<?php
/**
 * Smartmeter classic - Diagnose und Selbstpruefung
 *
 * Zwei verschiedene Dinge, deshalb zwei Funktionen:
 *
 *   sm_diagnose()    beantwortet "wird der Zaehler gelesen?" - acht Stufen,
 *                    die erste rote Zeile von oben ist die Ursache. Sie
 *                    fragt das System und kostet Prozessstarts.
 *   sm_selbsttest()  beantwortet "passt das Plugin noch zusammen?" - sie
 *                    sieht sich die eigenen Dateien an und findet, was
 *                    keine Pruefkette dieses Hauses sieht.
 *
 * Beide laufen nur im geoeffneten Reiter; bis 2.3.14 lief die Diagnose bei
 * JEDEM Seitenaufbau, auch beim Speichern im Reiter MQTT - gemessen 15
 * Prozessstarts, darunter ein curl mit fuenf Sekunden Zeitgrenze.
 */

require_once __DIR__ . '/sm_lib.php';

/* ==================================================================
 * Diagnose
 * ================================================================== */

/**
 * Die Diagnose. Liefert eine Liste aus (Bezeichnung, Zustand, Text);
 * Zustand ist 'ok', 'warn' oder 'fail'.
 */
function sm_diagnose($cfg)
{
    $p = sm_paths();
    $d = array();
    $add = function ($label, $zustand, $text) use (&$d) {
        $d[] = array($label, $zustand, $text);
    };

    // 1 - Betriebsart
    if (!$cfg['enabled']) {
        $add(sm_t('DIAG.BETRIEBSART'), 'warn', sm_t('DIAG.VZ_AUS'));
        return $d;
    }
    $add(sm_t('DIAG.BETRIEBSART'), 'ok', sm_t('DIAG.VZ_AN'));

    // 2 - Programm
    list($bin, $warum) = sm_vz_binary();
    $akt = sm_vz_paket('current');
    $verf = sm_vz_paket('available');
    if ($bin !== '') {
        list(, $v) = sm_sh(escapeshellarg($bin) . ' --version | head -1');
        $v = trim($v);
        $txt = $bin . ($v !== '' ? ' - ' . $v : '');
        if ($akt !== '') {
            $txt .= ' (' . sprintf(sm_t('DIAG.PAKET'), $akt)
                  . (($verf !== '' && $verf !== $akt)
                     ? ', ' . sprintf(sm_t('DIAG.PAKET_VERFUEGBAR'), $verf) : '') . ')';
        }
        $add(sm_t('DIAG.PROGRAMM'), ($verf !== '' && $akt !== '' && $verf !== $akt) ? 'warn' : 'ok', $txt);
    } else {
        $add(sm_t('DIAG.PROGRAMM'), 'fail', $warum);
    }

    // 3 - Prozess
    $pid = sm_vz_running();
    if ($pid !== '') {
        $add(sm_t('DIAG.PROZESS'), 'ok', sprintf(sm_t('DIAG.LAEUFT_PID'), $pid));
    } else {
        $add(sm_t('DIAG.PROZESS'), 'fail', sprintf(sm_t('DIAG.KEIN_PROZESS'), $p['vzconf']));
    }

    // 4 - Konfigurationsdatei
    if (!is_readable($p['vzconf'])) {
        $add(sm_t('DIAG.KONFIG'), 'fail', sprintf(sm_t('DIAG.KONFIG_FEHLT'), $p['vzconf']));
    } else {
        $txt = (string) @file_get_contents($p['vzconf']);
        $n = substr_count($txt, '"identifier"');
        if (strpos($txt, '"meters"') === false) {
            $add(sm_t('DIAG.KONFIG'), 'fail', sprintf(sm_t('DIAG.KONFIG_OHNE_METERS'), $p['vzconf']));
        } elseif (!$n) {
            $add(sm_t('DIAG.KONFIG'), 'fail', sm_t('DIAG.KONFIG_OHNE_KANAL'));
        } elseif (preg_match('/"interval"\s*:\s*-/', $txt)) {
            $add(sm_t('DIAG.KONFIG'), 'warn', sprintf(sm_t('DIAG.KONFIG_INTERVAL'), $n));
        } else {
            $add(sm_t('DIAG.KONFIG'), 'ok', sprintf(sm_t('DIAG.KONFIG_OK'), $n));
        }
    }

    // 5 - Lesekopf
    $dev = (string) $cfg['device'];
    if ($dev === '') {
        $add(sm_t('DIAG.LESEKOPF'), 'fail', sm_t('DIAG.KEIN_GERAET'));
    } elseif (!file_exists($dev)) {
        $add(sm_t('DIAG.LESEKOPF'), 'fail', sprintf(sm_t('DIAG.GERAET_FEHLT'), $dev));
    } elseif (!is_readable($dev)) {
        $add(sm_t('DIAG.LESEKOPF'), 'fail', sprintf(sm_t('DIAG.GERAET_RECHTE'), $dev));
    } else {
        $ziel = is_link($dev) ? readlink($dev) : '';
        $add(sm_t('DIAG.LESEKOPF'), 'ok', $dev . ($ziel !== '' ? ' -> ' . $ziel : ''));
    }

    // 6 - Belegung der Schnittstelle (der haeufigste Fallstrick)
    $busy = sm_vz_device_busy($dev);
    if (sm_legacy_aktiv()) {
        $add(sm_t('DIAG.BELEGT'), 'fail', sm_t('DIAG.BELEGT_LEGACY'));
    } elseif ($busy !== '') {
        $add(sm_t('DIAG.BELEGT'), 'warn', sprintf(sm_t('DIAG.BELEGT_FREMD'), $dev, $busy));
    } else {
        $add(sm_t('DIAG.BELEGT'), 'ok', sprintf(sm_t('DIAG.BELEGT_FREI'), $dev));
    }

    // 7 und 8 - HTTP-Schnittstelle und Messwerte
    $port = (int) $cfg['httpport'];
    list(, $roh) = sm_sh('curl -s -m 5 http://127.0.0.1:' . $port . '/');
    if (strpos($roh, 'vzlogger') === false) {
        $add(sm_t('DIAG.HTTP'), 'fail', sprintf(sm_t('DIAG.HTTP_STUMM'), $port));
        $add(sm_t('DIAG.MESSWERTE'), 'fail', sm_t('DIAG.KEINE_WERTE'));
        return $d;
    }

    $cnt = substr_count($roh, '"uuid"');
    preg_match_all('/"last"\s*:\s*(\d+)/', $roh, $m);
    $letzte = isset($m[1]) ? array_map('floatval', $m[1]) : array();
    $max = $letzte ? max($letzte) : 0;
    $gut = 0;
    foreach ($letzte as $x) { if ($x > 0) { $gut++; } }

    $add(sm_t('DIAG.HTTP'), 'ok', sprintf(sm_t('DIAG.HTTP_OK'), $port, $cnt));

    if (!$cnt) {
        $add(sm_t('DIAG.MESSWERTE'), 'fail', sm_t('DIAG.KEINE_KANAELE'));
    } elseif (!$gut) {
        $zeitfehler = false;
        // Rueckwaerts mit fseek statt exec("tail") - kein Prozessstart.
        $t = implode("\n", sm_log_ende($p['vzlog'], 60));
        if ($t !== '') {
            $zeitfehler = (strpos($t, 'timestamp before 1990') !== false);
        }
        if ($zeitfehler) {
            $add(sm_t('DIAG.MESSWERTE'), 'fail', sm_t('DIAG.ZEITSTEMPEL')
                . (!$cfg['localtime'] ? ' ' . sm_t('DIAG.ZEITSTEMPEL_ZUSATZ') : ''));
        } else {
            $add(sm_t('DIAG.MESSWERTE'), 'fail', sprintf(sm_t('DIAG.ALLE_NULL'), $cnt));
        }
    } else {
        // vzlogger meldet je nach Fassung Sekunden oder Millisekunden.
        $alter = ($max > 1000000000000)
            ? (int) (time() - $max / 1000)
            : (int) (time() - $max);
        $add(sm_t('DIAG.MESSWERTE'), ($alter > 300 ? 'warn' : 'ok'),
            sprintf(sm_t('DIAG.WERTE_OK'), $gut, $cnt, $alter));
    }

    return $d;
}

