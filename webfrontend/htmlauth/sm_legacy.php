<?php
/**
 * Smartmeter classic - der Legacy-Leser
 *
 * Die Zaehlerprofile und die Abfrage per Cron. Der eigentliche Leser bleibt
 * bewusst Perl: bin/sm_logger.pl spricht ueber Device::SerialPort mit dem
 * Zaehler und wechselt bei D0-Zaehlern mitten in der Sitzung die Baudrate.
 * Dafuer gibt es in PHP kein verlaessliches Gegenstueck, und 41 Profile
 * liessen sich ohne 41 Zaehler auch nicht nachpruefen.
 *
 * Diese Datei kuemmert sich um alles davor und danach: Einstellungen,
 * Zaehlerprofile, Abfragetakt.
 */

require_once __DIR__ . '/sm_lib.php';

/* ==================================================================
 * smartmeter.cfg - abschnittsweise lesen und schreiben
 * ================================================================== */

/**
 * Die ganze Datei als array[Abschnitt][Schluessel] = Wert.
 *
 * Nicht mit parse_ini_file: die Datei stammt von Config::Simple und darf
 * Werte ohne Anfuehrungszeichen enthalten, die PHPs INI-Leser anders
 * auslegen wuerde (etwa "on", "off", "none").
 */
function sm_cfg_read()
{
    $datei = sm_paths()['legacy'];
    $alles = array();
    if (!is_readable($datei)) {
        return $alles;
    }
    $abschnitt = 'MAIN';
    foreach ((array) @file($datei, FILE_IGNORE_NEW_LINES) as $z) {
        $z = trim($z);
        if ($z === '' || $z[0] === ';' || $z[0] === '#') {
            continue;
        }
        if ($z[0] === '[' && substr($z, -1) === ']') {
            $abschnitt = substr($z, 1, -1);
            if (!isset($alles[$abschnitt])) { $alles[$abschnitt] = array(); }
            continue;
        }
        $pos = strpos($z, '=');
        if ($pos === false) {
            continue;
        }
        $k = trim(substr($z, 0, $pos));
        $v = trim(substr($z, $pos + 1));
        $alles[$abschnitt][$k] = $v;
    }
    return $alles;
}

/** Die ganze Datei zurueckschreiben. */
function sm_cfg_write($alles)
{
    $datei = sm_paths()['legacy'];
    $z = "; Smartmeter classic\n; Geschrieben von der Oberflaeche.\n\n";
    // MAIN zuerst - so war die Datei schon immer aufgebaut.
    $reihenfolge = array_merge(array('MAIN'),
        array_diff(array_keys($alles), array('MAIN')));
    foreach ($reihenfolge as $abschnitt) {
        if (!isset($alles[$abschnitt]) || !is_array($alles[$abschnitt])) {
            continue;
        }
        $z .= '[' . $abschnitt . "]\n";
        foreach ($alles[$abschnitt] as $k => $v) {
            // Zeilenumbrueche wuerden die Datei zerlegen.
            $v = str_replace(array("\r", "\n"), '', (string) $v);
            $z .= $k . '=' . $v . "\n";
        }
        $z .= "\n";
    }
    @mkdir(dirname($datei), 0775, true);
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $z) === false) {
        return false;
    }
    return @rename($tmp, $datei);
}

/** Einzelne Werte in einem Abschnitt setzen. */
function sm_cfg_set($abschnitt, $paare)
{
    $alles = sm_cfg_read();
    if (!isset($alles[$abschnitt])) {
        $alles[$abschnitt] = array();
    }
    foreach ($paare as $k => $v) {
        $alles[$abschnitt][$k] = $v;
    }
    return sm_cfg_write($alles);
}

function sm_cfg_get($alles, $abschnitt, $schluessel, $vorgabe = '')
{
    return (isset($alles[$abschnitt][$schluessel]) && $alles[$abschnitt][$schluessel] !== '')
        ? $alles[$abschnitt][$schluessel] : $vorgabe;
}

