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
 * SEIT 2.8.13 (Gruppe 35, Entscheidung 2): EINEN Tag zurueck merkt sich
 * dieser Lauf die Preise doch - die des laufenden Tages (preise_tag in
 * kosten.json), beim Datumswechsel nachgerueckt (preise_vortag). Damit
 * schliesst der erste Lauf nach 0 Uhr den Vortag samt der Stunde 23-24 Uhr
 * ab: eine Zeile je Tag in kosten_historie.csv und kosten/gestern_*.
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

/* Gruppe 35 (2.8.13, T4-6): EINE Wurzelsuche fuer alle Abholer - smg_wurzel() in sm_gemein.php
 * (LBHOMEDIR, sonst aufwaerts mit config/plugins, webfrontend UND config/system/general.json). Die
 * Schleife hier lieferte nach acht Stufen ohne Treffer den achten Elternordner statt ''. */
$sm_home = smg_wurzel(__DIR__);
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
// Gruppe 35 (2.8.13): Tagesabschluss (eine Zeile je Tag) und die Sperre auf der Ramdisk.
$SM_KHIST    = $SM_DATA . '/kosten_historie.csv';
$SM_SHM      = '/dev/shm/' . $sm_ordner;

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

/**
 * Themen ueber das UDP-Relais des Gateways senden (Durchgang 01.10.2026:
 * aus dem Hauptteil gezogen, damit auch der Ausfallzweig senden kann - A6).
 * Rueckgabe: true, wenn das Relais erreichbar war. Vorgabe SENDMQTT '1' wie
 * in sm_lib.php und fetch.php (M5; bis 2.8.5 stand hier '0').
 */
function sm_k_mqtt($cfg, $msgs)
{
    global $SM_GENERAL, $sm_verbose;
    if (smg_wert($cfg, 'MAIN', 'SENDMQTT', '1') !== '1') {
        return false;
    }
    $udp = 0;
    $gen = @json_decode((string) @file_get_contents($SM_GENERAL), true);
    if (isset($gen['Mqtt']['Udpinport'])) { $udp = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udp && isset($gen['mqtt']['udpinport'])) { $udp = (int) $gen['mqtt']['udpinport']; }
    $praefix = trim(smg_wert($cfg, 'MAIN', 'MQTTTOPIC', 'smartmeter'), '/');
    if ($udp <= 0 || $udp >= 65536) {
        return false;
    }
    $strom = @stream_socket_client('udp://127.0.0.1:' . $udp, $e1, $e2, 2);
    if (!$strom) {
        sm_k_log('Das UDP-Relais des Gateways auf Port ' . $udp . ' war nicht erreichbar.', 'WARN');
        return false;
    }
    foreach ($msgs as $k => $v) {
        @fwrite($strom, 'publish ' . $praefix . '/' . $k . ' ' . smg_wert_saeubern($v));
    }
    fclose($strom);
    if ($sm_verbose) { printf("%d Thema/Themen gesendet.\n", count($msgs)); }
    return true;
}

/* ==================================================================
 * Gruppe 35 (2.8.13, Entscheidung 2: T4-3 + Fremd-E3) - Tagesabschluss
 *
 * Bis 2.8.12 rechnete dieser Lauf nur "heute"; die Stunde 23-24 Uhr
 * entsteht in historie.csv erst nach 0 Uhr und fiel damit in keinen Tag,
 * und von 0 bis 1 Uhr stand stunde_ct auf -1 (Pruefbericht T4-3, Probe).
 * Jetzt merkt sich kosten.json die Preise des Tages (preise_tag) und rueckt
 * sie beim Datumswechsel nach preise_vortag, BEVOR neue eingetragen werden.
 * Der erste Lauf nach 0 Uhr, der die 23-Uhr-Zeile des Vortags in
 * historie.csv sieht (spaetestens an der Frist 01:00, dann ohne sie),
 * schliesst den Vortag ab: genau EINE Zeile je Tag in kosten_historie.csv.
 * Ohne Vortagspreise entsteht die Zeile mit -1 - kein erfundener Preis.
 * Preise nach Stundenschluessel 0-23: am 25-Stunden-Tag haben beide
 * 2-Uhr-Stunden denselben Preis (Bestand, N9).
 * ================================================================== */