/**
 * Dieselbe Diagnose, aber hoechstens alle fuenf Minuten neu.
 *
 * Sie startet rund zwanzig Prozesse, darunter apt-cache policy und ein curl
 * mit fuenf Sekunden Zeitgrenze. Ohne Puffer wartet der Anwender genau dann
 * fuenf Sekunden vor jeder Seite, wenn etwas nicht stimmt.
 *
 * Der Puffer wird verworfen, sobald jemand am Dienst oder an der
 * Konfiguration dreht (sm_cache_verwerfen()).
 */
function sm_diagnose_gepuffert($cfg, $alter = 300)
{
    $p = sm_paths();
    $datei = $p['datadir'] . '/diagnose.json';
    if (is_readable($datei) && (time() - (int) filemtime($datei)) < $alter) {
        $d = json_decode((string) @file_get_contents($datei), true);
        if (is_array($d) && isset($d['zeilen']) && is_array($d['zeilen'])) {
            return array($d['zeilen'], (int) (time() - (int) filemtime($datei)));
        }
    }
    $zeilen = sm_diagnose($cfg);
    sm_json_schreiben($datei, array('zeilen' => $zeilen));
    return array($zeilen, 0);
}

/** Die Farbe zu einem Diagnosezustand. */
function sm_farbe($zustand)
{
    $f = array('ok' => '#1a7f1a', 'warn' => '#b06000', 'fail' => '#b00000');
    return isset($f[$zustand]) ? $f[$zustand] : '';
}

