#!/usr/bin/env php
<?php
/**
 * Smartmeter classic - was der Strom gekostet hat
 *
 * WOZU
 *
 * Bei einem dynamischen Tarif hat jede Stunde einen eigenen Preis. Was ein
 * Tag gekostet hat, ist deshalb nicht Verbrauch mal Durchschnittspreis,
 * sondern die Summe ueber die Stunden - und genau das kann der Miniserver
 * nicht: er hat kein Gedaechtnis fuer 24 Stundenwerte.
 *
 * Beide Haelften liegen seit 2.7.2 vor:
 *   die MENGE je Stunde  aus data/plugins/<ordner>/historie.csv (2.6.0)
 *   der PREIS je Stunde  vom oertlichen Spotpreis-Plugin
 *
 * DER PREIS IST DER ENDPREIS, NICHT DER BOERSENPREIS
 *
 * Genommen wird 'ct' aus dem Zustand des Spotpreis-Plugins - dort sind
 * Netzentgelt, Stromsteuer, Konzessionsabgabe, Umlagen, Aufschlag und
 * Umsatzsteuer schon drin. 'boerse' waere die halbe Wahrheit: aus 8,00 ct
 * Boerse werden dort 26,01 ct Endpreis. Wer mit dem Boersenpreis rechnet,
 * bekommt eine Zahl, die um den Faktor drei danebenliegt und trotzdem
 * plausibel aussieht.
 *
 * Gepflegt wird der Endpreis DORT. Hier wird er nur geholt - eine zweite
 * Stelle fuer Netzentgelte und Steuersaetze laeuft aus dem Takt.
 *
 * WAS ER NICHT KANN, UND WARUM
 *
 * Nur HEUTE. Das Spotpreis-Plugin liefert die Stundenpreise fuer heute und
 * morgen; eine Preishistorie ueber zurueckliegende Tage in STUNDEN gibt es
 * dort nicht (seine history.csv fuehrt Tageswerte). Was der Dienstag
 * gekostet hat, laesst sich damit nicht nachrechnen - und eine Zahl, die
 * mit dem Tagesmittel gerechnet waere, hiesse "Kosten" und waere keine.
 *
 * Wer es braucht, muesste die Stundenpreise mitschreiben. Das gehoert dann
 * ins Spotpreis-Plugin, nicht hierher: dort entstehen sie.
 *
 * DIE EINHEIT
 *
 * historie.csv fuehrt Wh und schreibt die Einheit daneben; Stunden mit "?"
 * werden UEBERGANGEN und gezaehlt. Seit 2.8.0 ist einheit_vz fuer die
 * Zaehlwerke belegt (am Zaehler gemessen), auf einem anderen Zaehler kann
 * sie es wieder nicht sein. Eine Kostenzahl aus einer geratenen Einheit
 * waere um den Faktor 1000 daneben.
 *
 * Aufrufe von Hand:
 *     sm_kosten.php --verbose   ein Durchlauf mit Bildschirmausgabe
 *     sm_kosten.php --zeigen    den Stand ausgeben, nichts aendern
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$sm_verbose = false;
$sm_zeigen  = false;
foreach ($argv as $sm_i => $sm_a) {
    if ($sm_i === 0) { continue; }
    if ($sm_a === '--verbose' || $sm_a === '-v') { $sm_verbose = true; continue; }
    if ($sm_a === '--zeigen') { $sm_zeigen = true; continue; }
    fwrite(STDERR, "Unbekannter Schalter: " . $sm_a . "\n");
    exit(2);
}

$sm_ordner = basename(dirname(__FILE__));
if ($sm_ordner === '' || $sm_ordner === 'bin') { $sm_ordner = 'smartmeter-classic'; }
$sm_gemein = __DIR__ . '/sm_gemein.php';
if (!is_file($sm_gemein)) {
    fwrite(STDERR, "sm_gemein.php fehlt neben " . __FILE__ . "\n");
    exit(1);
}
require_once $sm_gemein;

$sm_home = getenv('LBHOMEDIR');
if (!$sm_home || !is_dir($sm_home)) {
    $sm_d = __DIR__;
    for ($sm_i = 0; $sm_i < 8; $sm_i++) {
        if (is_dir($sm_d . '/config/plugins') && is_dir($sm_d . '/webfrontend')) { break; }
        $sm_e = dirname($sm_d);
        if ($sm_e === $sm_d) { $sm_d = ''; break; }
        $sm_d = $sm_e;
    }
    $sm_home = $sm_d;
}
if ($sm_home === '' || !is_dir($sm_home)) {
    fwrite(STDERR, "Der LoxBerry-Wurzelordner liess sich nicht bestimmen.\n");
    exit(1);
}

$SM_DATA     = $sm_home . '/data/plugins/' . $sm_ordner;
$SM_CFGDATEI = $sm_home . '/config/plugins/' . $sm_ordner . '/smartmeter.cfg';
$SM_HIST     = $SM_DATA . '/historie.csv';
$SM_STAND    = $SM_DATA . '/kosten.json';
$SM_GENERAL  = $sm_home . '/config/system/general.json';
$SM_LOG      = $sm_home . '/log/plugins/' . $sm_ordner . '/smartmeter.log';

function sm_k_log($text, $stufe = 'INFO')
{
    global $SM_LOG, $sm_verbose;
    @mkdir(dirname($SM_LOG), 0775, true);
    $zeile = date('Y-m-d H:i:s') . ' [' . $stufe . '] [kosten] ' . $text;
    @file_put_contents($SM_LOG, $zeile . "\n", FILE_APPEND);
    if (function_exists('smg_log_kappen')) { smg_log_kappen($SM_LOG); }
    if ($sm_verbose) { echo $zeile . "\n"; }
}

/** Schreiben ueber temp + rename. */
function sm_k_atomar($pfad, $inhalt, $rechte = null)
{
    @mkdir(dirname($pfad), 0775, true);
    $tmp = $pfad . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    if ($rechte !== null && !@chmod($tmp, $rechte)) { @unlink($tmp); return false; }
    $ok = ftruncate($fh, 0);
    $ok = $ok && (fwrite($fh, $inhalt) === strlen($inhalt));
    fflush($fh);
    if (!fclose($fh)) { @unlink($tmp); return false; }
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

function sm_k_stand_lesen()
{
    global $SM_STAND;
    $leer = array('ts' => 0, 'quelle_ok' => 0, 'grund' => '',
                  'stunde_ct' => null, 'heute_ct' => null, 'heute_kwh' => null,
                  'stunden' => 0, 'offen' => 0, 'ohne_preis' => 0);
    if (!is_readable($SM_STAND)) { return $leer; }
    $d = json_decode((string) @file_get_contents($SM_STAND), true);
    return is_array($d) ? array_merge($leer, $d) : $leer;
}

/**
 * Die Stundenpreise von heute holen - als array(Stunde 0..23 => ct/kWh).
 *
 * Genommen wird der ENDPREIS ('ct'), nicht 'boerse'.
 */
function sm_k_preise($url)
{
    $url = trim((string) $url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return array(null, 'KEINE_ADRESSE');
    }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET', 'timeout' => 8, 'ignore_errors' => true,
        'user_agent' => 'LoxBerry Smartmeter classic')));
    $roh = @file_get_contents($url, false, $ctx);
    if ($roh === false || $roh === '') { return array(null, 'KEINE_ANTWORT'); }
    $d = json_decode($roh, true);
    if (!is_array($d)) { return array(null, 'KEIN_JSON'); }
    if (!isset($d['heute']['hours']) || !is_array($d['heute']['hours'])) {
        return array(null, 'KEINE_PREISE');
    }
    $p = array();
    foreach ($d['heute']['hours'] as $h => $row) {
        if (!is_array($row) || !isset($row['ct']) || !is_numeric($row['ct'])) { continue; }
        $p[(int) $h] = (float) $row['ct'];
    }
    if (!$p) { return array(null, 'KEINE_PREISE'); }
    return array($p, '');
}

