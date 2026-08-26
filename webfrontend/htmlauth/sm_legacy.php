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
 * Diese Datei kuemmert sich um alles davor und danach: Lesekoepfe,
 * Zaehlerprofile, Abfragetakt, Suchlauf.
 *
 * Die Konfigurationsdatei selbst liest und schreibt sm_lib.php
 * (sm_cfg_read/sm_cfg_write) - dort steht auch die einzige Vorgabeliste.
 */

require_once __DIR__ . '/sm_lib.php';

/** So lange darf eine Abfrage von Hand hoechstens dauern (Sekunden). */
define('SM_ABFRAGE_GRENZE', 25);

/** So lange darf ein einzelner Versuch des Suchlaufs dauern (Sekunden). */
define('SM_SUCHE_GRENZE', 20);

/**
 * Ein Zufallstoken fuer den unangemeldeten Endpunkt.
 *
 * Der Name bleibt, weil er an mehreren Stellen steht; gebildet wird es von
 * sm_zufall() in sm_lib.php - ein zweiter Zufallsgenerator waere eine
 * zweite Wahrheit.
 */
function sm_token_erzeugen($laenge = 24)
{
    return sm_zufall($laenge);
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
    $namen = array();
    foreach (sm_lesekoepfe() as $pfad) {
        $serial = basename($pfad);
        if (isset($alles[$serial]['DEVICE']) && $alles[$serial]['DEVICE'] !== '') {
            continue;
        }
        // Die Feldliste steht in sm_kopf_felder() - hier stand sie ein
        // zweites Mal ausgeschrieben. Kommt ein Feld hinzu und wird nur eine
        // der beiden Stellen nachgezogen, bekommt ein neu angesteckter
        // Lesekopf einen Abschnitt ohne dieses Feld, und niemand sieht warum.
        $kopf = array();
        foreach (sm_kopf_felder() as $sm_feld) {
            $kopf[$sm_feld] = '';
        }
        $kopf['NAME']   = $serial;
        $kopf['SERIAL'] = $serial;
        $kopf['DEVICE'] = $pfad;
        $kopf['METER']  = '0';
        $alles[$serial] = $kopf;
        $namen[] = $serial;
        $neu = true;
    }
    if ($neu) {
        sm_cfg_write($alles);
        sm_log('Neuer Lesekopf eingetragen: ' . implode(', ', $namen));
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

/**
 * Die moeglichen Takte: Wert => (Ordner, Beschriftung)
 *
 * BERICHTIGT 26.08.2026. Der Eintrag 'M' hiess "dauerhaft - der Leser
 * laeuft staendig", und der Hilfetext daneben sagte, er lasse den Leser
 * staendig mitlaufen. Beides war falsch. Die Kette dahinter ist drei
 * Glieder lang und endet nach EINEM Durchlauf:
 *
 *   sm_cron_setzen('M')   verknuepft bin/reboot_cron_runner.sh in cron.reboot
 *   reboot_cron_runner.sh sleep 15, dann EIN Aufruf von fetch.php
 *   fetch.php             laeuft einmal ueber alle Lesekoepfe, dann exit(0)
 *   sm_logger.pl          endet nach einem Durchlauf ebenfalls mit exit
 *
 * Der Takt liefert also einen Zaehlerstand je Neustart des LoxBerry und
 * danach nie wieder - und in Loxone behaelt der virtuelle Eingang seinen
 * letzten Wert, es sieht also aus wie ein ruhiger Tag. Der Eintrag heisst
 * deshalb jetzt, was er tut.
 */
function sm_takte()
{
    return array(
        'M'  => array('',              sm_t('TAKT.START')),
        '1'  => array('cron.01min',    sm_t('TAKT.MIN01')),
        '3'  => array('cron.03min',    sm_t('TAKT.MIN03')),
        '5'  => array('cron.05min',    sm_t('TAKT.MIN05')),
        '10' => array('cron.10min',    sm_t('TAKT.MIN10')),
        '15' => array('cron.15min',    sm_t('TAKT.MIN15')),
        '30' => array('cron.30min',    sm_t('TAKT.MIN30')),
        '60' => array('cron.hourly',   sm_t('TAKT.STUENDLICH')),
    );
}

/**
 * Ab wann gilt ein Messwert als zu alt?
 *
 * Eine Grenze, zwei Verbraucher - der Endpunkt, der OK und ALTER an Loxone
 * liefert, und der Reiter Test, der dieselbe Frage beantwortet. Gerechnet
 * wird sie in bin/sm_gemein.php, weil der Endpunkt in einem anderen Baum
 * liegt und diese Datei nicht einbinden kann.
 *
 * Und wenn der gemeinsame Vorlauf fehlt, gilt KEINE Ersatzgrenze: wer die
 * Grenze nicht kennt, faellt kein Altersurteil. Eine Untergrenze waere hier
 * kein Fail safe, sondern ein Zuschlagen, sobald der echte Wert groesser
 * ist.
 */
function sm_alter_grenze()
{
    if (!function_exists('smg_alter_grenze')) {
        return 0;
    }
    $cfg = sm_legacy_read();
    $vz  = sm_vz_read();
    return smg_alter_grenze($cfg['CRON'], !empty($vz['enabled']));
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
        sm_log('Klassischer Leser abgeschaltet, Cron-Eintraege entfernt.');
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
    sm_log('Abfragetakt gesetzt: ' . $takt . ' (' . $ziel . ')');
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

/**
 * Liegt der eigene Cron-Eintrag da, und ist er eine DATEI?
 *
 * Ein Plugin muss seinen eigenen Cron-Eintrag pruefen: es kann vollstaendig
 * installiert dastehen, alle Selbstpruefungen gruen, und trotzdem nichts
 * tun, weil der Eintrag an der falschen Stelle liegt. Die Oberflaeche
 * meldet dann nichts, weil sie gar nicht hinsieht.
 *
 * Rueckgabe: array(zustand, Text). zustand ist 1 (Haken), 0 (Kreuz) oder
 * 2 (Strich - nicht feststellbar).
 */
function sm_cron_lage()
{
    $p = sm_paths();
    $cfg = sm_legacy_read();
    $vz  = sm_vz_read();
    $basis = $p['home'] . '/system/cron/';
    if ($p['home'] === '' || !is_dir($basis)) {
        return array(2, sm_t('CRON.LAGE_UNBEKANNT'));
    }
    $name = sm_cfg_get(sm_cfg_read(), 'MAIN', 'SCRIPTNAME', $p['plugin']);

    // Der vzLogger-Weg haengt an cron/crontab, nicht an einer Verknuepfung.
    // Der klassische Weg haengt an genau einer Verknuepfung.
    $gefunden = array();
    $verzeichnisse = array();
    foreach (sm_cron_ordner() as $ordner) {
        $ziel = $basis . $ordner . '/' . $name;
        if (is_dir($ziel) && !is_link($ziel)) {
            $verzeichnisse[] = $ordner;
        } elseif (is_link($ziel) || is_file($ziel)) {
            $gefunden[] = $ordner;
        }
    }
    if ($verzeichnisse) {
        // cron/cron.XXmin ist eine DATEI, kein Verzeichnis - LoxBerry fuehrt
        // in diesen Ordnern nur Dateien aus.
        return array(0, sprintf(sm_t('CRON.LAGE_VERZEICHNIS'), implode(', ', $verzeichnisse)));
    }
    if ($cfg['READ'] !== '1') {
        if ($gefunden) {
            return array(0, sprintf(sm_t('CRON.LAGE_UEBERZAEHLIG'), implode(', ', $gefunden)));
        }
        return array(1, sm_t('CRON.LAGE_AUS'));
    }
    if (!$gefunden) {
        return array(0, sm_t('CRON.LAGE_FEHLT'));
    }
    if (count($gefunden) > 1) {
        return array(0, sprintf(sm_t('CRON.LAGE_MEHRFACH'), implode(', ', $gefunden)));
    }
    $soll = ($cfg['CRON'] === 'M') ? 'cron.reboot' : sm_takte()[$cfg['CRON']][0];
    if ($gefunden[0] !== $soll) {
        return array(0, sprintf(sm_t('CRON.LAGE_FALSCH'), $gefunden[0], $soll));
    }
    return array(1, sprintf(sm_t('CRON.LAGE_OK'), $gefunden[0]));
}

/** Laeuft der Legacy-Leser gerade? */
function sm_logger_pid()
{
    list(, $roh) = sm_sh('pgrep -f sm_logger.pl');
    foreach (preg_split('/\s+/', trim($roh)) as $pid) {
        if ($pid !== '' && preg_match('/^[0-9]+$/', $pid) && is_dir('/proc/' . $pid)) {
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
    sm_log('Zwischenspeicher geleert, ' . $n . ' Datei(en) entfernt.');
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

/* ==================================================================
 * Suchlauf: welches Zaehlerprofil passt?
 *
 * Der Anwender steht sonst vor einer Liste mit 45 Eintraegen und muss
 * raten. Raet er falsch, laeuft die Abfrage bis zur Zeitgrenze des Profils
 * und liefert nichts - ohne einen Hinweis, was als Naechstes zu tun waere.
 *
 * Gebaut wird nichts Neues: bin/sm_logger.pl nimmt ueber GetOptions jeden
 * Parameter einzeln entgegen, und bin/fetch.php reicht genau diese zehn
 * Werte schon durch, wenn das Profil "manual" gewaehlt ist.
 *
 * Der Suchlauf SCHLAEGT VOR, er uebernimmt nicht: der erste Klick zeigt,
 * der zweite schreibt. Und was mehrdeutig bleibt - zwei Wege liefern beide
 * etwas - bleibt offen und wird als offen benannt.
 * ================================================================== */

/**
 * Die Wege, die der Suchlauf probiert.
 *
 * Vier allgemeine Faelle, keine Geraeteprofile: die 41 Modelle
 * unterscheiden sich fast nur in Zeitgrenzen und Wartezeiten, und ein
 * Suchlauf ueber 41 Profile mit je bis zu 120 Sekunden waere kein
 * Suchlauf, sondern ein Nachmittag.
 */
function sm_suche_wege()
{
    return array(
        array('bez' => 'SUCHE.W_SML9600', 'protocol' => 'genericsml',
              'startbaudrate' => 9600, 'baudrate' => 9600,
              'databits' => 8, 'parity' => 'none'),
        array('bez' => 'SUCHE.W_SML300', 'protocol' => 'genericsml',
              'startbaudrate' => 300, 'baudrate' => 300,
              'databits' => 7, 'parity' => 'even'),
        array('bez' => 'SUCHE.W_D09600', 'protocol' => 'genericd0',
              'startbaudrate' => 9600, 'baudrate' => 9600,
              'databits' => 8, 'parity' => 'none'),
        array('bez' => 'SUCHE.W_D0300', 'protocol' => 'genericd0',
              'startbaudrate' => 300, 'baudrate' => 9600,
              'databits' => 7, 'parity' => 'even'),
    );
}

/**
 * Einen Lesekopf durchprobieren.
 *
 * Rueckgabe: array(Zeilen fuer die Anzeige, Vorschlag oder '').
 *
 * Er laeuft NICHT, solange ein Leser eingeschaltet ist: zwei Prozesse
 * koennen sich eine serielle Schnittstelle nicht teilen, und das Ergebnis
 * waere Bruchstueckwerk aus beiden.
 */
function sm_suchlauf($device)
{
    $p = sm_paths();
    $zeilen = array();
    $vorschlag = '';
    $treffer = array();

    if ($device === '' || !file_exists($device)) {
        return array(array(sprintf(sm_t('SUCHE.KEIN_GERAET'), sm_e($device))), '');
    }
    $vz = sm_vz_read();
    if ($vz['enabled'] || sm_legacy_aktiv()) {
        return array(array(sm_t('SUCHE.LESER_LAEUFT')), '');
    }
    $belegt = sm_vz_device_busy($device);
    if ($belegt !== '') {
        return array(array(sprintf(sm_t('SUCHE.BELEGT'), sm_e($device), sm_e($belegt))), '');
    }
    $logger = $p['bin'] . '/sm_logger.pl';
    if (!is_readable($logger)) {
        return array(array(sprintf(sm_t('LG.FETCH_FEHLT'), $logger)), '');
    }

    $vor = '';
    list($rc_t, ) = sm_sh('command -v timeout');
    if ($rc_t === 0) {
        $vor = 'timeout -k 5 ' . SM_SUCHE_GRENZE . ' ';
    }
    $serial = basename($device);
    $datendatei = $p['shm'] . '/' . $serial . '.data';

    foreach (sm_suche_wege() as $w) {
        // Der vorige Stand darf das Ergebnis nicht faelschen.
        @unlink($datendatei);
        $b = escapeshellarg($logger)
           . ' --device ' . escapeshellarg($device)
           . ' --protocol ' . escapeshellarg($w['protocol'])
           . ' --startbaudrate ' . (int) $w['startbaudrate']
           . ' --baudrate ' . (int) $w['baudrate']
           . ' --databits ' . (int) $w['databits']
           . ' --parity ' . escapeshellarg($w['parity'])
           . ' --timeout ' . (int) (SM_SUCHE_GRENZE - 5);
        $start = microtime(true);
        sm_sh($vor . 'perl ' . $b);
        $dauer = round(microtime(true) - $start, 1);

        $werte = sm_werte($serial);
        // Last_Update und Last_UpdateLoxEpoche schreibt der Leser IMMER -
        // sie sind kein Beleg dafuer, dass ein Telegramm ankam. Gezaehlt
        // werden nur die gemessenen Groessen.
        $echte = array();
        foreach ($werte as $pv) {
            if (strncmp($pv[0], 'Last_Update', 11) !== 0) {
                $echte[] = $pv[0];
            }
        }
        if ($echte) {
            $treffer[] = $w;
            $zeilen[] = sprintf(sm_t('SUCHE.TREFFER'), sm_t($w['bez']), $dauer,
                count($echte), implode(', ', array_slice($echte, 0, 6)));
        } else {
            $zeilen[] = sprintf(sm_t('SUCHE.NICHTS'), sm_t($w['bez']), $dauer);
        }
    }
    @unlink($datendatei);

    if (count($treffer) === 1) {
        $vorschlag = $treffer[0]['protocol'];
        $zeilen[] = '';
        $zeilen[] = sprintf(sm_t('SUCHE.VORSCHLAG'), sm_t($treffer[0]['bez']));
    } elseif (count($treffer) > 1) {
        // Mehrdeutig bleibt mehrdeutig. Wer hier einen der beiden waehlt,
        // waehlt ihn fuer den Anwender, ohne es zu wissen.
        $namen = array();
        foreach ($treffer as $t) { $namen[] = sm_t($t['bez']); }
        $zeilen[] = '';
        $zeilen[] = sprintf(sm_t('SUCHE.MEHRDEUTIG'), implode(', ', $namen));
    } else {
        $zeilen[] = '';
        $zeilen[] = sm_t('SUCHE.NICHTS_GEFUNDEN');
    }
    sm_log('Suchlauf an ' . $device . ': ' . count($treffer) . ' von '
         . count(sm_suche_wege()) . ' Wegen lieferten Werte.');
    return array($zeilen, $vorschlag);
}

/**
 * Der letzte Mitschnitt eines Lesekopfs.
 *
 * bin/sm_logger.pl schreibt bei jedem Lauf die rohen Bytes nach
 * <serial>.dump - bei SML als Hex. Die Oberflaeche hat ihn bis 2.3.14 nie
 * gezeigt, obwohl jede Fehlersuche, die ueber "es kommt nichts" hinausgeht,
 * genau daran haengt.
 *
 * Der Puffer darf bis 1 MiB wachsen; angezeigt wird ein Ausschnitt.
 */
function sm_mitschnitt($serial, $grenze = 4000)
{
    $datei = sm_paths()['shm'] . '/' . $serial . '.dump';
    if (!is_readable($datei)) {
        return array('', 0);
    }
    $gross = (int) filesize($datei);
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array('', $gross);
    }
    $text = (string) fread($fp, $grenze);
    fclose($fp);
    return array($text, $gross);
}