function sm_zeichen($zustand)
{
    $z = array('ok' => '&#10004;', 'warn' => '!', 'fail' => '&#10008;');
    return isset($z[$zustand]) ? $z[$zustand] : '';
}

function sm_block($text)
{
    return '<div class="sm-log">' . sm_e($text) . '</div>';
}

/* ==================================================================
 * Selbstpruefung
 *
 * Jede Zeile liefert array(Bezeichnung, ok, Text).
 *   ok = 1  Haken
 *   ok = 0  Kreuz
 *   ok = 2  Strich - NICHT feststellbar. Ein Strich ist ausdruecklich kein
 *           Haken; am Ende der Liste steht, wie viele Striche darin stehen.
 *
 * Jede Zeile, die die eigenen Dateien liest, nennt die Zahl der angesehenen
 * Stellen. Eine Null ist dann kein "in Ordnung", sondern der Hinweis, dass
 * nichts gemessen wurde.
 * ================================================================== */

/** Der Aufruf des eigenen Endpunkts - gepuffert, drei Ausgaenge. */
function sm_endpunkt_probe($alter = 300)
{
    $p = sm_paths();
    $datei = $p['datadir'] . '/endpunkt.json';
    if (is_readable($datei) && (time() - (int) filemtime($datei)) < $alter) {
        $d = json_decode((string) @file_get_contents($datei), true);
        if (is_array($d) && isset($d['ok'])) {
            return array((int) $d['ok'], (string) $d['text']);
        }
    }
    $cfg = sm_legacy_read();
    $token = trim((string) $cfg['TOKEN']);
    // Serverseitig ist 127.0.0.1 die RICHTIGE Adresse. Das widerspricht der
    // Regel "ein Knopf auf 127.0.0.1 kann nie funktionieren" nicht - die
    // gilt fuer einen Verweis, den ein Mensch im Browser anklickt.
    $url = 'http://127.0.0.1/plugins/' . $p['plugin'] . '/index.php?selftest=1'
         . ($token !== '' ? '&token=' . rawurlencode($token) : '');
    $erg = array(2, sprintf(sm_t('PRUEF.EP_NICHT_MESSBAR'), $url));
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        $antwort = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($antwort === false || $code === 0) {
            // Ein Webserver, der gerade diese Seite baut, kann sich unter
            // Umstaenden nicht selbst aufrufen. Das ist kein Kreuz.
            $erg = array(2, sprintf(sm_t('PRUEF.EP_KEINE_ANTWORT'), $url));
        } elseif ($code === 200 && strpos((string) $antwort, 'SELFTEST;OK=1') !== false) {
            $erg = array(1, sprintf(sm_t('PRUEF.EP_OK'), $code));
        } else {
            $erg = array(0, sprintf(sm_t('PRUEF.EP_FALSCH'), $code,
                substr(trim(preg_replace('/\s+/', ' ', (string) $antwort)), 0, 80)));
        }
    }
    sm_json_schreiben($datei, array('ok' => $erg[0], 'text' => $erg[1]));
    return $erg;
}

/**
 * Passen Reiterliste, Leiste und Bereiche zusammen?
 *
 * Verglichen werden die MENGEN, nicht die Anzahlen: ein Bereich mit einem
 * anderen NAMEN laesst die Zahlen stimmen und fuehrt den Reiter trotzdem
 * ins Leere.
 */
function sm_reiter_probe(array $ids, $datei)
{
    $s = (string) @file_get_contents($datei);
    if ($s === '') {
        // Wer eine Datei liest, um darin etwas NICHT zu finden, prueft
        // zuerst, dass er ueberhaupt etwas gelesen hat.
        return array(2, sprintf(sm_t('PRUEF.NICHT_LESBAR'), basename($datei)));
    }
    $soll = array();
    foreach ($ids as $i) { $soll[] = 'tab-' . $i; }

    $bereiche = array();
    if (preg_match_all('/class="sm-pane[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $s, $y)) {
        $bereiche = $y[1];
    }
    $leiste = array();
    if (preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $s, $y2)) {
        $leiste = array_values(array_unique($y2[1]));
    }
    $fehlt = array_values(array_diff($soll, $bereiche));
    $ueber = array_values(array_diff($bereiche, $soll));
    if ($fehlt) {
        return array(0, sprintf(sm_t('PRUEF.REITER_OHNE_FLAECHE'), implode(', ', $fehlt)));
    }
    if ($ueber) {
        return array(0, sprintf(sm_t('PRUEF.REITER_UNERREICHBAR'), implode(', ', $ueber)));
    }
    $fehlt2 = array_values(array_diff($soll, $leiste));
    if ($fehlt2) {
        return array(0, sprintf(sm_t('PRUEF.REITER_OHNE_LEISTE'), implode(', ', $fehlt2)));
    }
    return array(1, sprintf(sm_t('PRUEF.REITER_OK'), count($soll)));
}

