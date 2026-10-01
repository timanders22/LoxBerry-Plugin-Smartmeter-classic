#!/usr/bin/env php
<?php
/**
 * Smartmeter classic - Fahrplan-Abgleich
 *
 * DIE FRAGE, DIE ER BEANTWORTET
 *
 * "Tut die Hardware, was der Fahrplan sagt?" Der Planer der Spotpreis-Plugins
 * schaltet Regeln (Wallbox, Speicher, Waermepumpe) in die guenstigen Stunden.
 * Ob das Geraet dann wirklich zieht, weiss er nicht - er sendet einen Befehl
 * und sieht keinen Zaehler. Dieses Plugin sieht den Zaehler.
 *
 * WARUM ES KEIN "DELTA" IST
 *
 * Die naheliegende Rechnung waere "geplanter minus realer Netzbezug". Sie
 * geht nicht: einen geplanten Netzbezug gibt es nicht. Der Planer plant
 * SCHALTFENSTER fuer einzelne Regeln - "Wallbox 11 kW soll jetzt laufen" -,
 * gemessen wird aber der Bezug des GANZEN Hauses. Die Differenz waere
 * Grundlast plus Herd plus alles andere, nicht die Abweichung der Wallbox.
 *
 * Deshalb rechnet dieses Stueck eine UNTERE SCHRANKE, und die ist ein
 * Beweis statt einer Schaetzung:
 *
 *     Laeuft eine Regel mit 11 kW 47 Minuten lang, muss sie 8,6 kWh
 *     gezogen haben. Der Netzbezug in dieser Zeit kann nur GROESSER
 *     sein - der Rest des Hauses kommt dazu, zieht nie ab. Sind es
 *     2,1 kWh, hat die Regel nachweislich nicht gezogen.
 *
 * Die Richtung ist entscheidend: "zu wenig Bezug" ist ein Befund, "zu viel"
 * ist keiner. Wer daraus eine Abweichung in beide Richtungen macht, misst
 * den Haushalt und nennt es Regelabweichung.
 *
 * DIE EINE AUSNAHME, UND SIE WIRD GENANNT
 *
 * Eigene Einspeisung drueckt den Netzbezug, ohne dass die Regel weniger
 * zieht. Lief in der Zeit eine Einspeisung, ist die Schranke keine mehr -
 * die Aussage wird dann als UNSICHER gekennzeichnet und nicht als Befund
 * gemeldet. Ein Alarm, der bei jeder Wolke anschlaegt, wird abgeschaltet.
 *
 * WOHER DER FAHRPLAN KOMMT
 *
 * Ueber HTTP von spot.php?json=1 des oertlichen Spotpreis-Plugins -
 * derselbe Weg, den auch der Preis nimmt. KEIN MQTT-Abo: das braeuchte
 * einen Dauerprozess, eine neue Abhaengigkeit und einen Neustart-Waechter,
 * und es brachte nichts, solange der Zaehler ohnehin nur minutenweise
 * gelesen wird.
 *
 * WAS ER NICHT IST
 *
 * Er ist keine Regelung und kein schneller Weg. Wer sekundenschnell
 * nachregeln will, tut das im Miniserver: dort liegen der Netzbezug (aus
 * diesem Plugin) und regel/<n>/aktiv (aus dem Planer) ohnehin beide an,
 * und ein Baustein rechnet sie in einem Zyklus. Der Weg ueber dieses
 * Skript ist zwei MQTT-Sprünge und einen Cron-Lauf LANGSAMER. Was es
 * dafuer kann und der Miniserver nicht: ueber eine Laufzeit hinweg
 * Energie summieren.
 *
 * Aufrufe von Hand:
 *     sm_abgleich.php --verbose   ein Durchlauf mit Bildschirmausgabe
 *     sm_abgleich.php --zeigen    den Stand ausgeben, nichts aendern
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

$SM_SHM     = '/dev/shm/' . $sm_ordner;
$SM_DATA    = $sm_home . '/data/plugins/' . $sm_ordner;
$SM_CFGDATEI = $sm_home . '/config/plugins/' . $sm_ordner . '/smartmeter.cfg';
$SM_VZJSON  = $sm_home . '/config/plugins/' . $sm_ordner . '/vzlogger.json';
$SM_FELDER  = __DIR__ . '/sm_felder.json';
$SM_STAND   = $SM_DATA . '/abgleich.json';
$SM_GENERAL = $sm_home . '/config/system/general.json';
$SM_LOG     = $sm_home . '/log/plugins/' . $sm_ordner . '/smartmeter.log';

/* Ab welchem Anteil des Solls gilt eine Regel als "zieht". 0.80 laesst
 * Messrauschen, Anlaufverhalten und eine ungenau eingetragene Leistung
 * durch; darunter ist der Fehlbetrag zu gross, um davon zu kommen. */
