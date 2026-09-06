#!/usr/bin/env php
<?php
/**
 * Smartmeter classic - die Verbrauchshistorie je Stunde
 *
 * WOZU
 *
 * Das Plugin haelt bis 2.5.0 nur den LETZTEN Zaehlerstand, und der liegt auf
 * der Ramdisk. Nach jedem Neustart des LoxBerry ist er weg. Damit laesst sich
 * nicht beantworten, was in der letzten Stunde oder am gestrigen Tag
 * verbraucht wurde - und genau das braucht ein dynamischer Tarif, weil dort
 * jede Stunde einen eigenen Preis hat.
 *
 * WARUM EIN EIGENES SKRIPT UND NICHT IN DEN BEIDEN LESERN
 *
 * Es gibt zwei Lesewege: bin/fetch.php (klassisch, PHP) und
 * bin/fetch_vzlogger.pl (vzLogger, Perl). Die Fortschreibung in beide
 * einzubauen hiesse, dieselbe Rechnung in zwei Sprachen zu pflegen - genau
 * der Fehler, den 2.4.0 mit bin/sm_felder.json abgestellt hat.
 *
 * Beide schreiben aber dieselbe Datei im selben Schema:
 *
 *     /dev/shm/<ordner>/<serial>.data      Zeilen "SERIAL:Feldname:Wert"
 *
 * Das ist die vorhandene gemeinsame Schnittstelle. Dieses Skript liest sie
 * und rechnet - ein Verbraucher, eine Sprache, beide Lesewege gedeckt. Beide
 * Leser schreiben ueber temp + rename; eine halb geschriebene Datei kann
 * hier also nicht ankommen.
 *
 * ZWEI DATEIEN, UND WARUM
 *
 *     historie_merker.json    klein, wird bei JEDEM Lauf angefasst
 *     historie.csv            gross, wird nur beim STUNDENWECHSEL angefasst
 *
 * Der erste Entwurf hielt alles in der CSV und schrieb sie bei jedem
 * Minutenlauf neu. Bei 24 Monaten sind das rund 17500 Zeilen, 1440 mal am
 * Tag - auf einer SD-Karte. Deshalb sammelt der Merker die laufende Stunde,
 * und erst wenn sie vorbei ist, wird EINE Zeile an die CSV ANGEHAENGT: 24
 * Schreibvorgaenge am Tag statt 1440 vollstaendiger Neuschreibungen.
 *
 * Der Rueckschnitt auf SM_HIST_MONATE schreibt die Datei doch einmal ganz -
 * das geschieht einmal taeglich in der Stunde SM_HIST_PUTZSTUNDE.
 *
 * WAS ES NICHT TUT
 *
 * Es liest den Zaehler NICHT selbst und redet mit keinem Geraet. Es sieht
 * nur an, was der jeweilige Leser hinterlegt hat. Laeuft kein Leser, waechst
 * die Historie nicht - und das ist die richtige Antwort, nicht eine
 * gerechnete Fortsetzung.
 *
 * DIE EINHEIT
 *
 * Auf dem KLASSISCHEN Weg rechnet bin/sml_parser.php Wh auf kWh um; die
 * Einheit ist damit belegt und steht als 'einheit' im Katalog.
 *
 * Auf dem vzLOGGER-Weg reicht vzlogger den Wert des Zaehlers durch. Welche
 * Einheit das ist, ist nicht gemessen - 'einheit_vz' im Katalog ist leer,
 * und deshalb steht seit 2.5.0 auch in der Loxone-Vorlage keine.
 *
 * Die Historie schreibt die Differenz TROTZDEM mit, aber sie schreibt die
 * Einheit DANEBEN. Eine Zeile mit "?" heisst: gemessen ja, umgerechnet nein.
 * Wer sie in kWh haben will, wartet, bis einheit_vz belegt ist - er bekommt
 * keine Zahl, die so aussieht als waere sie es. Der Lastgang-Endpunkt
 * liefert nur belegte Zeilen aus und sagt, wieviele er weggelassen hat.
 *
 * Aufrufe von Hand:
 *     sm_historie.php --verbose   ein Durchlauf mit Bildschirmausgabe
 *     sm_historie.php --putzen    den Rueckschnitt sofort ausfuehren
 *     sm_historie.php --zeigen    die letzten Stunden ausgeben, nichts aendern
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$sm_verbose = false;
$sm_putzen  = false;
$sm_zeigen  = false;
foreach ($argv as $sm_i => $sm_a) {
    if ($sm_i === 0) { continue; }
    if ($sm_a === '--verbose' || $sm_a === '-v') { $sm_verbose = true; continue; }
    if ($sm_a === '--putzen') { $sm_putzen = true; $sm_verbose = true; continue; }
    if ($sm_a === '--zeigen') { $sm_zeigen = true; continue; }
    // Ein unbekannter Schalter darf nicht stillschweigend durchfallen und
    // in einem Durchlauf landen.
    fwrite(STDERR, "Unbekannter Schalter: " . $sm_a . "\n");
    exit(2);
}

/* Der gemeinsame Vorlauf. */
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