/** Setzt der Server das sm-active - an der Leiste UND an den Flaechen? */
function sm_smactive_probe(array $ids, $datei)
{
    $s = (string) @file_get_contents($datei);
    if ($s === '') {
        return array(2, sprintf(sm_t('PRUEF.NICHT_LESBAR'), basename($datei)));
    }
    $anzahl = count($ids);
    $leiste   = preg_match_all('/class="sm-tab<\?php echo \$sm_tab ===/', $s);
    $bereiche = preg_match_all('/class="sm-pane<\?php echo \$sm_tab ===/', $s);
    if ($anzahl > 0 && $leiste >= 1 && $bereiche >= $anzahl) {
        return array(1, sprintf(sm_t('PRUEF.SMACTIVE_OK'), $anzahl, $bereiche));
    }
    return array(0, sprintf(sm_t('PRUEF.SMACTIVE_FEHLT'), $leiste, $bereiche, $anzahl));
}

/** Traegt jedes Formular das Merkmal gegen fremde Absender? */
function sm_formularprobe($datei)
{
    $s = (string) @file_get_contents($datei);
    if ($s === '') {
        return array(2, sprintf(sm_t('PRUEF.NICHT_LESBAR'), basename($datei)));
    }
    $gesamt = 0; $ohne = 0;
    if (preg_match_all('/<form\s/', $s, $y, PREG_OFFSET_CAPTURE)) {
        foreach ($y[0] as $f) {
            $gesamt++;
            $ende = strpos($s, '</form>', $f[1]);
            $blk  = substr($s, $f[1], ($ende === false ? 600 : $ende - $f[1]));
            if (strpos($blk, 'sm_fmt()') === false && strpos($blk, 'name="fmt"') === false) {
                $ohne++;
            }
        }
    }
    // Die leere Menge zuerst: "alle 0 von 0 sind in Ordnung" ist kein Haken.
    if ($gesamt === 0) {
        return array(2, sm_t('PRUEF.FORM_KEINS'));
    }
    if ($ohne > 0) {
        return array(0, sprintf(sm_t('PRUEF.FORM_OHNE'), $ohne, $gesamt));
    }
    return array(1, sprintf(sm_t('PRUEF.FORM_OK'), $gesamt));
}

/**
 * Nennt die Themenliste, was der Dienst wirklich sendet?
 *
 * Der Dienst wird WIRKLICH gefragt: bin/fetch_vzlogger.pl --themen gibt
 * aus, welche Feldnamen es fuer die eingestellten Kanaele bilden wuerde.
 * Ein Vergleich zweier Listen, die beide aus PHP stammen, misst nichts -
 * die zweite Sprache ist der ganze Punkt.
 */
function sm_themen_probe()
{
    $p = sm_paths();
    $skript = $p['bin'] . '/fetch_vzlogger.pl';
    if (!is_readable($skript)) {
        return array(2, sprintf(sm_t('PRUEF.NICHT_LESBAR'), 'fetch_vzlogger.pl'));
    }
    list($rc, $aus) = sm_sh('perl ' . escapeshellarg($skript) . ' --themen');
    $dienst = array();
    foreach (preg_split('/\R/', $aus) as $z) {
        $z = trim($z);
        if ($z !== '' && strpos($z, ' ') === false) {
            $dienst[] = $z;
        }
    }
    if ($rc !== 0 || !$dienst) {
        return array(2, sprintf(sm_t('PRUEF.THEMEN_STUMM'), $rc));
    }
    $meine = sm_vz_felder(sm_vz_read());
    sort($meine);
    sort($dienst);
    $nur_hier   = array_values(array_diff($meine, $dienst));
    $nur_dienst = array_values(array_diff($dienst, $meine));
    if ($nur_hier || $nur_dienst) {
        return array(0, sprintf(sm_t('PRUEF.THEMEN_ABWEICHUNG'),
            count($meine), count($dienst),
            implode(', ', array_slice(array_merge($nur_hier, $nur_dienst), 0, 6))));
    }
    return array(1, sprintf(sm_t('PRUEF.THEMEN_OK'), count($meine)));
}

