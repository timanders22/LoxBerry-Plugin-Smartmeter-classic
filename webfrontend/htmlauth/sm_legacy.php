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
/** So lange darf eine Abfrage von Hand hoechstens dauern (Sekunden). */
define('SM_ABFRAGE_GRENZE', 25);

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

/**
 * Ein Zufallstoken fuer den unangemeldeten Endpunkt.
 *
 * Ohne mehrdeutige Zeichen (0/O, 1/l), weil man es abtippt.
 */
function sm_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
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
        // Nur diese vier Eintraege sind uebersetzbar - die uebrigen 41 sind
        // Geraetebezeichnungen und bleiben in jeder Sprache gleich.
        '0'                     => sm_t('PROFIL.OFFEN'),
        'manual'                => sm_t('PROFIL.MANUELL'),
        'genericd0'             => sm_t('PROFIL.D0'),
        'genericsml'            => sm_t('PROFIL.SML'),
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
        'M'  => array('',              sm_t('TAKT.DAUERHAFT')),
        '1'  => array('cron.01min',    sm_t('TAKT.MIN01')),
        '3'  => array('cron.03min',    sm_t('TAKT.MIN03')),
        '5'  => array('cron.05min',    sm_t('TAKT.MIN05')),
        '10' => array('cron.10min',    sm_t('TAKT.MIN10')),
        '15' => array('cron.15min',    sm_t('TAKT.MIN15')),
        '30' => array('cron.30min',    sm_t('TAKT.MIN30')),
        '60' => array('cron.hourly',   sm_t('TAKT.STUENDLICH')),
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
    // Der Rueckfall ist der ERMITTELTE Pluginordner, nicht "smartmeter": So
    // heisst das Originalplugin, das neben diesem installiert sein kann. Und
    // diese Funktion raeumt gleich unten "tabula rasa" alle Verknuepfungen
    // dieses Namens weg - mit dem falschen Namen haette sie die Cron-Eintraege
    // des FREMDEN Plugins geloescht.
    $name = sm_cfg_get(sm_cfg_read(), 'MAIN', 'SCRIPTNAME', $p['plugin']);
    $basis = $p['home'] . '/system/cron/';

    // 1. Tabula rasa
    foreach (sm_cron_ordner() as $ordner) {
        $ziel = $basis . $ordner . '/' . $name;
        if (is_link($ziel) || file_exists($ziel)) {
            @unlink($ziel);
        }
    }
    if (!$lesen) {
        return array(true, sm_t('CRON.ABGESCHALTET'));
    }

    $takte = sm_takte();
    if (!isset($takte[$takt])) {
        return array(false, sm_t('CRON.UNBEKANNT'));
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
        return array(false, sprintf(sm_t('CRON.DATEI_FEHLT'), $quelle));
    }
    if (!is_dir(dirname($ziel))) {
        return array(false, sprintf(sm_t('CRON.ORDNER_FEHLT'), dirname($ziel)));
    }
    if (!@symlink($quelle, $ziel)) {
        return array(false, sprintf(sm_t('CRON.LINK_FEHLER'), $ziel));
    }
    return array(true, sprintf(sm_t('CRON.GESETZT'), strip_tags($takte[$takt][1])));
}

/** Wo liegt derzeit eine Verknuepfung? Liefert den Takt oder ''. */
function sm_cron_ist()
{
    $p = sm_paths();
    // Gegenstueck zu sm_cron_setzen(): derselbe Rueckfall, sonst suchte das
    // Lesen an einer anderen Stelle als das Schreiben.
    $name = sm_cfg_get(sm_cfg_read(), 'MAIN', 'SCRIPTNAME', $p['plugin']);
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
        return sprintf(sm_t('LG.FETCH_FEHLT'), $skript);
    }
    /* Mit Zeitgrenze aufrufen.
     *
     * sm_sh() benutzt exec() und blockiert, bis der Aufruf fertig ist. Dahinter
     * haengt sm_logger.pl, das je nach Zaehlerprofil bis zu 120 Sekunden
     * lauscht. Ein Webserver bricht die Anfrage lange vorher ab - der Benutzer
     * sieht dann einen 504 statt einer Auskunft.
     *
     * Der eigentliche Grund fuer die langen Laufzeiten war ein Fehler in der
     * Leseschleife (siehe READ_SERIAL in sm_logger.pl): sie endete nie von
     * selbst, jede Abfrage dauerte deshalb IMMER die volle Zeitgrenze. Das ist
     * behoben. Die Grenze hier bleibt trotzdem - ein Lesekopf, der gar nicht
     * mehr antwortet, darf die Oberflaeche nicht mitnehmen.
     *
     * timeout gehoert zu den coreutils und ist auf jedem Debian vorhanden;
     * fehlt es wider Erwarten, wird ohne Grenze aufgerufen statt gar nicht.
     */
    $vor = '';
    list($rc_t, ) = sm_sh('command -v timeout');
    if ($rc_t === 0) {
        $vor = 'timeout -k 5 ' . SM_ABFRAGE_GRENZE . ' ';
    }
    list($rc, $aus) = sm_sh($vor . 'php ' . escapeshellarg($skript) . ' --verbose');
    // 124 ist der Rueckgabewert, mit dem timeout einen Abbruch meldet.
    if ($rc === 124) {
        $aus = sprintf(sm_t('LG.ABFRAGE_ZEITGRENZE'), SM_ABFRAGE_GRENZE)
             . (trim($aus) !== '' ? "\n\n" . $aus : '');
    }
    return trim($aus) !== '' ? $aus : '(' . sm_t('ALLG.KEINE_AUSGABE') . ')';
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