define('SM_AB_TOLERANZ', 0.80);
/* Erst ab dieser Laufzeit wird geurteilt. Eine Regel, die zwei Minuten
 * lief, hat ein Soll von wenigen hundert Wattstunden - da entscheidet das
 * Messraster, nicht die Hardware. */
define('SM_AB_MINDAUER', 600);

function sm_ab_log($text, $stufe = 'INFO')
{
    global $SM_LOG, $sm_verbose;
    @mkdir(dirname($SM_LOG), 0775, true);
    $zeile = date('Y-m-d H:i:s') . ' [' . $stufe . '] [abgleich] ' . $text;
    @file_put_contents($SM_LOG, $zeile . "\n", FILE_APPEND);
    if (function_exists('smg_log_kappen')) { smg_log_kappen($SM_LOG); }
    if ($sm_verbose) { echo $zeile . "\n"; }
}

/** Schreiben ueber temp + rename. */
function sm_ab_atomar($pfad, $inhalt, $rechte = null)
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

function sm_ab_stand_lesen()
{
    global $SM_STAND;
    $leer = array('regeln' => array(), 'ts' => 0, 'quelle_ok' => 0, 'grund' => '');
    if (!is_readable($SM_STAND)) { return $leer; }
    $d = json_decode((string) @file_get_contents($SM_STAND), true);
    if (!is_array($d)) { return $leer; }
    return array_merge($leer, $d);
}

/**
 * Zeilen array(verb, thema, wert) ueber das UDP-Relais des Gateways senden
 * (Durchgang 01.10.2026: aus dem Hauptteil gezogen, damit auch der
 * Ausfallzweig senden kann - A6). Rueckgabe: true, wenn das Relais
 * erreichbar war und jede Zeile ganz geschrieben wurde - ob das Gateway sie
 * annahm, sagt das nicht (Regeln/07). Vorgabe SENDMQTT '1' wie in sm_lib.php
 * und fetch.php (M5; bis 2.8.5 stand hier '0'). Leere Werte gehen nie hinaus.
 */
function sm_ab_mqtt($cfg, $zeilen)
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
        sm_ab_log('Das UDP-Relais des Gateways auf Port ' . $udp . ' war nicht erreichbar.', 'WARN');
        return false;
    }
    $alle = true;
    foreach ($zeilen as $z) {
        $w = smg_wert_saeubern($z[2]);
        if ($w === '') { continue; }
        $text = $z[0] . ' ' . $praefix . '/' . $z[1] . ' ' . $w;
        if (@fwrite($strom, $text) !== strlen($text)) { $alle = false; }
    }
    fclose($strom);
    if ($sm_verbose) { printf("%d Thema/Themen gesendet.\n", count($zeilen)); }
    return $alle;
}