/**
 * Ist jedes Suchmuster eindeutig?
 *
 * Loxone sucht woertlich und nimmt den ersten Treffer. Die Feldnamen stehen
 * zwischen zwei Doppelpunkten, koennen also nicht ineinander stecken - die
 * Zeile misst es trotzdem nach und faengt damit das sechsundsechzigste Feld
 * ab.
 */
function sm_suchmuster_probe()
{
    $cfg = sm_vz_read();
    $felder = sm_vz_felder($cfg);
    foreach (sm_koepfe() as $k) {
        foreach (sm_werte($k['ABSCHNITT']) as $pv) {
            $felder[] = $pv[0];
        }
    }
    $felder = array_values(array_unique($felder));
    if (!$felder) {
        return array(2, sm_t('PRUEF.MUSTER_LEER'));
    }
    $doppelt = array();
    foreach ($felder as $a) {
        foreach ($felder as $b) {
            if ($a !== $b && substr($b, -strlen($a)) === $a) {
                $doppelt[] = $a . ' in ' . $b;
            }
        }
    }
    if ($doppelt) {
        return array(0, sprintf(sm_t('PRUEF.MUSTER_KOLLISION'),
            count($doppelt), implode(', ', array_slice($doppelt, 0, 4))));
    }
    return array(1, sprintf(sm_t('PRUEF.MUSTER_OK'), count($felder)));
}

/**
 * Steht ein Suchtext fest in einer Sprachdatei?
 *
 * Was ein Anwender abschreiben soll und was der Code erzeugt, muss
 * dieselbe Quelle haben. Ein Suchmuster in einer .ini erreicht keine
 * Vereinheitlichung - genau daran ist AnkerSolix 0.9.7 vorbeigelaufen.
 */
function sm_ini_muster_probe()
{
    $p = sm_paths();
    $pfad = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
    if (!is_dir($pfad)) {
        $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
    }
    $gesehen = 0;
    $funde = array();
    foreach (array('language_de.ini', 'language_en.ini') as $n) {
        $f = $pfad . '/' . $n;
        if (!is_readable($f)) {
            continue;
        }
        $gesehen++;
        foreach (preg_split('/\R/', (string) @file_get_contents($f)) as $nr => $z) {
            // Der Backslash wird nicht als Literal geschrieben - ein
            // Erklaertext, der die gesuchte Form enthaelt, waere selbst ein
            // Fund. chr(92) ist der Rueckwaertsschraegstrich.
            if (strpos($z, chr(92) . 'i') !== false && strpos($z, '=') !== false) {
                $funde[] = $n . ':' . ($nr + 1);
            }
        }
    }
    if ($gesehen === 0) {
        return array(2, sm_t('PRUEF.INI_KEINE'));
    }
    if ($funde) {
        return array(0, sprintf(sm_t('PRUEF.INI_MUSTER'),
            count($funde), implode(', ', array_slice($funde, 0, 4))));
    }
    return array(1, sprintf(sm_t('PRUEF.INI_OK'), $gesehen));
}

/** Sind die erzeugbaren Vorlagen wohlgeformt? */
function sm_vorlagen_probe()
{
    if (!function_exists('simplexml_load_string')) {
        return array(2, sm_t('PRUEF.XML_KEIN_PARSER'));
    }
    $geprueft = 0;
    $kaputt = array();
    $lang = array();
    foreach (array('sm_vorlage', 'sm_vorlage_legacy') as $f) {
        list($name, $xml) = $f();
        if ($name === '') {
            continue;
        }
        $geprueft++;
        $vorher = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        if ($sx === false) {
            $kaputt[] = $name;
            continue;
        }
        if (!preg_match('//u', $xml)) {
            $kaputt[] = $name . ' (UTF-8)';
        }
        // Der Kommentar eines Befehls wird in Loxone Config zum
        // ANZEIGENAMEN. Was ueber vierzig Zeichen liegt, ist ein Satz und
        // kein Name.
        if (preg_match_all('/<VirtualInHttpCmd Title="[^"]*" Comment="([^"]*)"/', $xml, $m)) {
            foreach ($m[1] as $c) {
                if (strlen($c) > 40) { $lang[] = substr($c, 0, 30) . '...'; }
            }
        }
    }
    if ($geprueft === 0) {
        return array(2, sm_t('PRUEF.XML_KEINE'));
    }
    if ($kaputt) {
        return array(0, sprintf(sm_t('PRUEF.XML_KAPUTT'), implode(', ', $kaputt)));
    }
    if ($lang) {
        return array(0, sprintf(sm_t('PRUEF.XML_KOMMENTAR'), count($lang), $lang[0]));
    }
    return array(1, sprintf(sm_t('PRUEF.XML_OK'), $geprueft));
}