define('SM_K_FRIST', 3600);
define('SM_K_KOPF', 'datum;kwh;ct;eur;stunden;offen;ohne_preis;stunde23;zeilen');

/** Preise merken: preise_tag nachruecken, bekannte Stunden halten. */
function sm_k_preise_merken(array $stand, array $preise, $jetzt)
{
    $heute = date('Y-m-d', $jetzt);
    $pt = (isset($stand['preise_tag']) && is_array($stand['preise_tag'])) ? $stand['preise_tag'] : null;
    if ($pt && isset($pt['datum']) && $pt['datum'] !== $heute) {
        // Nachruecken, nie verwerfen - die Preise von gestern braucht der Abschluss.
        $stand['preise_vortag'] = $pt;
        $pt = null;
    }
    if (!$pt || !isset($pt['ct']) || !is_array($pt['ct'])) {
        $pt = array('datum' => $heute, 'ct' => array());
    }
    foreach ($preise as $h => $ct) {
        $pt['ct'][(string) (int) $h] = (float) $ct;
    }
    $stand['preise_tag'] = $pt;
    return $stand;
}

/**
 * Den Vortag abschliessen (unter der Sperre dieses Laufs). Rueckgabe
 * array(Lage, Zeile|null); Lage: erledigt | wartet | keine_daten |
 * geschrieben | ohne_preise | nicht_schreibbar.
 */
function sm_k_tagesabschluss($hist, $khist, array $stand, $jetzt)
{
    $heute_beginn = strtotime('today', $jetzt);
    $gestern_beginn = strtotime('yesterday', $jetzt);
    $gestern = date('Y-m-d', $gestern_beginn);
    if (sm_k_gestern_zeile($khist, $jetzt) !== null) {
        return array('erledigt', null);
    }
    $wh = array();
    $offen = 0;
    $hat23 = false;
    $zeilen = 0;
    $s23 = strtotime($gestern . ' 23:00:00');
    $fh = is_readable($hist) ? @fopen($hist, 'rb') : false;
    if ($fh !== false) {
        while (($z = fgets($fh)) !== false) {
            $f = explode(';', trim($z));
            if (count($f) < 7 || !ctype_digit($f[0])) { continue; }
            $st = (int) $f[0];
            if ($st < $gestern_beginn || $st >= $heute_beginn) { continue; }
            $zeilen++;
            if ($st === $s23) { $hat23 = true; }
            if ($f[4] !== 'Wh') { $offen++; continue; }
            // Je Stundenbeginn (nicht je Uhrzeit): am 25-Stunden-Tag sind es zwei 2-Uhr-Stunden.
            $wh[$st] = (isset($wh[$st]) ? $wh[$st] : 0.0) + (float) $f[2];
        }
        fclose($fh);
    }
    if (!$hat23 && $jetzt < $heute_beginn + SM_K_FRIST) {
        return array('wartet', null);
    }
    if ($zeilen === 0) {
        // Kein Leser lief gestern - keine Zeile; gestern_* gehen als -1 hinaus.
        return array('keine_daten', null);
    }
    $pv = (isset($stand['preise_vortag']['datum'], $stand['preise_vortag']['ct'])
           && $stand['preise_vortag']['datum'] === $gestern && is_array($stand['preise_vortag']['ct']))
        ? $stand['preise_vortag']['ct'] : array();
    $ct = 0.0;
    $kwh = 0.0;
    $n = 0;
    $ohne = 0;
    foreach ($wh as $st => $m) {
        $h = (int) date('G', $st);
        if (!isset($pv[(string) $h])) { $ohne++; continue; }
        $ct += $m / 1000.0 * (float) $pv[(string) $h];
        $kwh += $m / 1000.0;
        $n++;
    }
    $zeile = implode(';', array($gestern, $n ? round($kwh, 3) : -1, $n ? round($ct, 3) : -1,
        $n ? round($ct / 100.0, 3) : -1, $n, $offen, $ohne, $hat23 ? 1 : 0, $zeilen));
    $neu = !is_file($khist) || (int) @filesize($khist) === 0;
    $fh = @fopen($khist, 'ab');
    if ($fh === false) {
        return array('nicht_schreibbar', $zeile);
    }
    $text = ($neu ? SM_K_KOPF . "\n" : '') . $zeile . "\n";
    $ok = (fwrite($fh, $text) === strlen($text));
    fclose($fh);
    if ($neu) { @chmod($khist, 0640); }
    if (!$ok) {
        return array('nicht_schreibbar', $zeile);
    }
    return array($n ? 'geschrieben' : 'ohne_preise', $zeile);
}