/** Die aktuellen Zaehlerstaende - Bezug und Einspeisung, summiert. */
function sm_ab_zaehlerstaende($vz_an)
{
    global $SM_SHM, $SM_FELDER;
    static $kat = null;
    if ($kat === null) {
        $kat = function_exists('smg_katalog') ? smg_katalog($SM_FELDER)
                                              : array('felder' => array());
    }
    $felder = array('bezug' => 'Consumption_Total_OBIS_1.8.0',
                    'einspeisung' => 'Delivery_Total_OBIS_2.8.0');
    $erg = array('bezug' => null, 'einspeisung' => null, 'ts' => 0, 'einheit' => '');
    if (!is_dir($SM_SHM)) { return $erg; }
    $dateien = glob($SM_SHM . '/*.data');
    if ($dateien === false) { $dateien = array(); }
    sort($dateien);
    $summe = array('bezug' => 0.0, 'einspeisung' => 0.0);
    $hat = array('bezug' => false, 'einspeisung' => false);
    foreach ($dateien as $datei) {
        $roh = @file_get_contents($datei);
        if ($roh === false || $roh === '') { continue; }
        $werte = array();
        foreach (preg_split('/\R/', $roh) as $z) {
            $z = trim($z);
            if ($z === '') { continue; }
            $teile = explode(':', $z, 3);
            if (count($teile) !== 3) { continue; }
            $werte[$teile[1]] = $teile[2];
        }
        // Ohne Messzeitpunkt ist der Stand nicht datierbar.
        if (!isset($werte['Last_UpdateUnix'])
            || !preg_match('/^[0-9]+$/', trim($werte['Last_UpdateUnix']))) {
            continue;
        }
        $ts = (int) $werte['Last_UpdateUnix'];
        if ($ts > $erg['ts']) { $erg['ts'] = $ts; }
        foreach ($felder as $art => $feld) {
            if (!isset($werte[$feld]) || !is_numeric(trim($werte[$feld]))) { continue; }
            // Die Einheit: klassisch belegt, auf dem vzLogger-Weg offen.
            $md = isset($kat['felder'][$feld]) ? $kat['felder'][$feld] : null;
            $eh = '';
            if ($md) {
                $eh = $vz_an ? (isset($md['einheit_vz']) ? (string) $md['einheit_vz'] : '')
                             : (isset($md['einheit']) ? (string) $md['einheit'] : '');
            }
            if ($eh === 'kWh') { $summe[$art] += (float) trim($werte[$feld]); }
            elseif ($eh === 'Wh') { $summe[$art] += (float) trim($werte[$feld]) / 1000.0; }
            else { $erg['einheit'] = '?'; continue; }
            $hat[$art] = true;
            if ($erg['einheit'] === '') { $erg['einheit'] = 'kWh'; }
        }
    }
    if ($hat['bezug']) { $erg['bezug'] = $summe['bezug']; }
    if ($hat['einspeisung']) { $erg['einspeisung'] = $summe['einspeisung']; }
    return $erg;
}

/** Den Fahrplan holen. Rueckgabe: array(regeln, grund). */
function sm_ab_fahrplan($url)
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
    if (!isset($d['regeln']) || !is_array($d['regeln'])) {
        // Der Endpunkt antwortet, kennt aber keine Regeln. Das ist kein
        // Fehler dieses Plugins - moeglicherweise ist der Planer aus.
        return array(array(), 'KEINE_REGELN');
    }
    return array($d['regeln'], '');
}

/* ==================================================================
 * --zeigen
 * ================================================================== */