/** Ist die Konfiguration heil - und vollstaendig? */
function sm_konfig_probe()
{
    $p = sm_paths();
    if (!is_file($p['legacy'])) {
        return array(0, sprintf(sm_t('PRUEF.CFG_FEHLT'), $p['legacy']));
    }
    if (filesize($p['legacy']) === 0) {
        // Datei da, aber leer: das ist kein "leerer Zustand", sondern der
        // Abbruch eines Schreibvorgangs.
        return array(0, sm_t('PRUEF.CFG_LEER'));
    }
    $lage = sm_cfg_lage();
    if ($lage['fehlend']) {
        return array(0, sprintf(sm_t('PRUEF.CFG_FEHLEND'),
            count($lage['fehlend']), $lage['anzahl'], implode(', ', $lage['fehlend'])));
    }
    if ($lage['fremd']) {
        // Fremdes wird GENANNT, nicht geloescht.
        return array(0, sprintf(sm_t('PRUEF.CFG_FREMD'), implode(', ', $lage['fremd'])));
    }
    return array(1, sprintf(sm_t('PRUEF.CFG_OK'), $lage['anzahl']));
}

/** Kennt der Katalog seine Felder - und liest der Dienst dieselbe Datei? */
function sm_katalog_probe()
{
    $datei = sm_felder_datei();
    if ($datei === '') {
        return array(0, sm_t('PRUEF.KAT_FEHLT'));
    }
    $k = sm_katalog();
    if (!$k['felder']) {
        return array(0, sprintf(sm_t('PRUEF.KAT_LEER'), $datei));
    }
    // Erst nachsehen, dann lesen. Das @ haette die Warnung zwar
    // verschluckt, aber ein Fehlerbehandler, der auch unterdrueckte
    // Meldungen mitschreibt - der Vorlauf der Pruefkette tut das -, traegt
    // sie trotzdem ein. Eine Warnung, die bei jedem Lauf steht und nie
    // etwas bedeutet, bringt jedem bei, den Befundblock zu ueberblaettern.
    $p = sm_paths();
    $perl = '';
    foreach (array($p['bin'] . '/fetch_vzlogger.pl',
                   dirname(dirname(dirname(__FILE__))) . '/bin/fetch_vzlogger.pl') as $sm_kandidat) {
        if (is_readable($sm_kandidat)) {
            $perl = (string) @file_get_contents($sm_kandidat);
            if ($perl !== '') {
                break;
            }
        }
    }
    if ($perl === '') {
        return array(2, sprintf(sm_t('PRUEF.NICHT_LESBAR'), 'fetch_vzlogger.pl'));
    }
    // Der Dienst darf keine eigene Zuordnungstabelle mehr fuehren - sonst
    // gibt es sie zweimal, und zwei Listen halten sich nicht von selbst
    // gleich.
    if (preg_match('/my\s+%SM_NAME\s*=\s*\(/', $perl)) {
        return array(0, sm_t('PRUEF.KAT_ZWEITE_LISTE'));
    }
    if (strpos($perl, 'sm_felder.json') === false) {
        return array(0, sm_t('PRUEF.KAT_DIENST_LIEST_NICHT'));
    }
    return array(1, sprintf(sm_t('PRUEF.KAT_OK'),
        count($k['felder']), count($k['obis'])));
}

/** Der Herzschlag: arbeitet der Dienst noch? */
function sm_herzschlag_probe()
{
    $z = sm_zaehler_lesen();
    if ($z < 0) {
        return array(2, sm_t('PRUEF.HERZ_NIE'));
    }
    $f = sm_paths()['zaehler'];
    $alter = is_file($f) ? (time() - (int) filemtime($f)) : -1;
    $grenze = sm_alter_grenze();
    if ($grenze <= 0) {
        // Ueber eine Betriebsart, die absichtlich nur beim Start liest, wird
        // kein Altersurteil gefaellt.
        return array(2, sprintf(sm_t('PRUEF.HERZ_NUR_START'), $z, $alter));
    }
    if ($alter > $grenze) {
        return array(0, sprintf(sm_t('PRUEF.HERZ_ALT'), $z, $alter, $grenze));
    }
    return array(1, sprintf(sm_t('PRUEF.HERZ_OK'), $z, $alter));
}

/**
 * Die ganze Selbstpruefung.
 *
 * $ids sind die Reiterkennungen, $datei ist die eigene index.php - beide
 * kommen als ARGUMENT, nicht aus einem zweiten preg_match: sie stehen zur
 * Laufzeit ohnehin da, und sie ein zweites Mal aus dem Quelltext zu lesen
 * waere eine zweite Wahrheit.
 */
