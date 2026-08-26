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
 * Schlusszeile: SMARTMETER;OK=1;ALTER=42;ZAEHLER=137;KOEPFE=1
 * Abschluss:   #EOF
 *
 * WARUM ES DIE SCHLUSSZEILE SEIT 2.3.14 GIBT
 *
 * Ein virtueller Eingang behaelt seinen letzten Wert. Faellt der Zaehler
 * aus, sieht in der App alles normal aus. Bis 2.3.14 empfahl der Reiter
 * "Einbindung in Loxone", dafuer die Wirkleistung zu beobachten - die steht
 * nachts bei konstanter Grundlast minutenlang exakt still, und bei einem
 * Zaehler ohne 16.7.0 gibt es sie gar nicht. Die Empfehlung war mit den
 * vorhandenen Feldern nicht umsetzbar.
 *
 *   ALTER    Sekunden seit der letzten erfolgreichen Messung. Zur LESEZEIT
 *            gerechnet, nicht beim Schreiben eingefroren - ein
 *            eingefrorenes Alter kann einen toten Dienst nicht von einer
 *            frischen Messung unterscheiden.
 *   ZAEHLER  0..999, laeuft um; -1 = noch nie gelaufen. Er ist von einem
 *            Zeitsprung unabhaengig, den ein Raspberry Pi nach dem Booten
 *            regelmaessig macht.
 *   OK       aus dem Alter gegen eine Grenze, die deutlich ueber dem
 *            Abfragetakt liegt - und die in EINER Funktion steht
 *            (smg_alter_grenze in bin/sm_gemein.php).
 *   KOEPFE   wie viele Datendateien gelesen wurden.
 *
 * Zum Token: Er ist FREIWILLIG. Wird im Reiter "Einbindung in Loxone" keiner
 * gesetzt, verhaelt sich der Endpunkt wie bisher. Ein Pflichttoken wuerde
 * bei jedem bestehenden Aufbau die Verbindung zum Miniserver abreissen
 * lassen, ohne dass jemand versteht, warum.
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

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
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

function sm_ende($code, $grund)
{
    http_response_code($code);
    echo 'FEHLER;OK=0;GRUND=' . $grund . "\n";
    echo "#EOF\n";
    exit;
}

$sm_home = getenv('LBHOMEDIR');
if (!$sm_home || !is_dir($sm_home)) {
    $sm_home = lb_wurzel_ermitteln();
}
$sm_ordner = sm_pluginordner();

/* Der gemeinsame Vorlauf. Er liegt unter bin/ - auf dem installierten
 * LoxBerry liegen html/, htmlauth/ und bin/ in GETRENNTEN Baeumen, ein
 * require ueber eine feste Zahl von ".." trifft nur den Archivfall. Deshalb
 * eine Kandidatenliste, und im Fehlerfall eine LESBARE Antwort statt eines
 * leeren HTTP 500.
 *
 * Die durchsuchten Pfade gehen ins Fehlerprotokoll des Webservers, NICHT in
 * die Antwort: an dieser Stelle hat sich der Aufrufer noch nicht ueber das
 * Token ausgewiesen. */
$sm_kandidaten = array(
    ($sm_home !== '' ? $sm_home . '/bin/plugins/' . $sm_ordner . '/sm_gemein.php' : ''),
    dirname(dirname(dirname(__DIR__))) . '/bin/plugins/' . $sm_ordner . '/sm_gemein.php',
    dirname(dirname(__DIR__)) . '/bin/sm_gemein.php',
);
$sm_gefunden = false;
foreach ($sm_kandidaten as $sm_k) {
    if ($sm_k !== '' && is_file($sm_k)) {
        require_once $sm_k;
        $sm_gefunden = true;
        break;
    }
}
if (!$sm_gefunden) {
    error_log('Smartmeter classic: sm_gemein.php nicht gefunden, gesucht in: '
        . implode(', ', array_filter($sm_kandidaten)));
    sm_ende(500, 'BIBLIOTHEK_FEHLT');
}

if ($sm_home === '') {
    sm_ende(500, 'KEINE_WURZEL');
}

$sm_cfgdatei = $sm_home . '/config/plugins/' . $sm_ordner . '/smartmeter.cfg';
$sm_cfg = smg_cfg_lesen($sm_cfgdatei);

/* ---------------- Token, falls eingerichtet ----------------
 *
 * Aus $_GET gelesen, nie aus $_REQUEST: was dort steht, haengt an
 * request_order, und die faellt bei leerem Wert auf variables_order
 * zurueck - mit Cookies. Ein Cookie namens "token" haette die Pruefung
 * gefuettert. */
$sm_soll = smg_wert($sm_cfg, 'MAIN', 'TOKEN', '');
$sm_ist  = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
$sm_token_ok = true;
if ($sm_soll !== '') {
    // hash_equals statt ==: ein zeichenweiser Vergleich verraet ueber die
    // Antwortzeit, wie viele Zeichen schon stimmen. Und der leere Fall wird
    // VORHER abgefangen - hash_equals('', '') ist true.
    $sm_token_ok = ($sm_ist !== '') && hash_equals($sm_soll, $sm_ist);
}