$SM_SHM    = '/dev/shm/' . $sm_ordner;
$SM_DATA   = $sm_home . '/data/plugins/' . $sm_ordner;
$SM_VZJSON = $sm_home . '/config/plugins/' . $sm_ordner . '/vzlogger.json';
$SM_FELDER = __DIR__ . '/sm_felder.json';
$SM_HIST   = $SM_DATA . '/historie.csv';
$SM_MERKER = $SM_DATA . '/historie_merker.json';
$SM_LOG    = $sm_home . '/log/plugins/' . $sm_ordner . '/smartmeter.log';

/* Wieviele Monate bleiben stehen. 24: damit laesst sich ein Jahr gegen das
 * Vorjahr halten, und die Datei bleibt bei einem Zaehler in der
 * Groessenordnung von 17500 Zeilen. */
define('SM_HIST_MONATE', 24);
/* In welcher Stunde geputzt wird. Drei Uhr nachts: dann laeuft ohnehin
 * wenig, und ein Neuschreiben der ganzen Datei stoert niemanden. */
define('SM_HIST_PUTZSTUNDE', 3);
/* Groesster Abstand zweier Messungen, aus dem noch eine Differenz gebildet
 * wird. Der langsamste einstellbare Takt des klassischen Lesers ist
 * stuendlich; zwei Stunden lassen einen verpassten Lauf durch, ohne aus
 * einer halben Nacht eine Stunde zu machen. */
define('SM_HIST_MAXLUECKE', 7200);

define('SM_HIST_KOPF', 'stunde;serial;bezug_wh;einspeisung_wh;einheit;quelle;messungen');

/**
 * Die Felder, deren Zaehlerstand fortgeschrieben wird. Bezug und
 * Einspeisung - mehr braucht ein Tarifvergleich nicht, und jedes weitere
 * Feld waere eine Spalte, die niemand liest.
 */
function sm_h_felder()
{
    return array(
        'bezug'       => 'Consumption_Total_OBIS_1.8.0',
        'einspeisung' => 'Delivery_Total_OBIS_2.8.0',
    );
}

function sm_h_log($text, $stufe = 'INFO')
{
    global $SM_LOG, $sm_verbose;
    @mkdir(dirname($SM_LOG), 0775, true);
    $zeile = date('Y-m-d H:i:s') . ' [' . $stufe . '] [historie] ' . $text;
    @file_put_contents($SM_LOG, $zeile . "\n", FILE_APPEND);
    if (function_exists('smg_log_kappen')) { smg_log_kappen($SM_LOG); }
    if ($sm_verbose) { echo $zeile . "\n"; }
}

/**
 * Welche Einheit hat der Zaehlerstand dieses Feldes auf DIESEM Leseweg?
 * Rueckgabe: 'kWh', 'Wh' oder '' (nicht belegt). Die leere Antwort ist kein
 * Fehler, sondern die einzige richtige, solange einheit_vz leer ist.
 */