function sm_selbsttest(array $ids, $datei)
{
    $z = array();
    $add = function ($bez, $paar) use (&$z) {
        $z[] = array($bez, (int) $paar[0], (string) $paar[1]);
    };

    $add(sm_t('PRUEF.Z_DIENST'), sm_dienst_probe());
    $add(sm_t('PRUEF.Z_HERZ'), sm_herzschlag_probe());
    $add(sm_t('PRUEF.Z_CRON'), sm_cron_lage());
    $add(sm_t('PRUEF.Z_KONFIG'), sm_konfig_probe());
    $add(sm_t('PRUEF.Z_KATALOG'), sm_katalog_probe());
    $add(sm_t('PRUEF.Z_THEMEN'), sm_themen_probe());
    $add(sm_t('PRUEF.Z_MUSTER'), sm_suchmuster_probe());
    $add(sm_t('PRUEF.Z_INI'), sm_ini_muster_probe());
    $add(sm_t('PRUEF.Z_XML'), sm_vorlagen_probe());
    $add(sm_t('PRUEF.Z_REITER'), sm_reiter_probe($ids, $datei));
    $add(sm_t('PRUEF.Z_SMACTIVE'), sm_smactive_probe($ids, $datei));
    $add(sm_t('PRUEF.Z_FORM'), sm_formularprobe($datei));
    $add(sm_t('PRUEF.Z_GATEWAY'), sm_gateway_probe());
    $add(sm_t('PRUEF.Z_ENDPUNKT'), sm_endpunkt_probe());
    return $z;
}

/** Laeuft ueberhaupt ein Leser - und nur einer? */
function sm_dienst_probe()
{
    $vz = sm_vz_read();
    $lg = sm_legacy_aktiv();
    if ($vz['enabled'] && $lg) {
        // Zwei Leser an einer seriellen Schnittstelle. Bis 2.3.14 liess sich
        // dieser Zustand herstellen: der Schutz sass nur im Legacy-Handler.
        return array(0, sm_t('PRUEF.DIENST_BEIDE'));
    }
    if (!$vz['enabled'] && !$lg) {
        return array(2, sm_t('PRUEF.DIENST_KEINER'));
    }
    if ($vz['enabled']) {
        $pid = sm_vz_running();
        return $pid !== ''
            ? array(1, sprintf(sm_t('PRUEF.DIENST_VZ'), $pid))
            : array(0, sm_t('PRUEF.DIENST_VZ_TOT'));
    }
    $pid = sm_logger_pid();
    // Der klassische Leser ist ein Cron-Lauf, kein Dauerlaeufer: dass er
    // gerade nicht laeuft, ist der Normalfall und kein Befund.
    return array(1, $pid !== null
        ? sprintf(sm_t('PRUEF.DIENST_LG_LAEUFT'), $pid)
        : sm_t('PRUEF.DIENST_LG'));
}

/** Zustand und Fassung des MQTT-Gateways. */
function sm_gateway_probe()
{
    $cfg = sm_legacy_read();
    if ($cfg['SENDMQTT'] !== '1') {
        return array(2, sm_t('PRUEF.GW_AUS'));
    }
    $g = sm_mqtt_gateway_info();
    if ($g === null) {
        return array(2, sm_t('PRUEF.GW_UNLESBAR'));
    }
    if (!$g['autostart']) {
        return array(0, sm_t('MQ.W_AUTOSTART'));
    }
    if ($g['udpin'] <= 0) {
        return array(0, sm_t('PRUEF.GW_KEIN_UDPIN'));
    }
    // BERICHTIGT AM 26.08.2026. Hier stand ein HAKEN mit dem Satz
    // "Gateway-Fassung liess sich nicht feststellen" darin - ein gruenes
    // Haekchen ueber einer Zeile, die selbst sagt, dass sie nichts weiss.
    // Fehlt die Fassung, ist hier nichts zu messen, und ein Strich sagt
    // genau das. Der Unterschied zaehlt: an der Fassung haengt, ob der
    // Anwender ein Abo eintragen muss (V1) oder nicht (V2).
    $f = (int) $g['fassung'];
    if ($f <= 0) {
        return array(2, sm_t('PRUEF.GW_UNBEKANNT'));
    }
    return array(1, sprintf(sm_t('PRUEF.GW_OK'), (string) $f, $g['udpin']));
}

/* ==================================================================
 * Knoepfe des Reiters Test
 * ================================================================== */