/* ==================================================================
 * --zeigen
 * ================================================================== */
if ($sm_zeigen) {
    $st = sm_k_stand_lesen();
    if (!$st['ts']) {
        echo "Es gibt noch keine Kostenrechnung (" . $SM_STAND . ").\n";
        exit(0);
    }
    printf("Stand von %s, Preisquelle: %s\n\n", date('Y-m-d H:i:s', (int) $st['ts']),
           $st['quelle_ok'] ? 'erreichbar' : ('NICHT erreichbar (' . $st['grund'] . ')'));
    printf("  %-34s %s\n", 'letzte volle Stunde',
           $st['stunde_ct'] === null ? '-' : number_format($st['stunde_ct'], 2, ',', '.') . ' ct');
    printf("  %-34s %s\n", 'heute bisher',
           $st['heute_ct'] === null ? '-' : number_format($st['heute_ct'] / 100.0, 2, ',', '.') . ' EUR');
    printf("  %-34s %s\n", 'heute bisher verbraucht',
           $st['heute_kwh'] === null ? '-' : number_format($st['heute_kwh'], 3, ',', '.') . ' kWh');
    printf("  %-34s %d\n", 'gerechnete Stunden', (int) $st['stunden']);
    printf("  %-34s %d\n", 'Stunden ohne belegte Einheit', (int) $st['offen']);
    printf("  %-34s %d\n", 'Stunden ohne Preis', (int) $st['ohne_preis']);
    echo "\nGerechnet wird nur der HEUTIGE Tag: Stundenpreise fuer\n";
    echo "zurueckliegende Tage fuehrt das Spotpreis-Plugin nicht.\n";
    exit(0);
}