function sm_h_einheit($feld, $vz_an)
{
    global $SM_FELDER;
    static $kat = null;
    if ($kat === null) {
        $kat = function_exists('smg_katalog') ? smg_katalog($SM_FELDER)
                                              : array('felder' => array());
    }
    if (!isset($kat['felder'][$feld])) { return ''; }
    $md = $kat['felder'][$feld];
    if ($vz_an) {
        return isset($md['einheit_vz']) ? (string) $md['einheit_vz'] : '';
    }
    return isset($md['einheit']) ? (string) $md['einheit'] : '';
}

/** Einen Zaehlerstand in Wh umrechnen - oder null, wenn die Einheit fehlt. */
function sm_h_nach_wh($wert, $einheit)
{
    if ($einheit === 'kWh') { return (float) $wert * 1000.0; }
    if ($einheit === 'Wh')  { return (float) $wert; }
    return null;
}

/** Eine .data-Datei in ein Feld Feldname => Wert zerlegen. */
function sm_h_data_lesen($pfad)
{
    $werte = array();
    $roh = @file_get_contents($pfad);
    if ($roh === false || $roh === '') { return $werte; }
    foreach (preg_split('/\R/', $roh) as $z) {
        $z = trim($z);
        if ($z === '') { continue; }
        // SERIAL:Feldname:Wert - der Feldname steht zwischen zwei
        // Doppelpunkten, der Wert kann selbst welche enthalten
        // (Last_Update ist ein Zeitstempel mit Doppelpunkten).
        $teile = explode(':', $z, 3);
        if (count($teile) !== 3) { continue; }
        $werte[$teile[1]] = $teile[2];
    }
    return $werte;
}