/* ==================================================================
 * Lesekoepfe
 * ================================================================== */

/** Die Felder, die ein Lesekopf in der Konfiguration fuehrt. */
function sm_kopf_felder()
{
    return array('NAME', 'SERIAL', 'DEVICE', 'METER', 'PROTOCOL',
                 'STARTBAUDRATE', 'BAUDRATE', 'TIMEOUT', 'DELAY',
                 'HANDSHAKE', 'DATABITS', 'STOPBITS', 'PARITY', 'CRC');
}

/**
 * Fuer jeden angesteckten Lesekopf einen Abschnitt anlegen, falls er fehlt.
 * Liefert true, wenn etwas geschrieben wurde.
 */
function sm_koepfe_anlegen()
{
    $alles = sm_cfg_read();
    $neu = false;
    foreach (sm_lesekoepfe() as $pfad) {
        $serial = basename($pfad);
        if (isset($alles[$serial]['DEVICE']) && $alles[$serial]['DEVICE'] !== '') {
            continue;
        }
        $alles[$serial] = array(
            'NAME' => $serial, 'SERIAL' => $serial, 'DEVICE' => $pfad,
            'METER' => '0', 'PROTOCOL' => '', 'STARTBAUDRATE' => '',
            'BAUDRATE' => '', 'TIMEOUT' => '', 'DELAY' => '',
            'HANDSHAKE' => '', 'DATABITS' => '', 'STOPBITS' => '',
            'PARITY' => '', 'CRC' => '',
        );
        $neu = true;
    }
    if ($neu) {
        sm_cfg_write($alles);
    }
    return $neu;
}

/** Die eingerichteten Lesekoepfe mit ihren Werten. */
function sm_koepfe()
{
    $alles = sm_cfg_read();
    $koepfe = array();
    foreach ($alles as $abschnitt => $werte) {
        if ($abschnitt === 'MAIN' || !isset($werte['DEVICE'])) {
            continue;
        }
        $werte['ABSCHNITT'] = $abschnitt;
        $werte['ANGESTECKT'] = file_exists($werte['DEVICE']);
        $koepfe[] = $werte;
    }
    return $koepfe;
}

/* ==================================================================
 * Zaehlerprofile
 * ================================================================== */

/**
 * Die Profile, die bin/sm_logger.pl kennt.
 *
 * Reihenfolge und Bezeichnungen wie in der bisherigen Oberflaeche.
 */