/** Die Zeile des Vortags aus kosten_historie.csv als Feld (Spaltenname => Wert) oder null. */
function sm_k_gestern_zeile($khist, $jetzt)
{
    if (!is_readable($khist)) { return null; }
    $gestern = date('Y-m-d', strtotime('yesterday', $jetzt));
    $kopf = explode(';', SM_K_KOPF);
    $fh = @fopen($khist, 'rb');
    if ($fh === false) { return null; }
    $treffer = null;
    while (($z = fgets($fh)) !== false) {
        $z = trim($z);
        if (strncmp($z, $gestern . ';', 11) !== 0) { continue; }
        $f = explode(';', $z);
        if (count($f) === count($kopf)) { $treffer = array_combine($kopf, $f); }
    }
    fclose($fh);
    return $treffer;
}

/**
 * kosten/gestern_* (Entwurf 2.4): NIE retained - Gestern-Werte werden allein
 * durch die Uhr falsch. Sie gehen in JEDEM Lauf hinaus, solange die Zeile von
 * gestern steht (ein verlorenes Datagramm heilt im naechsten Lauf, das
 * MQTT-Gateway V2 entdoppelt gleiche Werte); fehlt sie nach der Frist, -1.
 * Vor der Frist und ohne Zeile: nichts.
 */