/* ---------------- Selbsttest ----------------
 *
 * Ein Token muss sich pruefen lassen, ohne dass etwas passiert. Ohne diesen
 * Zweig gibt es nur zwei schlechte Moeglichkeiten: entweder man ruft Daten
 * ab, oder man erfaehrt nie, ob die Adresse im Miniserver noch stimmt.
 *
 * Er steht so, dass die Tokenpruefung greift, aber nichts ausgeliefert wird:
 * kein Zugriff auf die Ramdisk, kein Schreibvorgang.
 *
 * Der dritte Fall weicht vom Hausstandard ab, und die Abweichung gehoert an
 * Ort und Stelle begruendet: bei diesem Plugin ist das Token FREIWILLIG. Ein
 * leeres Soll ist hier kein Fehler, sondern eine Einstellung - die Antwort
 * sagt es trotzdem, statt zu schweigen. */
if (isset($_GET['selftest'])) {
    if ($sm_soll === '') {
        echo "SELFTEST;OK=1;TOKEN=OFFEN\n";
        echo "#EOF\n";
        exit(0);
    }
    if (!$sm_token_ok) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=TOKEN\n";
        echo "#EOF\n";
        exit;
    }
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    echo "#EOF\n";
    exit(0);
}

if (!$sm_token_ok) {
    sm_ende(403, 'TOKEN');
}

/* ---------------- Daten ausliefern ---------------- */
$sm_shm = '/dev/shm/' . $sm_ordner;
if (!is_dir($sm_shm)) {
    sm_ende(503, 'KEINE_DATEN');
}

// Feste Reihenfolge. readdir() liefert die Dateien in der Reihenfolge des
// Dateisystems - bei mehreren Lesekoepfen kann sich die Antwort sonst von
// Abruf zu Abruf umsortieren.
$sm_dateien = glob($sm_shm . '/*.data');
if ($sm_dateien === false) {
    $sm_dateien = array();
}
sort($sm_dateien);

$sm_gefunden = 0;
$sm_juengste = 0;
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
    // Der Zeitpunkt der letzten ERFOLGREICHEN Messung. Er steht in der Datei
    // und wird nur dann geschrieben, wenn wirklich Werte gelesen wurden -
    // der Zeitstempel gehoert zur Messung, nicht zum Schreibvorgang.
    if (preg_match_all('/:Last_UpdateUnix:([0-9]+)/', $sm_inhalt, $sm_m)) {
        foreach ($sm_m[1] as $sm_ts) {
            if ((int) $sm_ts > $sm_juengste) { $sm_juengste = (int) $sm_ts; }
        }
    }
}

if ($sm_gefunden === 0) {
    sm_ende(503, 'KEINE_DATEN');
}

/* ---------------- Die Schlusszeile ----------------
 *
 * Sie wird ANGEHAENGT, nicht eingeschoben: bestehende Anlagen suchen
 * woertlich nach ihren Feldnamen, und eine neue Groesse hinten stoert sie
 * nicht. */
$sm_vzjson = $sm_home . '/config/plugins/' . $sm_ordner . '/vzlogger.json';
$sm_vz_an = false;
if (is_readable($sm_vzjson)) {
    $sm_vzd = json_decode((string) @file_get_contents($sm_vzjson), true);
    $sm_vz_an = is_array($sm_vzd) && !empty($sm_vzd['enabled']);
}
$sm_grenze = smg_alter_grenze(smg_wert($sm_cfg, 'MAIN', 'CRON', '5'), $sm_vz_an);
$sm_zaehler = smg_zaehler_lesen($sm_shm . '/zaehler');

if ($sm_juengste > 0) {
    $sm_alter = time() - $sm_juengste;
    // Ein Zeitsprung nach vorn machte das Alter sonst negativ, und
    // max(0, ...) meldete "gerade eben gemessen".
    if ($sm_alter < 0) { $sm_alter = -1; }
} else {
    // Kein Zeitstempel: es hat noch keine erfolgreiche Messung gegeben, oder
    // die Datei stammt aus einer Fassung vor 2.3.14. Eine 0 waere hier eine
    // stille Falschaussage.
    $sm_alter = -1;
}
// OK beantwortet die Frage, die der Anwender stellt: ist dieser Wert
// aktuell? Eine Grenze von 0 heisst "es gibt keine" - dann gibt es auch
// kein Urteil, und OK bleibt 1.
$sm_ok = 1;
if ($sm_alter < 0) {
    $sm_ok = 0;
} elseif ($sm_grenze > 0 && $sm_alter > $sm_grenze) {
    $sm_ok = 0;
}

echo 'SMARTMETER;OK=' . $sm_ok
   . ';ALTER=' . $sm_alter
   . ';ZAEHLER=' . $sm_zaehler
   . ';KOEPFE=' . $sm_gefunden
   . ';GRENZE=' . $sm_grenze . "\n";
echo "#EOF\n";
exit(0);