function sm_profile()
{
    return array(
        '0'                     => 'noch nicht festgelegt',
        'manual'                => 'von Hand einstellen',
        'genericd0'             => 'Allgemeines D0-Protokoll',
        'genericsml'            => 'Allgemeines SML-Protokoll',
        'apatornorax3dsml'      => 'Apator Norax 3D (SML)',
        'apatorpicusehz060sml'  => 'Apator Picus eHZ.060.D (SML)',
        'bauerbsmqd36ad0'       => 'Bauer BSM-QD36A (D0)',
        'dzgdvs7410sml'         => 'DZG DVS7410 (SML)',
        'dzgdvs7420sml'         => 'DZG DVS7420.1 (SML)',
        'easymeteresy5q3dad0'   => 'Easymeter ESY5Q3DA (D0)',
        'easymeterq3asml'       => 'Easymeter Q3A (SML)',
        'efrsgmc2sml'           => 'EFR SGM-C2 (SML)',
        'efrsgmc4sml'           => 'EFR SGM-C4 (SML)',
        'efrsgmddsml'           => 'EFR SGM-DD / Digimeto (SML)',
        'elsteras3000d0'        => 'Elster AS3000 (D0)',
        'emhed300sml'           => 'EMH ED300(S) (SML)',
        'emhehzksml'            => 'EMH eHZ-K / eHZ-IW8E2A (SML)',
        'hagerehz363sml'        => 'Hager eHZ363 (SML)',
        'holleydtz541sml'       => 'Holley DTS541/DTZ541 (SML)',
        'iskra173d0'            => 'Iskra MT173 (D0)',
        'iskra173sml'           => 'Iskra MT173 (SML)',
        'iskra174d0'            => 'Iskra MT174 (D0)',
        'iskra174sml'           => 'Iskra MT174 (SML)',
        'iskra175d0'            => 'Iskra MT175 (D0)',
        'iskra175sml'           => 'Iskra MT175 (SML)',
        'iskra382d0'            => 'Iskra MT382 (D0)',
        'iskra681sml'           => 'Iskra MT681 (SML)',
        'iskra691sml'           => 'Iskra MT691 (SML)',
        'itronace3000type260d0' => 'Itron ACE3000 Type 260 (D0)',
        'landisgyre220sml'      => 'Landis &amp; Gyr E220 (SML)',
        'landisgyre320d0'       => 'Landis &amp; Gyr E320 (D0)',
        'landisgyre350d0'       => 'Landis &amp; Gyr E350 (D0)',
        'landisgyret550d0'      => 'Landis &amp; Gyr T550 (D0)',
        'logarexlk13bdd0'       => 'Logarex LK13BD (D0)',
        'logarexlk13be8030d0'   => 'Logarex LK13BE8030x9 (D0)',
        'pafal20ec3grd0'        => 'Pafal 20ec3gr (D0)',
        'sagemcomsmartybzsml'   => 'Sagemcom SMARTY BZ-PLUS (SML)',
        'sagemcomt210d0'        => 'Sagemcom T210-D (D0)',
        'sagemcomt211d0'        => 'Sagemcom S211/T211 (D0)',
        'sagemcomt211d0f'       => 'Sagemcom S211/T211 Flandern (D0)',
        'siemenstd3511d0'       => 'Siemens TD-3511 (D0)',
        'siemensuh50do'         => 'Siemens UH50 (D0)',
        'zpagh305sml'           => 'ZPA GH305 (SML)',
    );
}

/* ==================================================================
 * Abfragetakt (Cron)
 * ================================================================== */

/** Die moeglichen Takte: Wert => (Ordner, Beschriftung) */
function sm_takte()
{
    return array(
        'M'  => array('',              'dauerhaft &ndash; der Leser laeuft st&auml;ndig'),
        '1'  => array('cron.01min',    'jede Minute'),
        '3'  => array('cron.03min',    'alle 3 Minuten'),
        '5'  => array('cron.05min',    'alle 5 Minuten'),
        '10' => array('cron.10min',    'alle 10 Minuten'),
        '15' => array('cron.15min',    'alle 15 Minuten'),
        '30' => array('cron.30min',    'alle 30 Minuten'),
        '60' => array('cron.hourly',   'st&uuml;ndlich'),
    );
}

/** Alle Ordner, in denen eine Verknuepfung liegen koennte. */
function sm_cron_ordner()
{
    $o = array('cron.reboot');
    foreach (sm_takte() as $t) {
        if ($t[0] !== '') { $o[] = $t[0]; }
    }
    return $o;
}

/**
 * Den Abfragetakt setzen.
 *
 * Zuerst werden **alle** Verknuepfungen entfernt, dann genau eine gesetzt.
 * Die alte Perl-Fassung listete je Zweig die zu loeschenden Ordner einzeln
 * auf - und im Zweig fuer 10 Minuten standen dort "cron.1min", "cron.3min"
 * und "cron.5min" ohne fuehrende Null. Diese Ordner gibt es nicht. Wer von
 * 1, 3 oder 5 Minuten auf 10 wechselte, behielt die alte Verknuepfung: der
 * Zaehler wurde danach doppelt abgefragt.
 */