function sm_k_gestern_msgs($khist, $jetzt)
{
    $g = sm_k_gestern_zeile($khist, $jetzt);
    if ($g !== null) {
        return array('kosten/gestern_eur' => $g['eur'], 'kosten/gestern_kwh' => $g['kwh'],
                     'kosten/gestern_ct' => $g['ct'], 'kosten/gestern_stunden' => (int) $g['stunden']);
    }
    if ($jetzt >= strtotime('today', $jetzt) + SM_K_FRIST) {
        return array('kosten/gestern_eur' => -1, 'kosten/gestern_kwh' => -1,
                     'kosten/gestern_ct' => -1, 'kosten/gestern_stunden' => 0);
    }
    return array();
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
    $sm_g = sm_k_gestern_zeile($SM_KHIST, time());
    printf("  %-34s %s\n", 'gestern (Tagesabschluss)', $sm_g === null ? '-'
           : ((float) $sm_g['eur'] < 0 ? 'ohne Preise (' . $sm_g['ohne_preis'] . ' Stunden)'
              : number_format((float) $sm_g['eur'], 2, ',', '.') . ' EUR fuer '
                . number_format((float) $sm_g['kwh'], 3, ',', '.') . ' kWh, ' . $sm_g['stunden'] . ' Stunden'));
    echo "\nGerechnet wird der HEUTIGE Tag; den Vortag schliesst der erste Lauf\n";
    echo "nach 0 Uhr ab (kosten_historie.csv) - mit den Preisen, die dieser Lauf\n";
    echo "gestern selbst gemerkt hat.\n";
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
/* Gruppe 35 (2.8.13, Entscheidung 2): ein Lauf zugleich - der Tagesabschluss ("steht der Vortag schon
 * da?" + Anhaengen) laeuft unter dieser Sperre. fopen 'ce', LOCK_NB; belegt = still enden. */
$sm_sperre = smg_sperre($SM_SHM, 'sm_kosten');
if ($sm_sperre === false) {
    sm_k_log('Ein anderer Kostenlauf haelt die Sperre - dieser endet ohne Wirkung.', 'INFO');
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
$sm_ts_vorher = (int) $sm_stand['ts'];
$sm_stand['ts'] = $sm_jetzt;

list($sm_preise, $sm_grund) = sm_k_preise($sm_url);
/* Gruppe 35 (2.8.13): Preise merken (nachruecken beim Datumswechsel), dann den Vortag abschliessen -
 * auch wenn die Quelle gerade schweigt: der Abschluss braucht nur preise_vortag und historie.csv. */
$sm_stand = sm_k_preise_merken($sm_stand, $sm_preise === null ? array() : $sm_preise, $sm_jetzt);
list($sm_ab_lage, $sm_ab_zeile) = sm_k_tagesabschluss($SM_HIST, $SM_KHIST, $sm_stand, $sm_jetzt);
if ($sm_ab_lage === 'geschrieben' || $sm_ab_lage === 'ohne_preise') {
    sm_k_log('Tagesabschluss: ' . $sm_ab_zeile . ($sm_ab_lage === 'ohne_preise'
        ? ' - ohne Preise des Vortags (Plugin war aus oder ein Update dazwischen), -1 statt eines erfundenen Preises.'
        : ''), 'INFO');
} elseif ($sm_ab_lage === 'nicht_schreibbar') {
    sm_k_log('Tagesabschluss: ' . $SM_KHIST . ' liess sich nicht schreiben - naechster Versuch im naechsten Lauf.', 'ERROR');
}
$sm_gestern = sm_k_gestern_msgs($SM_KHIST, $sm_jetzt);
if ($sm_preise === null) {
    /* Die alten Zahlen bleiben stehen. Sie auf 0 zu setzen waere eine
     * Aussage; "unbekannt" ist hier die richtige. */
    $sm_stand['quelle_ok'] = 0;
    $sm_stand['grund'] = $sm_grund;
    /* SEIT DEM DURCHGANG 01.10.2026 (A6, Entscheidung 8): quelle_ok 0 geht
     * hinaus - bis 2.8.5 endete dieser Zweig vor dem MQTT-Teil, und in
     * Loxone blieb quelle_ok 1 stehen (gemessen: 0 Datagramme, Codebericht
     * Nr. 5). Stammt der bisherige Stand von einem ANDEREN Tag, sind seine
     * Tageswerte nicht die von heute: sie gehen als -1 ("unbekannt") hinaus
     * und werden im Stand geleert. Am selben Tag bleiben sie stehen. */
    $sm_msgs = array('kosten/quelle_ok' => 0);
    if ($sm_ts_vorher <= 0 || date('Y-m-d', $sm_ts_vorher) !== date('Y-m-d', $sm_jetzt)) {
        $sm_stand['stunde_ct'] = null;
        $sm_stand['heute_ct'] = null;
        $sm_stand['heute_kwh'] = null;
        $sm_stand['stunden'] = 0;
        $sm_msgs['kosten/stunde_ct'] = -1;
        $sm_msgs['kosten/heute_ct'] = -1;
        $sm_msgs['kosten/heute_eur'] = -1;
        $sm_msgs['kosten/heute_kwh'] = -1;
        $sm_msgs['kosten/stunden'] = 0;
    }
    sm_k_log('Die Preisquelle ist nicht erreichbar (' . $sm_grund . ') - '
        . (count($sm_msgs) > 1 ? 'die Tageswerte eines frueheren Tages gelten nicht mehr.'
                               : 'der bisherige Stand bleibt stehen.'), 'WARN');
    sm_k_atomar($SM_STAND, json_encode($sm_stand), 0640);
    sm_k_mqtt($sm_cfg, $sm_msgs + $sm_gestern);
    exit(1);
}
$sm_stand['quelle_ok'] = 1;
$sm_stand['grund'] = '';

if (!is_readable($SM_HIST)) {
    $sm_stand['grund'] = 'KEINE_HISTORIE';
    sm_k_log('Es gibt noch keine Verbrauchshistorie - nichts zu rechnen.', 'INFO');
    sm_k_atomar($SM_STAND, json_encode($sm_stand), 0640);
    /* Gruppe 1 (2.8.11, Entwurf sm 2.3 c): die Preisquelle IST erreichbar - das geht auch ohne
     * Historie hinaus. Bis 2.8.10 endete dieser Zweig ohne MQTT, und in Loxone blieb ein altes
     * quelle_ok 0 stehen. Die Kostenzahlen bleiben, wie sie sind. */
    sm_k_mqtt($sm_cfg, array('kosten/quelle_ok' => 1) + $sm_gestern);
    exit(0);
}

/* Die Stunden des heutigen Tages aus der Historie. Mehrere Zaehlernummern
 * werden ADDIERT - wer zwei Zaehlpunkte hat, zahlt fuer beide. */
$sm_tagesbeginn = strtotime('today');
$sm_wh = array();          // Stunde 0..23 => Wh
$sm_offen = 0;
$sm_wh23 = 0.0;            // Gruppe 35: die Stunde 23-24 Uhr des Vortags (fuer stunde_ct um 0 Uhr)
$sm_hat23 = false;
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
    if ($sm_st === $sm_tagesbeginn - 3600 && $sm_f[4] === 'Wh') {
        $sm_wh23 += (float) $sm_f[2];
        $sm_hat23 = true;
    }
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
} elseif ($sm_letzte < 0 && $sm_hat23
          && isset($sm_stand['preise_vortag']['datum'], $sm_stand['preise_vortag']['ct']['23'])
          && $sm_stand['preise_vortag']['datum'] === date('Y-m-d', $sm_tagesbeginn - 3600)) {
    /* Gruppe 35 (2.8.13, T4-3): von 0 bis 1 Uhr ist die letzte volle Stunde 23-24 Uhr des Vortags -
     * bis 2.8.12 stand hier -1. Preis aus preise_vortag. */
    $sm_stunde_ct = round($sm_wh23 / 1000.0 * (float) $sm_stand['preise_vortag']['ct']['23'], 3);
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

/* ---- MQTT ueber das UDP-Relais des Gateways ----
 *
 * -1 heisst "unbekannt". Eine 0 waere eine Aussage: sie hiesse, es habe
 * nichts gekostet. Seit dem Durchgang 01.10.2026 ueber sm_k_mqtt() - denselben
 * Weg wie der Ausfallzweig oben. */
sm_k_mqtt($sm_cfg, array(
    'kosten/quelle_ok'  => (int) $sm_stand['quelle_ok'],
    'kosten/stunde_ct'  => $sm_stunde_ct === null ? -1 : $sm_stunde_ct,
    'kosten/heute_ct'   => $sm_stand['heute_ct'] === null ? -1 : $sm_stand['heute_ct'],
    'kosten/heute_eur'  => $sm_stand['heute_ct'] === null ? -1
                           : round($sm_stand['heute_ct'] / 100.0, 3),
    'kosten/heute_kwh'  => $sm_stand['heute_kwh'] === null ? -1 : $sm_stand['heute_kwh'],
    'kosten/stunden'    => $sm_gerechnet,
    'kosten/offen'      => $sm_offen,
    'kosten/ohne_preis' => $sm_ohne_preis,
) + $sm_gestern);

if ($sm_verbose) {
    printf("%d Stunde(n) gerechnet, %d ohne Einheit, %d ohne Preis.\n",
           $sm_gerechnet, $sm_offen, $sm_ohne_preis);
}
exit($sm_ok ? 0 : 1);