if ($sm_zeigen) {
    $st = sm_ab_stand_lesen();
    if (!$st['ts']) {
        echo "Es gibt noch keinen Abgleich (" . $SM_STAND . ").\n";
        exit(0);
    }
    printf("Stand von %s, Fahrplanquelle: %s\n\n",
           date('Y-m-d H:i:s', (int) $st['ts']),
           $st['quelle_ok'] ? 'erreichbar' : ('NICHT erreichbar (' . $st['grund'] . ')'));
    if (!$st['regeln']) { echo "Keine Regel beobachtet.\n"; exit(0); }
    printf("%-4s %-9s %8s %10s %10s %10s  %s\n",
           'Nr', 'Zustand', 'Dauer', 'Soll kWh', 'Ist kWh', 'Fehlt kWh', 'Urteil');
    foreach ($st['regeln'] as $nr => $r) {
        printf("%-4s %-9s %8s %10s %10s %10s  %s\n",
               $nr, !empty($r['aktiv']) ? 'aktiv' : 'aus',
               isset($r['dauer']) ? (int) $r['dauer'] . ' s' : '-',
               isset($r['soll']) ? number_format($r['soll'], 3, ',', '') : '-',
               isset($r['ist']) ? number_format($r['ist'], 3, ',', '') : '-',
               isset($r['fehlt']) ? number_format($r['fehlt'], 3, ',', '') : '-',
               isset($r['urteil']) ? $r['urteil'] : '-');
    }
    echo "\n\"unsicher\" heisst: in der Zeit lief eine Einspeisung, oder die\n";
    echo "Einheit des Zaehlers ist nicht belegt. Dann ist die untere Schranke\n";
    echo "keine, und es wird NICHT geurteilt.\n";
    exit(0);
}

/* ==================================================================
 * Der Durchlauf
 * ================================================================== */
$sm_cfg = smg_cfg_lesen($SM_CFGDATEI);
$sm_an  = smg_wert($sm_cfg, 'ABGLEICH', 'AKTIV', '0') === '1';
$sm_url = smg_wert($sm_cfg, 'ABGLEICH', 'FAHRPLAN_URL', '');

if (!$sm_an) {
    if ($sm_verbose) { echo "Der Fahrplan-Abgleich ist ausgeschaltet (ABGLEICH.AKTIV).\n"; }
    exit(0);
}

$sm_vz_an = false;
if (is_readable($SM_VZJSON)) {
    $sm_vzd = json_decode((string) @file_get_contents($SM_VZJSON), true);
    $sm_vz_an = is_array($sm_vzd) && !empty($sm_vzd['enabled']);
}

$sm_stand = sm_ab_stand_lesen();
$sm_jetzt = time();
/* M4 (Durchgang 01.10.2026): die Regeln des vorigen Laufs und die noch nicht
 * gemeldeten entfernten - fuer den einmaligen "-"-Versand weiter unten. */
$sm_alt_regeln = is_array($sm_stand['regeln']) ? $sm_stand['regeln'] : array();
$sm_entfernt_offen = (isset($sm_stand['entfernt_offen']) && is_array($sm_stand['entfernt_offen']))
    ? array_values(array_map('strval', $sm_stand['entfernt_offen'])) : array();
list($sm_regeln, $sm_grund) = sm_ab_fahrplan($sm_url);
$sm_zaehler = sm_ab_zaehlerstaende($sm_vz_an);

if ($sm_regeln === null) {
    /* Der Fahrplan fehlt. Der bisherige Stand bleibt stehen - er wird NICHT
     * geleert: eine laufende Beobachtung geht sonst bei jeder Stoerung der
     * Verbindung verloren, und in Loxone stuende eine 0, wo "unbekannt"
     * richtig waere. */
    $sm_stand['quelle_ok'] = 0;
    $sm_stand['grund'] = $sm_grund;
    $sm_stand['ts'] = $sm_jetzt;
    sm_ab_log('Der Fahrplan ist nicht erreichbar (' . $sm_grund . ') - der '
        . 'bisherige Stand bleibt stehen.', 'WARN');
    sm_ab_atomar($SM_STAND, json_encode($sm_stand), 0640);
    /* SEIT DEM DURCHGANG 01.10.2026 (A6, Entscheidung 8): quelle_ok 0 geht
     * hinaus. Bis 2.8.5 endete dieser Zweig vor dem MQTT-Teil - in Loxone
     * blieb quelle_ok 1 stehen (gemessen: 0 Datagramme, Codebericht Nr. 5).
     * Die retained Zustaende abgleich/<n>/aktiv bleiben, wie sie sind. */
    sm_ab_mqtt($sm_cfg, array(array('publish', 'abgleich/quelle_ok', 0)));
    exit(1);
}
$sm_stand['quelle_ok'] = 1;
$sm_stand['grund'] = $sm_grund;
$sm_stand['ts'] = $sm_jetzt;