/* ==================================================================
 * Der Durchlauf
 * ================================================================== */
$sm_cfg = smg_cfg_lesen($SM_CFGDATEI);
if (smg_wert($sm_cfg, 'KOSTEN', 'AKTIV', '0') !== '1') {
    if ($sm_verbose) { echo "Die Kostenrechnung ist ausgeschaltet (KOSTEN.AKTIV).\n"; }
    exit(0);
}
/* Die Adresse. Leer heisst: die des Fahrplan-Abgleichs nehmen - es ist
 * derselbe Endpunkt desselben Plugins, und zwei Felder fuer eine Adresse
 * laufen auseinander. */
$sm_url = trim(smg_wert($sm_cfg, 'KOSTEN', 'PREIS_URL', ''));
if ($sm_url === '') {
    $sm_url = trim(smg_wert($sm_cfg, 'ABGLEICH', 'FAHRPLAN_URL', ''));
}

$sm_stand = sm_k_stand_lesen();
$sm_jetzt = time();
$sm_stand['ts'] = $sm_jetzt;

list($sm_preise, $sm_grund) = sm_k_preise($sm_url);
if ($sm_preise === null) {
    /* Die alten Zahlen bleiben stehen. Sie auf 0 zu setzen waere eine
     * Aussage; "unbekannt" ist hier die richtige. */
    $sm_stand['quelle_ok'] = 0;
    $sm_stand['grund'] = $sm_grund;
    sm_k_log('Die Preisquelle ist nicht erreichbar (' . $sm_grund . ') - der '
        . 'bisherige Stand bleibt stehen.', 'WARN');
    sm_k_atomar($SM_STAND, json_encode($sm_stand), 0640);
    exit(1);
}
$sm_stand['quelle_ok'] = 1;
$sm_stand['grund'] = '';

if (!is_readable($SM_HIST)) {
    $sm_stand['grund'] = 'KEINE_HISTORIE';
    sm_k_log('Es gibt noch keine Verbrauchshistorie - nichts zu rechnen.', 'INFO');
    sm_k_atomar($SM_STAND, json_encode($sm_stand), 0640);
    exit(0);
}

/* Die Stunden des heutigen Tages aus der Historie. Mehrere Zaehlernummern
 * werden ADDIERT - wer zwei Zaehlpunkte hat, zahlt fuer beide. */
$sm_tagesbeginn = strtotime('today');
$sm_wh = array();          // Stunde 0..23 => Wh
$sm_offen = 0;
clearstatcache(true, $SM_HIST);
$sm_fh = @fopen($SM_HIST, 'rb');
if ($sm_fh === false) {
    sm_k_log('Die Historie ' . $SM_HIST . ' liess sich nicht lesen.', 'ERROR');
    exit(1);
}
while (($sm_z = fgets($sm_fh)) !== false) {
    $sm_z = trim($sm_z);
    if ($sm_z === '' || strncmp($sm_z, 'stunde;', 7) === 0) { continue; }
    $sm_f = explode(';', $sm_z);
    if (count($sm_f) < 7) { continue; }
    $sm_st = (int) $sm_f[0];
    if ($sm_st < $sm_tagesbeginn || $sm_st >= $sm_tagesbeginn + 86400) { continue; }
    // "?" heisst gemessen, aber nicht umgerechnet - daraus wird kein Geld.
    if ($sm_f[4] !== 'Wh') { $sm_offen++; continue; }
    $sm_h = (int) date('G', $sm_st);
    $sm_wh[$sm_h] = (isset($sm_wh[$sm_h]) ? $sm_wh[$sm_h] : 0.0) + (float) $sm_f[2];
}
fclose($sm_fh);

