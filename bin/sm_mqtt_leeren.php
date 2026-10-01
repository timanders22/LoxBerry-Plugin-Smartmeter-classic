#!/usr/bin/env php
<?php
/**
 * Smartmeter classic - die zurueckbehaltenen MQTT-Zustaende der Linie leeren
 *
 * SEIT DEM DURCHGANG 01.10.2026 (M3; Entscheidungen 3 und 26). Seitdem gehen
 * Breaker_State, Tarif_Indicator, Message_Code und abgleich/<n>/aktiv
 * retained hinaus; die Deinstallation raeumt sie ab - unter dem eingestellten
 * Praefix UND unter jedem vorgemerkten frueheren (Praefixwechsel, bei dem der
 * Broker das Leeren nicht bestaetigt hat).
 *
 * Aufruf aus uninstall/uninstall (als root), VOR allem anderen: Praefix und
 * Port stehen in der Konfiguration. Schreibt kein Protokoll und legt nichts an.
 *
 *     php sm_mqtt_leeren.php
 *
 * Rueckgabe 0 geleert oder nicht nachpruefbar, 1 es steht noch etwas bzw.
 * der Eingang war nicht erreichbar, 2 nicht moeglich.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 'stderr');

$sm_ordner = basename(__DIR__);
if ($sm_ordner === '' || $sm_ordner === 'bin') {
    $sm_ordner = 'smartmeter-classic';
}
if (!is_file(__DIR__ . '/sm_gemein.php')) {
    echo "<INFO> sm_gemein.php fehlt - zurueckbehaltene MQTT-Zustaende wurden nicht geleert.\n";
    exit(2);
}
require_once __DIR__ . '/sm_gemein.php';

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
    echo "<INFO> Der LoxBerry-Ordner liess sich nicht bestimmen - zurueckbehaltene MQTT-Zustaende wurden nicht geleert.\n";
    exit(2);
}

$sm_cfg = smg_cfg_lesen($sm_home . '/config/plugins/' . $sm_ordner . '/smartmeter.cfg');
$sm_vzd = smg_vz_json($sm_home . '/config/plugins/' . $sm_ordner . '/vzlogger.json');
$sm_praefix = trim(smg_wert($sm_cfg, 'MAIN', 'MQTTTOPIC', 'smartmeter'));
$sm_liste = array();
if (smg_praefix_fehler($sm_praefix) === '') {
    $sm_liste[] = $sm_praefix;
} else {
    echo '<WARNING> Das eingestellte Praefix ist unzulaessig - darunter wird nichts geleert.' . "\n";
}
foreach (smg_mqtt_vorgemerkt($sm_home, $sm_ordner) as $sm_v) {
    if (!in_array($sm_v, $sm_liste, true)) {
        $sm_liste[] = $sm_v;
    }
}
$sm_rc = 0;
foreach ($sm_liste as $sm_nr => $sm_w) {
    if ($sm_nr > 0 || $sm_w !== $sm_praefix) {
        echo '<INFO> MQTT: dazu das vorgemerkte fruehere Praefix ' . $sm_w . '/ (Praefixwechsel).' . "\n";
    }
    $sm_koepfe = smg_mqtt_koepfe($sm_ordner, $sm_cfg, $sm_vzd);
    $sm_e = smg_mqtt_raeumen($sm_home, $sm_w, $sm_koepfe,
                             smg_mqtt_ersatz($sm_home, $sm_ordner, $sm_koepfe, $sm_w));
    foreach ($sm_e['zeilen'] as $sm_z) {
        echo $sm_z . "\n";
    }
    if ($sm_e['rc'] === 1) {
        $sm_rc = 1;
    } elseif ($sm_e['rc'] === 2 && $sm_rc === 0) {
        $sm_rc = 2;
    }
    if ($sm_e['rc'] === 2) {
        break;
    }
}
exit($sm_rc);