$sm_neu = array();
foreach ($sm_regeln as $sm_r) {
    if (!is_array($sm_r) || !isset($sm_r['nr'])) { continue; }
    $sm_nr = (string) (int) $sm_r['nr'];
    $sm_aktiv = !empty($sm_r['aktiv']);
    $sm_leistung = isset($sm_r['leistung']) ? (float) $sm_r['leistung'] : 0.0;

    $sm_vor = isset($sm_stand['regeln'][$sm_nr]) ? $sm_stand['regeln'][$sm_nr] : array();
    $sm_e = array('aktiv' => $sm_aktiv ? 1 : 0, 'leistung' => $sm_leistung);

    if ($sm_leistung <= 0) {
        /* Ohne eingetragene Leistung gibt es kein Soll. Das ist kein
         * Fehler - eine Regel darf am Leistungsbudget nicht teilnehmen -,
         * aber dann wird auch nicht geurteilt. */
        $sm_e['urteil'] = 'ohne_leistung';
        $sm_neu[$sm_nr] = $sm_e;
        continue;
    }

    if ($sm_aktiv) {
        /* Beginnt die Regel gerade - ODER lief sie schon, ohne dass je ein
         * Startpunkt zustande kam? Der zweite Fall ist der wichtige: liefert
         * der Zaehler im Augenblick des Einschaltens nichts (kein Lauf,
         * Einheit noch offen), gaebe es sonst fuer die GANZE Laufzeit kein
         * Urteil mehr - die Regel bliebe fuer immer auf "kein_start"
         * stehen. Gemessen am 04.09.2026 am Pruefstand. */
        if (empty($sm_vor['aktiv']) || !isset($sm_vor['start_ts'], $sm_vor['start_bezug'])) {
            if ($sm_zaehler['bezug'] === null) {
                // Ohne Zaehlerstand kein Startpunkt - und keine erfundene 0.
                $sm_e['urteil'] = 'kein_zaehler';
                $sm_neu[$sm_nr] = $sm_e;
                continue;
            }
            $sm_e['start_ts'] = $sm_zaehler['ts'] ? $sm_zaehler['ts'] : $sm_jetzt;
            $sm_e['start_bezug'] = $sm_zaehler['bezug'];
            $sm_e['start_einsp'] = $sm_zaehler['einspeisung'];
            $sm_e['start_einheit'] = $sm_zaehler['einheit'];
            $sm_e['urteil'] = 'laeuft_an';
            $sm_neu[$sm_nr] = $sm_e;
            continue;
        }
        // Sie lief schon - Startpunkt uebernehmen und laufend urteilen.
        foreach (array('start_ts', 'start_bezug', 'start_einsp', 'start_einheit') as $sm_k) {
            if (isset($sm_vor[$sm_k])) { $sm_e[$sm_k] = $sm_vor[$sm_k]; }
        }
    } else {
        // Sie ist aus. Lief sie vorher, bleibt das ERGEBNIS stehen; sonst
        // gibt es nichts zu sagen.
        if (empty($sm_vor['aktiv'])) {
            foreach (array('dauer', 'soll', 'ist', 'fehlt', 'urteil', 'sicher') as $sm_k) {
                if (isset($sm_vor[$sm_k])) { $sm_e[$sm_k] = $sm_vor[$sm_k]; }
            }
            $sm_neu[$sm_nr] = $sm_e;
            continue;
        }
        foreach (array('start_ts', 'start_bezug', 'start_einsp', 'start_einheit') as $sm_k) {
            if (isset($sm_vor[$sm_k])) { $sm_e[$sm_k] = $sm_vor[$sm_k]; }
        }
    }

    if (!isset($sm_e['start_ts'], $sm_e['start_bezug'])) {
        $sm_e['urteil'] = 'kein_start';
        $sm_neu[$sm_nr] = $sm_e;
        continue;
    }
    if ($sm_zaehler['bezug'] === null) {
        $sm_e['urteil'] = 'kein_zaehler';
        $sm_neu[$sm_nr] = $sm_e;
        continue;
    }

    $sm_dauer = (int) (($sm_zaehler['ts'] ? $sm_zaehler['ts'] : $sm_jetzt) - (int) $sm_e['start_ts']);
    $sm_e['dauer'] = $sm_dauer;
    if ($sm_dauer <= 0) {
        $sm_e['urteil'] = 'laeuft_an';
        $sm_neu[$sm_nr] = $sm_e;
        continue;
    }
    // Soll: Leistung mal Laufzeit. Ist: was der Zaehler dazugezaehlt hat.
    $sm_e['soll'] = round($sm_leistung * $sm_dauer / 3600.0, 3);
    $sm_ist = $sm_zaehler['bezug'] - (float) $sm_e['start_bezug'];
    if ($sm_ist < 0) {
        // Zaehlerwechsel oder Rueckwaertssprung - kein Messwert.
        $sm_e['urteil'] = 'zaehler_sprang';
        unset($sm_e['soll']);
        $sm_neu[$sm_nr] = $sm_e;
        continue;
    }
    $sm_e['ist'] = round($sm_ist, 3);
    $sm_e['fehlt'] = round(max(0.0, $sm_e['soll'] - $sm_ist), 3);

    /* Die Schranke gilt nur ohne eigene Einspeisung: die drueckt den
     * Netzbezug, ohne dass die Regel weniger zieht. Und nur mit belegter
     * Einheit. */
    $sm_einsp = 0.0;
    if ($sm_zaehler['einspeisung'] !== null && isset($sm_e['start_einsp'])
        && $sm_e['start_einsp'] !== null) {
        $sm_einsp = (float) $sm_zaehler['einspeisung'] - (float) $sm_e['start_einsp'];
    }
    $sm_sicher = ($sm_zaehler['einheit'] === 'kWh') && ($sm_einsp <= 0.001);
    $sm_e['sicher'] = $sm_sicher ? 1 : 0;

    if (!$sm_sicher) {
        $sm_e['urteil'] = 'unsicher';
    } elseif ($sm_dauer < SM_AB_MINDAUER) {
        // Zu kurz fuer ein Urteil - aber die Zahlen stehen trotzdem da.
        $sm_e['urteil'] = 'zu_kurz';
    } elseif ($sm_ist >= $sm_e['soll'] * SM_AB_TOLERANZ) {
        $sm_e['urteil'] = 'zieht';
    } else {
        $sm_e['urteil'] = 'zieht_nicht';
        if (empty($sm_vor['urteil']) || $sm_vor['urteil'] !== 'zieht_nicht') {
            sm_ab_log(sprintf('Regel %s: Soll %.3f kWh in %d s, gemessener Netzbezug '
                . 'nur %.3f kWh. Die Regel zieht nachweislich nicht.',
                $sm_nr, $sm_e['soll'], $sm_dauer, $sm_ist), 'WARN');
        }
    }
    $sm_neu[$sm_nr] = $sm_e;
}
$sm_stand['regeln'] = $sm_neu;
/* M4: Regeln, die der Fahrplan nicht mehr fuehrt. */
foreach (array_keys($sm_alt_regeln) as $sm_nr) {
    $sm_nr = (string) $sm_nr;
    if (!isset($sm_neu[$sm_nr]) && !in_array($sm_nr, $sm_entfernt_offen, true)
        && preg_match('/^[0-9]+$/', $sm_nr)) {
        $sm_entfernt_offen[] = $sm_nr;
    }
}
$sm_entfernt_offen = array_values(array_diff($sm_entfernt_offen, array_map('strval', array_keys($sm_neu))));
$sm_stand['entfernt_offen'] = $sm_entfernt_offen;