$sm_summe_ct = 0.0;
$sm_summe_kwh = 0.0;
$sm_gerechnet = 0;
$sm_ohne_preis = 0;
foreach ($sm_wh as $sm_h => $sm_menge) {
    if (!isset($sm_preise[$sm_h])) {
        /* Der Preis dieser Stunde fehlt in der Antwort. Mit dem
         * Tagesmittel zu rechnen waere genau die Naeherung, die ein
         * dynamischer Tarif abschafft. */
        $sm_ohne_preis++;
        continue;
    }
    $sm_kwh = $sm_menge / 1000.0;
    $sm_summe_ct += $sm_kwh * $sm_preise[$sm_h];
    $sm_summe_kwh += $sm_kwh;
    $sm_gerechnet++;
}

/* Die letzte VOLLE Stunde - die laufende ist noch nicht zu Ende und stuende
 * sonst als zu kleiner Wert da. */
$sm_letzte = (int) date('G', $sm_jetzt) - 1;
$sm_stunde_ct = null;
if ($sm_letzte >= 0 && isset($sm_wh[$sm_letzte], $sm_preise[$sm_letzte])) {
    $sm_stunde_ct = round($sm_wh[$sm_letzte] / 1000.0 * $sm_preise[$sm_letzte], 3);
}

$sm_stand['stunde_ct'] = $sm_stunde_ct;
$sm_stand['heute_ct']  = $sm_gerechnet > 0 ? round($sm_summe_ct, 3) : null;
$sm_stand['heute_kwh'] = $sm_gerechnet > 0 ? round($sm_summe_kwh, 3) : null;
$sm_stand['stunden']   = $sm_gerechnet;
$sm_stand['offen']     = $sm_offen;
$sm_stand['ohne_preis'] = $sm_ohne_preis;

$sm_ok = true;
if (!sm_k_atomar($SM_STAND, json_encode($sm_stand), 0640)) {
    sm_k_log('Der Stand ' . $SM_STAND . ' liess sich nicht schreiben.', 'ERROR');
    $sm_ok = false;
}

/* ---- MQTT ueber das UDP-Relais des Gateways ---- */
if (smg_wert($sm_cfg, 'MAIN', 'SENDMQTT', '0') === '1') {
    $sm_udp = 0;
    $sm_gen = @json_decode((string) @file_get_contents($SM_GENERAL), true);
    if (isset($sm_gen['Mqtt']['Udpinport'])) { $sm_udp = (int) $sm_gen['Mqtt']['Udpinport']; }
    if (!$sm_udp && isset($sm_gen['mqtt']['udpinport'])) { $sm_udp = (int) $sm_gen['mqtt']['udpinport']; }
    $sm_praefix = trim(smg_wert($sm_cfg, 'MAIN', 'MQTTTOPIC', 'smartmeter'), '/');
    if ($sm_udp > 0 && $sm_udp < 65536) {
        /* -1 heisst "unbekannt". Eine 0 waere eine Aussage: sie hiesse, es
         * habe nichts gekostet. */
        $sm_msgs = array(
            'kosten/quelle_ok'  => (int) $sm_stand['quelle_ok'],
            'kosten/stunde_ct'  => $sm_stunde_ct === null ? -1 : $sm_stunde_ct,
            'kosten/heute_ct'   => $sm_stand['heute_ct'] === null ? -1 : $sm_stand['heute_ct'],
            'kosten/heute_eur'  => $sm_stand['heute_ct'] === null ? -1
                                   : round($sm_stand['heute_ct'] / 100.0, 3),
            'kosten/heute_kwh'  => $sm_stand['heute_kwh'] === null ? -1 : $sm_stand['heute_kwh'],
            'kosten/stunden'    => $sm_gerechnet,
            'kosten/offen'      => $sm_offen,
            'kosten/ohne_preis' => $sm_ohne_preis,
        );
        $sm_strom = @stream_socket_client('udp://127.0.0.1:' . $sm_udp, $sm_e1, $sm_e2, 2);
        if ($sm_strom) {
            foreach ($sm_msgs as $sm_k => $sm_v) {
                @fwrite($sm_strom, 'publish ' . $sm_praefix . '/' . $sm_k . ' '
                        . smg_wert_saeubern($sm_v));
            }
            fclose($sm_strom);
            if ($sm_verbose) { printf("%d Thema/Themen gesendet.\n", count($sm_msgs)); }
        } else {
            sm_k_log('Das UDP-Relais des Gateways auf Port ' . $sm_udp
                . ' war nicht erreichbar.', 'WARN');
        }
    }
}

if ($sm_verbose) {
    printf("%d Stunde(n) gerechnet, %d ohne Einheit, %d ohne Preis.\n",
           $sm_gerechnet, $sm_offen, $sm_ohne_preis);
}
exit($sm_ok ? 0 : 1);