function sm_cron_setzen($lesen, $takt)
{
    $p = sm_paths();
    $name = sm_cfg_get(sm_cfg_read(), 'MAIN', 'SCRIPTNAME', 'smartmeter');
    $basis = $p['home'] . '/system/cron/';

    // 1. Tabula rasa
    foreach (sm_cron_ordner() as $ordner) {
        $ziel = $basis . $ordner . '/' . $name;
        if (is_link($ziel) || file_exists($ziel)) {
            @unlink($ziel);
        }
    }
    if (!$lesen) {
        return array(true, 'Der Legacy-Leser ist abgeschaltet, alle Cron-Eintr&auml;ge entfernt.');
    }

    $takte = sm_takte();
    if (!isset($takte[$takt])) {
        return array(false, 'Unbekannter Abfragetakt.');
    }

    // 2. Genau eine Verknuepfung setzen
    if ($takt === 'M') {
        $quelle = $p['bin'] . '/reboot_cron_runner.sh';
        $ziel   = $basis . 'cron.reboot/' . $name;
    } else {
        $quelle = $p['bin'] . '/fetch.php';
        $ziel   = $basis . $takte[$takt][0] . '/' . $name;
    }
    if (!file_exists($quelle)) {
        return array(false, 'Die Datei ' . $quelle . ' fehlt.');
    }
    if (!is_dir(dirname($ziel))) {
        return array(false, 'Den Ordner ' . dirname($ziel) . ' gibt es nicht.');
    }
    if (!@symlink($quelle, $ziel)) {
        return array(false, 'Die Verkn&uuml;pfung ' . $ziel . ' liess sich nicht anlegen.');
    }
    return array(true, 'Abfragetakt gesetzt: ' . strip_tags($takte[$takt][1]) . '.');
}

/** Wo liegt derzeit eine Verknuepfung? Liefert den Takt oder ''. */
function sm_cron_ist()
{
    $p = sm_paths();
    $name = sm_cfg_get(sm_cfg_read(), 'MAIN', 'SCRIPTNAME', 'smartmeter');
    $basis = $p['home'] . '/system/cron/';
    foreach (sm_takte() as $wert => $t) {
        $ordner = ($wert === 'M') ? 'cron.reboot' : $t[0];
        if ($ordner === '') { continue; }
        if (file_exists($basis . $ordner . '/' . $name)
            || is_link($basis . $ordner . '/' . $name)) {
            return $wert;
        }
    }
    return '';
}

/** Laeuft der Legacy-Leser gerade? */
function sm_logger_pid()
{
    list(, $roh) = sm_sh('pgrep -f sm_logger.pl');
    foreach (preg_split('/\s+/', trim($roh)) as $pid) {
        if ($pid !== '' && ctype_digit($pid) && is_dir('/proc/' . $pid)) {
            return $pid;
        }
    }
    return null;
}

/** Den Zwischenspeicher leeren. */
function sm_cache_leeren()
{
    $p = sm_paths();
    $n = 0;
    foreach ((array) glob($p['shm'] . '/*') as $f) {
        if (is_file($f) && @unlink($f)) { $n++; }
    }
    return $n;
}

/** Eine Abfrage von Hand anstossen. */
function sm_manuell_abfragen()
{
    $p = sm_paths();
    $skript = $p['bin'] . '/fetch.php';
    if (!is_readable($skript)) {
        return 'fetch.php wurde nicht gefunden (' . $skript . ').';
    }
    list(, $aus) = sm_sh('php ' . escapeshellarg($skript) . ' --verbose');
    return trim($aus) !== '' ? $aus : '(keine Ausgabe)';
}

/**
 * Die zuletzt gelesenen Werte eines Lesekopfs.
 *
 * Zeilenform der .data-Datei ist dreiteilig:  SERIAL:Schluessel:Wert
 * Der Wert selbst darf Doppelpunkte enthalten (etwa in Last_Update), also
 * wird nur zweimal getrennt.
 */
function sm_werte($serial)
{
    $datei = sm_paths()['shm'] . '/' . $serial . '.data';
    if (!is_readable($datei)) {
        return array();
    }
    $paare = array();
    foreach ((array) @file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $z) {
        if ($z === '' || $z[0] === '#') {
            continue;
        }
        $teile = explode(':', $z, 3);
        if (count($teile) < 3 || $teile[0] !== $serial) {
            continue;
        }
        $paare[] = array($teile[1], $teile[2]);
    }
    return $paare;
}