/** Schreiben ueber temp + rename. */
function sm_h_atomar($pfad, $inhalt, $rechte = null)
{
    @mkdir(dirname($pfad), 0775, true);
    $tmp = $pfad . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    if ($rechte !== null && !@chmod($tmp, $rechte)) { @unlink($tmp); return false; }
    $ok = ftruncate($fh, 0);
    // Nicht auf === false pruefen: eine KURZE Schreibung ist genauso kaputt
    // wie gar keine, meldet sich aber nicht als Fehler.
    $ok = $ok && (fwrite($fh, $inhalt) === strlen($inhalt));
    fflush($fh);
    // close ZUERST beurteilen: erst dort meldet sich ein Schreibfehler, den
    // die gepufferten Aufrufe verschluckt haben.
    if (!fclose($fh)) { @unlink($tmp); return false; }
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

/* ==================================================================
 * Der Merker
 *
 * Er haelt zweierlei:
 *   'stand'   je Zaehlernummer und Feld den zuletzt VERARBEITETEN Stand
 *   'offen'   die noch nicht abgeschlossenen Stunden je Zaehlernummer
 *
 * Er liegt im Datenverzeichnis und nicht auf der Ramdisk - sonst begaenne
 * die Historie nach jedem Neustart von vorn, und die Stunde des Neustarts
 * fehlte immer.
 * ================================================================== */
function sm_h_merker_lesen()
{
    global $SM_MERKER;
    $leer = array('stand' => array(), 'offen' => array());
    if (!is_readable($SM_MERKER)) { return $leer; }
    $d = json_decode((string) @file_get_contents($SM_MERKER), true);
    if (!is_array($d)) { return $leer; }
    return array(
        'stand' => (isset($d['stand']) && is_array($d['stand'])) ? $d['stand'] : array(),
        'offen' => (isset($d['offen']) && is_array($d['offen'])) ? $d['offen'] : array(),
    );
}

function sm_h_merker_schreiben($m)
{
    global $SM_MERKER;
    $roh = json_encode($m);
    if ($roh === false) { return false; }
    return sm_h_atomar($SM_MERKER, $roh, 0640);
}

/** Eine Zahl ohne Tausendertrenner und ohne nutzlose Nullen. */
function sm_h_zahl($w)
{
    $s = rtrim(rtrim(number_format((float) $w, 3, '.', ''), '0'), '.');
    return $s === '' || $s === '-' ? '0' : $s;
}

/** Eine fertige Stunde als CSV-Zeile. */
function sm_h_zeile($z)
{
    return implode(';', array(
        (int) $z['stunde'], $z['serial'],
        sm_h_zahl($z['bezug']), sm_h_zahl($z['einspeisung']),
        $z['einheit'], $z['quelle'], (int) $z['messungen'],
    ));
}

/** Eine Zeile ANHAENGEN. Der Kopf entsteht beim ersten Mal. */
function sm_h_anhaengen($zeilen)
{
    global $SM_HIST;
    if (!$zeilen) { return true; }
    @mkdir(dirname($SM_HIST), 0775, true);
    $neu = !is_file($SM_HIST);
    $fh = @fopen($SM_HIST, 'ab');
    if ($fh === false) { return false; }
    $text = ($neu ? SM_HIST_KOPF . "\n" : '') . implode("\n", $zeilen) . "\n";
    $ok = (fwrite($fh, $text) === strlen($text));
    if (!fclose($fh)) { return false; }
    if ($neu) { @chmod($SM_HIST, 0640); }
    return $ok;
}

/**
 * Der Rueckschnitt. Er schreibt die Datei EINMAL ganz neu und laeuft
 * deshalb nur einmal am Tag.
 *
 * Gerechnet ueber strtotime, nicht ueber 30*86400: Monate sind verschieden
 * lang, und ein Rueckschnitt, der im Februar anders greift als im Maerz,
 * ist eine Fehlerquelle ohne Gegenwert.
 */
function sm_h_putzen()
{
    global $SM_HIST;
    if (!is_readable($SM_HIST)) { return 0; }
    $grenze = strtotime('-' . SM_HIST_MONATE . ' months');
    $fh = @fopen($SM_HIST, 'rb');
    if ($fh === false) { return -1; }
    $behalten = array();
    $weg = 0;
    while (($z = fgets($fh)) !== false) {
        $z = rtrim($z, "\r\n");
        if ($z === '' || strncmp($z, 'stunde;', 7) === 0) { continue; }
        $f = explode(';', $z);
        if (count($f) < 7) { continue; }
        if ((int) $f[0] < $grenze) { $weg++; continue; }
        $behalten[] = $z;
    }
    fclose($fh);
    if ($weg === 0) { return 0; }
    $ok = sm_h_atomar($SM_HIST, SM_HIST_KOPF . "\n" . implode("\n", $behalten) . "\n", 0640);
    return $ok ? $weg : -1;
}

/* ==================================================================
 * --zeigen: nachsehen, ohne etwas zu aendern
 * ================================================================== */
if ($sm_zeigen) {
    if (!is_readable($SM_HIST)) {
        echo "Es gibt noch keine Historie (" . $SM_HIST . ").\n";
        echo "Sie entsteht, sobald der Leser zweimal gelaufen ist.\n";
        exit(0);
    }
    $alle = array();
    $fh = fopen($SM_HIST, 'rb');
    while (($z = fgets($fh)) !== false) {
        $z = rtrim($z, "\r\n");
        if ($z === '' || strncmp($z, 'stunde;', 7) === 0) { continue; }
        $alle[] = $z;
    }
    fclose($fh);
    $letzte = array_slice($alle, -24);
    printf("%d Zeile(n) in %s, die letzten %d:\n\n", count($alle), $SM_HIST, count($letzte));
    printf("%-19s %-16s %12s %12s %-8s %-9s %s\n",
           'Stunde', 'Zaehlernummer', 'Bezug', 'Einspeisung', 'Einheit', 'Quelle', 'Messungen');
    foreach ($letzte as $z) {
        $f = explode(';', $z);
        if (count($f) < 7) { continue; }
        printf("%-19s %-16s %12s %12s %-8s %-9s %s\n",
               date('Y-m-d H:i', (int) $f[0]), $f[1], $f[2], $f[3], $f[4], $f[5], $f[6]);
    }
    $m = sm_h_merker_lesen();
    if ($m['offen']) {
        echo "\nNoch nicht abgeschlossen (laufende Stunde):\n";
        foreach ($m['offen'] as $k => $o) {
            printf("  %-19s %-16s Bezug %s  Einspeisung %s  %s  %d Messung(en)\n",
                   date('Y-m-d H:i', (int) $o['stunde']), $o['serial'],
                   sm_h_zahl($o['bezug']), sm_h_zahl($o['einspeisung']),
                   $o['einheit'], (int) $o['messungen']);
        }
    }
    echo "\nEine Einheit \"?\" heisst: gemessen ja, umgerechnet nein - auf dem\n";
    echo "vzLogger-Weg ist einheit_vz im Katalog noch nicht belegt. Solche\n";
    echo "Stunden liefert der Lastgang-Endpunkt NICHT aus.\n";
    exit(0);
}

/* ==================================================================
 * Der Durchlauf
 * ================================================================== */

// Welcher Leseweg laeuft? Er entscheidet ueber die Einheit.
$sm_vz_an = false;
if (is_readable($SM_VZJSON)) {
    $sm_vzd = json_decode((string) @file_get_contents($SM_VZJSON), true);
    $sm_vz_an = is_array($sm_vzd) && !empty($sm_vzd['enabled']);
}
$sm_quelle = $sm_vz_an ? 'vzlogger' : 'legacy';

$sm_merker = sm_h_merker_lesen();
$sm_jetzt  = time();
$sm_stunde_jetzt = $sm_jetzt - ($sm_jetzt % 3600);
$sm_geaendert = false;

$sm_dateien = is_dir($SM_SHM) ? glob($SM_SHM . '/*.data') : array();
if ($sm_dateien === false) { $sm_dateien = array(); }
sort($sm_dateien);

foreach ($sm_dateien as $sm_datei) {
    $sm_werte = sm_h_data_lesen($sm_datei);
    if (!$sm_werte) { continue; }

    /* Der Zeitpunkt der MESSUNG, nicht der des Schreibens. Last_UpdateUnix
     * steht nur da, wenn wirklich Werte gelesen wurden - fehlt er, ist die
     * Datei aus einem Lauf ohne Messung oder aus einer Fassung vor 2.3.14.
     * Beides ist kein Messzeitpunkt, und geraten wird nicht. */
    if (!isset($sm_werte['Last_UpdateUnix'])
        || !preg_match('/^[0-9]+$/', trim($sm_werte['Last_UpdateUnix']))) {
        continue;
    }
    $sm_ts = (int) $sm_werte['Last_UpdateUnix'];
    $sm_serial = preg_replace('/\.data$/', '', basename($sm_datei));
    $sm_stunde = $sm_ts - ($sm_ts % 3600);

    foreach (sm_h_felder() as $sm_art => $sm_feld) {
        if (!isset($sm_werte[$sm_feld])) { continue; }
        $sm_roh = trim($sm_werte[$sm_feld]);
        if (!is_numeric($sm_roh)) { continue; }
        $sm_stand = (float) $sm_roh;
        $sm_schl = $sm_serial . '|' . $sm_art;

        $sm_vor = isset($sm_merker['stand'][$sm_schl]) ? $sm_merker['stand'][$sm_schl] : null;
        $sm_neuer_stand = array('ts' => $sm_ts, 'stand' => $sm_stand, 'quelle' => $sm_quelle);
        /* Den Merker nur anfassen, wenn sich wirklich etwas geaendert hat.
         * Er wird sonst 1440 mal am Tag neu geschrieben, ohne dass eine
         * neue Messung dahintersteht - auf einer SD-Karte ist das kein
         * Nichts. */
        if ($sm_vor !== $sm_neuer_stand) {
            $sm_merker['stand'][$sm_schl] = $sm_neuer_stand;
            $sm_geaendert = true;
        }

        if (!is_array($sm_vor) || !isset($sm_vor['ts'], $sm_vor['stand'])) {
            /* Erster Lauf fuer dieses Feld. Es gibt keine Differenz, und
             * eine erfundene waere der ganze Zaehlerstand als Verbrauch
             * einer Stunde. */
            continue;
        }
        $sm_dt = $sm_ts - (int) $sm_vor['ts'];
        if ($sm_dt <= 0) {
            /* Kein Fortschritt (derselbe Messwert wie beim letzten Lauf),
             * oder die Uhr ist zurueckgesprungen. Ein Raspberry Pi hat
             * keine Echtzeituhr; nach dem Booten steht er in der
             * Vergangenheit, und sobald NTP greift, springt die Zeit. Eine
             * Differenz ueber einen Rueckwaertssprung ist keine Messung. */
            continue;
        }
        if ($sm_dt > SM_HIST_MAXLUECKE) {
            /* Die verbrauchte Energie auf die Stunden dazwischen zu
             * verteilen waere eine Erfindung - gleichmaessiger Verbrauch
             * ist genau die Annahme, die dieses Modul abschaffen soll. Die
             * Luecke bleibt eine Luecke; der Lastgang-Endpunkt zaehlt die
             * gedeckten Stunden, und das Spotpreis-Plugin nimmt unter 20
             * von 24 sein eingebautes Profil. */
            sm_h_log('Luecke von ' . $sm_dt . ' s bei ' . $sm_serial . '/' . $sm_art
                . ' - die Stunden dazwischen bleiben leer.', 'INFO');
            continue;
        }
        if (isset($sm_vor['quelle']) && $sm_vor['quelle'] !== $sm_quelle) {
            // Der Leseweg hat gewechselt; die beiden Staende sind nicht
            // vergleichbar (verschiedene Einheiten, moeglicherweise
            // verschiedene Zaehlpunkte).
            sm_h_log('Leseweg gewechselt (' . $sm_vor['quelle'] . ' -> ' . $sm_quelle
                . ') - die erste Differenz danach wird verworfen.', 'INFO');
            continue;
        }
        $sm_diff = $sm_stand - (float) $sm_vor['stand'];
        if ($sm_diff < 0) {
            /* Ein Zaehlerstand laeuft nicht rueckwaerts. Das ist ein
             * Zaehlerwechsel, ein Ueberlauf oder ein Lesefehler - in allen
             * drei Faellen ist die Differenz keine Energie. Sie wird
             * ABGEWIESEN, nicht gedreht: ein gedrehter Wert saehe aus wie
             * ein sehr hoher Verbrauch. */
            sm_h_log('Zaehlerstand ' . $sm_serial . '/' . $sm_art
                . ' ist gefallen (' . $sm_vor['stand'] . ' -> ' . $sm_stand
                . ') - Differenz verworfen. Zaehlerwechsel?', 'WARN');
            continue;
        }

        $sm_einheit = sm_h_einheit($sm_feld, $sm_vz_an);
        $sm_wh = sm_h_nach_wh($sm_diff, $sm_einheit);
        /* Ohne belegte Einheit wird der ROHWERT gefuehrt und als solcher
         * gekennzeichnet. Nicht geraten, aber auch nicht weggeworfen: die
         * Messung ist echt, nur ihre Einheit ist offen. */
        $sm_wert = ($sm_wh === null) ? $sm_diff : $sm_wh;
        $sm_eh   = ($sm_wh === null) ? '?' : 'Wh';

        /* Die Stunde, in der die Messung ENDET. Die Energie zwischen zwei
         * Messungen auf zwei Stunden aufzuteilen waere wieder die Annahme
         * gleichmaessigen Verbrauchs; bei einem Minutentakt geht es um
         * hoechstens eine Minute Zuordnung. */
        $sm_k = $sm_serial;
        if (!isset($sm_merker['offen'][$sm_k])
            || (int) $sm_merker['offen'][$sm_k]['stunde'] !== $sm_stunde) {
            $sm_merker['offen'][$sm_k] = array(
                'stunde' => $sm_stunde, 'serial' => $sm_serial,
                'bezug' => 0.0, 'einspeisung' => 0.0,
                'einheit' => $sm_eh, 'quelle' => $sm_quelle, 'messungen' => 0,
            );
        }
        /* Eine Stunde mit gemischten Einheiten gibt es nicht - wechselt der
         * Leseweg mitten in der Stunde, gilt die unsicherere Angabe. Alles
         * andere waere eine Summe aus zwei Einheiten. */
        if ($sm_merker['offen'][$sm_k]['einheit'] !== $sm_eh) {
            $sm_merker['offen'][$sm_k]['einheit'] = '?';
        }
        $sm_merker['offen'][$sm_k][$sm_art] += $sm_wert;
        $sm_merker['offen'][$sm_k]['messungen']++;
        $sm_geaendert = true;
    }
}

/* ---- Abgeschlossene Stunden anhaengen ----
 *
 * Eine Stunde ist abgeschlossen, sobald die laufende eine andere ist. Sie
 * wird EINMAL angehaengt und danach aus dem Merker entfernt. */
$sm_fertig = array();
foreach ($sm_merker['offen'] as $sm_k => $sm_o) {
    if ((int) $sm_o['stunde'] >= $sm_stunde_jetzt) { continue; }
    $sm_fertig[] = sm_h_zeile($sm_o);
    unset($sm_merker['offen'][$sm_k]);
    $sm_geaendert = true;
}

$sm_ok = true;
if ($sm_fertig) {
    if (sm_h_anhaengen($sm_fertig)) {
        sm_h_log(count($sm_fertig) . ' abgeschlossene Stunde(n) in die Historie geschrieben.');
    } else {
        /* NICHT den Merker leeren, wenn das Anhaengen scheitert - die
         * Stunde waere sonst weg. Sie bleibt offen und wird beim naechsten
         * Lauf erneut versucht. */
        sm_h_log('Die Historie ' . $SM_HIST . ' liess sich nicht schreiben - die '
            . 'Stunde(n) bleiben offen und werden erneut versucht.', 'ERROR');
        exit(1);
    }
}

/* ---- Einmal taeglich putzen ---- */
$sm_letzte_putze = isset($sm_merker['geputzt']) ? (int) $sm_merker['geputzt'] : 0;
if ($sm_putzen
    || ((int) date('G', $sm_jetzt) === SM_HIST_PUTZSTUNDE
        && $sm_jetzt - $sm_letzte_putze > 43200)) {
    $sm_weg = sm_h_putzen();
    if ($sm_weg < 0) {
        sm_h_log('Der Rueckschnitt der Historie ist gescheitert.', 'ERROR');
        $sm_ok = false;
    } elseif ($sm_weg > 0) {
        sm_h_log($sm_weg . ' Zeile(n) aelter als ' . SM_HIST_MONATE
            . ' Monate entfernt.');
    } elseif ($sm_verbose) {
        echo "Rueckschnitt: nichts zu entfernen.\n";
    }
    $sm_merker['geputzt'] = $sm_jetzt;
    $sm_geaendert = true;
}

if ($sm_geaendert && !sm_h_merker_schreiben($sm_merker)) {
    sm_h_log('Der Merker ' . $SM_MERKER . ' liess sich nicht schreiben - die '
        . 'naechste Differenz geht verloren.', 'ERROR');
    $sm_ok = false;
}

if ($sm_verbose) {
    printf("%d Datendatei(en) angesehen, %d Stunde(n) abgeschlossen, %d offen.\n",
           count($sm_dateien), count($sm_fertig), count($sm_merker['offen']));
}
exit($sm_ok ? 0 : 1);