/** Eine Aktion des Reiters Test. */
function sm_test_ausfuehren($was, $cfg)
{
    $p = sm_paths();
    switch ($was) {

        case 'http':
            $port = (int) $cfg['httpport'];
            list(, $roh) = sm_sh('curl -s -m 5 http://127.0.0.1:' . $port . '/');
            return array(sm_t('TEST.K_HTTP'),
                sm_block(trim($roh) !== '' ? $roh
                    : sprintf(sm_t('TEST.HTTP_STUMM'), $port)));

        case 'umgebung':
            $z = array();
            $unbekannt = sm_t('TEST.UNBEKANNT');
            $z[] = sprintf('%-18s: %s', sm_t('TEST.U_PHP'), PHP_VERSION);
            list(, $arch) = sm_sh('uname -m');
            $z[] = sprintf('%-18s: %s', sm_t('TEST.U_ARCH'), trim($arch));
            list($bin, $warum) = sm_vz_binary();
            $z[] = sprintf('%-18s: %s', 'vzlogger',
                           $bin !== '' ? $bin : sm_t('TEST.U_NICHT_LAUFFAEHIG'));
            if ($bin === '') { $z[] = sprintf('  %-16s: %s', sm_t('TEST.U_GRUND'), $warum); }
            $z[] = sprintf('%-18s: %s', sm_t('TEST.U_PAKET_INST'), sm_vz_paket('current') ?: $unbekannt);
            $z[] = sprintf('%-18s: %s', sm_t('TEST.U_PAKET_VERF'), sm_vz_paket('available') ?: $unbekannt);
            $z[] = '';
            $koepfe = sm_lesekoepfe();
            $z[] = sprintf('%-18s: %s', sm_t('TEST.U_KOEPFE'),
                           $koepfe ? implode(', ', $koepfe) : sm_t('TEST.U_KEINER'));
            $z[] = '';
            foreach (array('vzjson' => 'vzlogger.json', 'vzconf' => 'vzlogger.conf',
                           'legacy' => 'smartmeter.cfg') as $k => $name) {
                $z[] = sprintf('%-18s: %s', $name, is_readable($p[$k])
                    ? sprintf(sm_t('TEST.U_VORHANDEN'),
                              number_format(filesize($p[$k]) / 1024, 1, ',', '.'))
                    : sm_t('TEST.U_NICHT_VORHANDEN'));
            }
            $kat = sm_felder_datei();
            $k = sm_katalog();
            $z[] = sprintf('%-18s: %s', 'sm_felder.json', $kat !== ''
                ? sprintf(sm_t('TEST.U_KATALOG'), count($k['felder']), count($k['obis']))
                : sm_t('TEST.U_NICHT_VORHANDEN'));
            return array(sm_t('TEST.K_UMGEBUNG'), sm_block(implode("\n", $z)));

        case 'legacy':
            $l = sm_legacy_read();
            $z = array();
            foreach ($l as $k => $v) {
                // Weder das Zugriffstoken noch das Formularmerkmal gehoeren
                // in eine Anzeige, die jemand abfotografiert und in ein Forum
                // haengt. Die LAENGE beantwortet die Frage genauso.
                if ($k === 'TOKEN' || $k === 'FORMKEY') {
                    $v = ($v === '') ? sm_t('TEST.LG_NICHT_GESETZT')
                                     : sprintf(sm_t('TEST.LG_GESETZT'), strlen($v));
                }
                $z[] = sprintf('%-12s: %s', $k, $v);
            }
            $z[] = '';
            $z[] = $l['READ'] === '1' ? sm_t('TEST.LG_AN') : sm_t('TEST.LG_AUS');
            return array(sm_t('TEST.K_LEGACY'), sm_block(implode("\n", $z)));

        case 'mitschnitt':
            $z = array();
            $gesehen = 0;
            foreach (sm_koepfe() as $k) {
                list($text, $gross) = sm_mitschnitt($k['ABSCHNITT']);
                if ($gross === 0) {
                    continue;
                }
                $gesehen++;
                $z[] = '--- ' . $k['ABSCHNITT'] . ' (' . $gross . ' Byte) ---';
                $z[] = $text;
                $z[] = '';
            }
            if ($gesehen === 0) {
                return array(sm_t('TEST.K_MITSCHNITT'),
                    '<p class="sm-small">' . sm_t('TEST.MITSCHNITT_LEER') . '</p>');
            }
            return array(sm_t('TEST.K_MITSCHNITT'),
                '<div class="sm-alert sm-warn">' . sm_t('TEST.MITSCHNITT_WARNUNG') . '</div>'
                . sm_block(implode("\n", $z)));
    }
    return array(sm_t('TEST.UNBEKANNTE_PRUEFUNG'),
        '<p class="sm-small">' . sm_t('TEST.GIBT_ES_NICHT') . '</p>');
}