$sm_ok = true;
if (!sm_ab_atomar($SM_STAND, json_encode($sm_stand), 0640)) {
    sm_ab_log('Der Stand ' . $SM_STAND . ' liess sich nicht schreiben.', 'ERROR');
    $sm_ok = false;
}

/* ---- MQTT ueber das UDP-Relais des Gateways ----
 *
 * Derselbe Weg wie in bin/fetch_vzlogger.pl: "<verb> <thema> <wert>" an
 * den Udpinport aus general.json. Ein eigener Broker-Griff waere ein
 * zweiter Sendeweg neben dem, den das Plugin schon hat.
 * Seit dem Durchgang 01.10.2026 (M3, Entscheidung 3) geht aktiv RETAINED
 * hinaus - es ist der Zustand der Regel; alles andere bleibt fluechtig. */
$sm_zeilen = array(array('publish', 'abgleich/quelle_ok', (int) $sm_stand['quelle_ok']));
foreach ($sm_neu as $sm_nr => $sm_e) {
    $sm_z = 'abgleich/' . $sm_nr . '/';
    $sm_zeilen[] = array('retain',  $sm_z . 'aktiv',  (int) $sm_e['aktiv']);
    $sm_zeilen[] = array('publish', $sm_z . 'soll',   isset($sm_e['soll']) ? $sm_e['soll'] : 0);
    $sm_zeilen[] = array('publish', $sm_z . 'ist',    isset($sm_e['ist']) ? $sm_e['ist'] : 0);
    $sm_zeilen[] = array('publish', $sm_z . 'fehlt',  isset($sm_e['fehlt']) ? $sm_e['fehlt'] : 0);
    $sm_zeilen[] = array('publish', $sm_z . 'dauer',  isset($sm_e['dauer']) ? (int) $sm_e['dauer'] : 0);
    $sm_zeilen[] = array('publish', $sm_z . 'sicher', isset($sm_e['sicher']) ? (int) $sm_e['sicher'] : 0);
    /* Ein Zahlenwert fuer Loxone - Woerter kann der Miniserver nicht
     * vergleichen. 1 = zieht, 0 = zieht nicht, -1 = kein Urteil (unsicher,
     * zu kurz, kein Zaehler, ohne Leistung). */
    $sm_u = isset($sm_e['urteil']) ? $sm_e['urteil'] : '';
    $sm_zeilen[] = array('publish', $sm_z . 'ok', ($sm_u === 'zieht') ? 1
                                                 : (($sm_u === 'zieht_nicht') ? 0 : -1));
}
/* M4: eine entfernte Regel bekommt einmal aktiv "-" (retained, Entscheidung 5)
 * und ok -1 ("kein Urteil", fluechtig). ok 0 hiesse "zieht nachweislich
 * nicht" - fuer eine geloeschte Regel eine falsche Aussage. */
foreach ($sm_entfernt_offen as $sm_nr) {
    $sm_zeilen[] = array('retain', 'abgleich/' . $sm_nr . '/aktiv', '-');
    $sm_zeilen[] = array('publish', 'abgleich/' . $sm_nr . '/ok', -1);
}
if (sm_ab_mqtt($sm_cfg, $sm_zeilen) && $sm_entfernt_offen) {
    sm_ab_log('Regel(n) ' . implode(', ', $sm_entfernt_offen) . ' fuehrt der Fahrplan nicht mehr - '
        . 'aktiv "-" und ok -1 gesendet.', 'INFO');
    $sm_stand['entfernt_offen'] = array();
    if (!sm_ab_atomar($SM_STAND, json_encode($sm_stand), 0640)) {
        $sm_ok = false;
    }
}

if ($sm_verbose) {
    printf("%d Regel(n) beobachtet.\n", count($sm_neu));
}
exit($sm_ok ? 0 : 1);
