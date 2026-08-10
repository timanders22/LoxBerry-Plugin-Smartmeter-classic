<?php
/**
 * Smartmeter classic - Endpunkt fuer den Miniserver
 *
 * Liefert die zuletzt gelesenen Zaehlerwerte als Klartext aus. Die Dateien
 * liegen auf der Ramdisk unter /dev/shm/<Pluginordner>/ und werden vom
 * Legacy-Leser (bin/sm_logger.pl) oder vom vzLogger-Abholer
 * (bin/fetch_vzlogger.pl) geschrieben.
 *
 * Zeilenform:  SERIAL:Schluessel:Wert
 * Abschluss:   #EOF
 *
 * WAS HIER BIS 2.3.2 SCHIEFGING - und warum die Datei neu gefasst ist:
 *
 * 1. array_pop(array_filter(explode(...)))
 *    array_pop() erwartet eine Variable, keinen Rueckgabewert. In PHP 7.4
 *    UND 8.1 gemessen: "Notice: Only variables should be passed by
 *    reference". Der Wert stimmte zwar, aber die Meldung landete mitten im
 *    Datenstrom - der Miniserver liest sie als Nutzdaten mit.
 *
 * 2. in_array($daten['extension'], ...) ohne isset
 *    Eine Datei ohne Endung im Ordner - und seit 2.3.3 gibt es dort beim
 *    Schreiben kurzzeitig Dateien - ergab: 7.4 "Notice: Undefined index",
 *    8.1 "Warning: Undefined array key". Wieder mitten in der Antwort.
 *
 * 3. Kein Schutz gegen Fremdzugriff. Der Ordner html/ ist bewusst NICHT
 *    passwortgeschuetzt, damit der Miniserver ohne Zugangsdaten liest -
 *    damit liest ihn aber auch jedes andere Geraet im Netz.
 *
 * Zum Token: Er ist FREIWILLIG. Wird im Reiter "Einbindung in Loxone" keiner
 * gesetzt, verhaelt sich der Endpunkt wie bisher. Ein Pflichttoken wuerde
 * bei jedem bestehenden Aufbau die Verbindung zum Miniserver abreissen
 * lassen, ohne dass jemand versteht, warum - das waere ein schlechterer
 * Tausch als die Zaehlerstaende, die ohnehin niemand ausserhalb des eigenen
 * Netzes erreicht.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

// Keine PHP-Meldung darf in den Datenstrom geraten: der Miniserver kann
// zwischen einer Warnung und einem Messwert nicht unterscheiden.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: inline; filename="data"');
header('Expires: 0');
header('Cache-Control: no-store, must-revalidate');
header('Pragma: no-cache');

/** Den Pluginordner aus dem eigenen Ablageort ableiten. */

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function sm_pluginordner()
{
    $ordner = basename(dirname(__FILE__));
    // NICHT "smartmeter": so heisst der Ordner des Originalplugins, das neben
    // diesem installiert sein kann. Der Rueckfall haette dessen Ramdisk
    // gelesen und fremde Zaehlerwerte an den Miniserver geliefert.
    return ($ordner === '' || $ordner === 'html') ? 'smartmeter-classic' : $ordner;
}

/**
 * Einen einzelnen Wert aus smartmeter.cfg holen.
 *
 * Zeilenweise gelesen, nicht mit parse_ini_file(): Die Datei enthaelt
 * frei vergebene Bezeichnungen der Lesekoepfe. Ein "&", "(" oder "!" darin
 * laesst parse_ini_file() die GANZE Datei verwerfen - dann waere ploetzlich
 * kein Token gesetzt, und der Endpunkt stuende offen.
 */
function sm_cfg_wert($abschnitt_gesucht, $schluessel, $vorgabe = '')
{
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        $home = lb_wurzel_ermitteln();
    }
    if ($home === '') {
        return $vorgabe;
    }
    $datei = $home . '/config/plugins/' . sm_pluginordner() . '/smartmeter.cfg';
    if (!is_readable($datei)) {
        return $vorgabe;
    }
    $abschnitt = 'MAIN';
    foreach ((array) @file($datei, FILE_IGNORE_NEW_LINES) as $z) {
        $z = trim($z);
        if ($z === '' || $z[0] === ';' || $z[0] === '#') {
            continue;
        }
        if ($z[0] === '[' && substr($z, -1) === ']') {
            $abschnitt = substr($z, 1, -1);
            continue;
        }
        $pos = strpos($z, '=');
        if ($pos === false || $abschnitt !== $abschnitt_gesucht) {
            continue;
        }
        if (trim(substr($z, 0, $pos)) === $schluessel) {
            return trim(substr($z, $pos + 1));
        }
    }
    return $vorgabe;
}

function sm_ende($code, $grund)
{
    http_response_code($code);
    echo 'FEHLER;OK=0;GRUND=' . $grund . "\n";
    echo "#EOF\n";
    exit;
}

/* ---------------- Token, falls eingerichtet ---------------- */
$sm_soll = sm_cfg_wert('MAIN', 'TOKEN', '');
if ($sm_soll !== '') {
    $sm_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
    // hash_equals statt ==: ein zeichenweiser Vergleich verraet ueber die
    // Antwortzeit, wie viele Zeichen schon stimmen.
    if (!hash_equals($sm_soll, $sm_ist)) {
        sm_ende(403, 'TOKEN');
    }
}

/* ---------------- Daten ausliefern ---------------- */
$sm_ordner = '/dev/shm/' . sm_pluginordner();
if (!is_dir($sm_ordner)) {
    sm_ende(503, 'KEINE_DATEN');
}

// Feste Reihenfolge. readdir() liefert die Dateien in der Reihenfolge des
// Dateisystems - bei mehreren Lesekoepfen kann sich die Antwort sonst von
// Abruf zu Abruf umsortieren.
$sm_dateien = glob($sm_ordner . '/*.data');
if ($sm_dateien === false) {
    $sm_dateien = array();
}
sort($sm_dateien);

$sm_gefunden = 0;
foreach ($sm_dateien as $sm_datei) {
    // Beim Schreiben liegt kurzzeitig eine Datei "<name>.data.tmp.<pid>"
    // daneben. Die endet nicht auf .data und faellt schon durch glob() raus;
    // die Pruefung bleibt trotzdem stehen, damit sie es auch tut, wenn
    // jemand das Muster spaeter lockert.
    if (substr($sm_datei, -5) !== '.data' || !is_file($sm_datei)) {
        continue;
    }
    $sm_inhalt = @file_get_contents($sm_datei);
    if ($sm_inhalt === false || $sm_inhalt === '') {
        continue;
    }
    echo $sm_inhalt;
    if (substr($sm_inhalt, -1) !== "\n") {
        echo "\n";
    }
    $sm_gefunden++;
}

if ($sm_gefunden === 0) {
    sm_ende(503, 'KEINE_DATEN');
}

echo "#EOF\n";
exit(0);
