<?php
/**
 * Smartmeter classic - Bedienoberflaeche
 *
 * Das Plugin kennt zwei Lesewege: vzLogger (modern) und den Legacy-Leser mit
 * Zaehlerprofilen. Beide teilen sich die serielle Schnittstelle, deshalb darf
 * immer nur einer eingeschaltet sein - beide Speicher-Handler weisen den
 * anderen Fall ab, und die Diagnose weist darauf hin.
 */

require_once 'loxberry_web.php';
require_once __DIR__ . '/sm_lib.php';
require_once __DIR__ . '/sm_test.php';
require_once __DIR__ . '/sm_legacy.php';

$sm_p       = sm_paths();
$sm_meldung = '';
$sm_fehler  = array();
$sm_hinweis = '';
$sm_notizen = array();

/* Die Konfiguration einmal vervollstaendigen.
 *
 * Ergaenzen heisst: beim Lesen tritt die Vorgabe ein, und "fehlt" ist von
 * "steht auf dem Vorgabewert" nicht zu unterscheiden. Vervollstaendigen
 * heisst: es steht danach in der Datei. Geschrieben wird nur, wenn wirklich
 * etwas fehlte - nicht bei jedem Aufruf. */
$sm_ergaenzt = sm_cfg_vervollstaendigen();

/* ---------------------------------------------------------------- *
 * Wachposten gegen fremde Absender
 *
 * htmlauth schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass
 * der Browser eines ANGEMELDETEN Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht; die Anmeldung geht dabei automatisch mit.
 *
 * Hier haengt daran mehr als anderswo: lox_token_neu macht jede Adresse im
 * Miniserver ungueltig, lox_token_weg oeffnet den Endpunkt fuer jedes Geraet
 * im Netz, und vz_install stoesst eine Paketinstallation an.
 *
 * EINE Pruefung, VOR allen Handlern und VOR der Reiterwahl. Einen einzelnen
 * Handler kann man beim Erweitern vergessen, einen Wachposten am Eingang
 * nicht. Faellt er durch, wird $_POST bis auf den aktiven Reiter geleert -
 * damit ist auch der naechste Handler mitgeschuetzt, den jemand ergaenzt.
 * ---------------------------------------------------------------- */
$sm_fmt_soll = sm_formtoken(true);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sm_mit = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    $sm_csrf_ok = ($sm_fmt_soll !== '') && hash_equals($sm_fmt_soll, $sm_mit);
    if (!$sm_csrf_ok) {
        $sm_fehler[] = ($sm_fmt_soll === '')
            ? sm_t('FEHLER.CSRF_KEIN_MERKMAL') : sm_t('FEHLER.CSRF');
        sm_log_wenn_neu('csrf', 'Ein Formular ohne gueltiges Merkmal wurde abgewiesen.', 'WARN');
        // Den aktiven Reiter behalten - der Anwender soll die Meldung dort
        // sehen, wo er war.
        $sm_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        if ($sm_behalten !== null) { $_POST['activetab'] = $sm_behalten; }
    }
}

/* EINE Quelle fuer Reihenfolge, Positivliste und Beschriftung.
 *
 * Die Liste steht AUSGESCHRIEBEN da, nicht als Rechnung aus kurzen
 * Schluesseln: hausstandard_pruefen.py sucht sie als Literal, und eine
 * erzeugte Liste macht die Spalte tab zu einem Strich - der sich beim
 * Ueberfliegen wie ein Haken einsammelt. Gemessen am 26.08.2026: die Spalte
 * stand vor diesem Umbau auf "-", die Reiter dieses Plugins prueften also
 * weder ein Werkzeug noch ein Selbsttest.
 *
 * Dass die Liste damit von der Leiste und den Flaechen abweichen KANN, ist
 * der Preis. Dagegen steht keine Hoffnung, sondern die Zeile
 * "Passen Reiterliste, Leiste und Flaechen zusammen?" im Reiter Test. */
$sm_reiter = array('tab-vzlogger', 'tab-legacy', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$sm_reiter_ids = array();
foreach ($sm_reiter as $sm_i) { $sm_reiter_ids[] = substr($sm_i, 4); }

// Der Reiter kommt entweder aus einem abgesendeten Formular (activetab) oder
// als Adresse - die Legacy-Seite verlinkt so hierher.
/* is_string vor der Wandlung. Ohne sie meldet "?tab[]=x" eine
 * PHP-Warnung ("Array to string conversion"), und activetab ist auch
 * dann erreichbar, wenn der Wachposten durchgefallen ist - er schreibt
 * genau dieses Feld absichtlich zurueck. */
$sm_wunsch = '';
if (isset($_POST['activetab']) && is_string($_POST['activetab'])) {
    $sm_wunsch = $_POST['activetab'];
} elseif (isset($_GET['tab']) && is_string($_GET['tab'])) {
    $sm_wunsch = 'tab-' . $_GET['tab'];
}
$sm_tab = in_array($sm_wunsch, $sm_reiter, true) ? $sm_wunsch : $sm_reiter[0];

$sm_cfg    = sm_vz_read();
$sm_legacy = sm_legacy_read();

/* ---------------------------------------------------------------- *
 * Downloads - jeder in einem eigenen Formular, damit er nicht am
 * Speichern haengt. Sie enden mit exit.
 * ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage'])
    && is_string($_POST['vorlage'])) {
    $sm_was = $_POST['vorlage'];
    list($sm_vname, $sm_vinhalt) = ($sm_was === 'legacy') ? sm_vorlage_legacy() : sm_vorlage();
    if ($sm_vname === '') {
        $sm_fehler[] = sm_t('LOX.VORLAGE_LEER');
        $sm_tab = 'tab-loxone';
    } else {
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $sm_vname . '"');
        header('Content-Length: ' . strlen($sm_vinhalt));
        echo $sm_vinhalt;
        exit;
    }
}

/* ---------------------------------------------------------------- *
 * Einstellungen sichern
 *
 * Zweck ist der UMZUG auf einen zweiten LoxBerry, nicht die Sicherung gegen
 * Verlust - dafuer gibt es die Zweitschrift aus preupgrade.sh. Die Datei
 * traegt das Zugriffstoken; ohne es stuenden nach dem Zurueckspielen alle
 * Felder richtig, und der Miniserver bekaeme weiterhin 403.
 * ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sichern'])) {
    $sm_txt = sm_sichern_text();
    sm_log('Einstellungen gesichert (Download).');
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="smartmeter-classic_einstellungen.txt"');
    header('Content-Length: ' . strlen($sm_txt));
    echo $sm_txt;
    exit;
}

/* ---------------------------------------------------------------- *
 * Einstellungen zurueckspielen
 *
 * Eine halb gueltige Datei ueberschreibt NICHTS, und alle Beanstandungen
 * werden auf einmal gemeldet. Wer nur die erste zeigt, schickt den Anwender
 * in eine Schleife aus je einem Fund pro Anlauf.
 * ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['laden'])) {
    // Die beiden Knoepfe stehen im Reiter vzLogger - dort soll die Antwort
    // auch erscheinen.
    $sm_tab = 'tab-vzlogger';
    if (!isset($_FILES['sicherung']) || !is_array($_FILES['sicherung'])
        || !isset($_FILES['sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['sicherung']['tmp_name'])) {
        $sm_fehler[] = sm_t('SICH.KEINE_DATEI');
    } elseif ((int) $_FILES['sicherung']['size'] > 65536) {
        // Obergrenze, bevor irgendetwas gelesen wird.
        $sm_fehler[] = sm_t('SICH.ZU_GROSS');
    } else {
        $sm_roh = (string) @file_get_contents($_FILES['sicherung']['tmp_name']);
        list($sm_neu, $sm_mangel, $sm_notizen) = sm_sichern_einlesen($sm_roh);
        if ($sm_neu === null) {
            $sm_fehler = array_merge($sm_fehler, $sm_mangel);
            $sm_hinweis = sm_t('SICH.ABGELEHNT');
        } else {
            list($sm_ok, $sm_hin) = sm_sichern_uebernehmen($sm_neu);
            $sm_notizen = array_merge($sm_notizen, $sm_hin);
            if (!$sm_ok) {
                $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN_TEIL'),
                    '<span class="sm-mono">smartmeter.cfg</span>');
            } else {
                $sm_cfg    = sm_vz_read();
                $sm_legacy = sm_legacy_read();
                // Den Dienst nachziehen UND sagen, was mit ihm geschehen ist.
                $sm_was = '';
                if ($sm_cfg['enabled']) {
                    $sm_h = sm_vz_restart($sm_cfg);
                    $sm_was = ($sm_h === '') ? sm_t('SICH.DIENST_NEU') : $sm_h;
                } else {
                    $sm_was = sm_t('SICH.DIENST_AUS');
                }
                list($sm_cok, $sm_ctext) = sm_cron_setzen($sm_legacy['READ'] === '1',
                                                          $sm_legacy['CRON']);
                /* $sm_cok wurde bis 2.4.2 nicht angesehen: der Fehlertext
                 * von sm_cron_setzen() landete unveraendert in der GRUENEN
                 * Kachel. Der Zwilling im Handler lg_speichern macht es
                 * richtig - zwei Aufrufstellen derselben Funktion, zwei
                 * Behandlungen. */
                $sm_meldung = sm_t('SICH.UEBERNOMMEN') . ' ' . $sm_was;
                if ($sm_cok) {
                    $sm_meldung .= ' ' . $sm_ctext;
                } else {
                    $sm_fehler[] = $sm_ctext;
                }
            }
        }
    }
}

$sm_test_titel = '';
$sm_test_text  = '';
$sm_installout = '';
$sm_suchzeilen = array();
$sm_suchvorschlag = '';

/* ---------------------------------------------------------------- *
 * Formulare
 * ---------------------------------------------------------------- */
if (isset($_POST['vz_speichern'])) {
    $neu = $sm_cfg;
    $neu['enabled'] = isset($_POST['vz_enabled']) ? 1 : 0;
    $neu['device']  = isset($_POST['vz_device']) ? trim((string) $_POST['vz_device']) : '';

    // Zwei Leser koennen sich eine serielle Schnittstelle nicht teilen.
    // Diese Pruefung sass bis 2.3.14 NUR im Legacy-Handler; wer hier
    // speicherte, waehrend der klassische Leser lief, bekam zwei Prozesse an
    // einem Geraet. Die Pruefung gehoert in BEIDE Handler.
    if ($neu['enabled'] && sm_legacy_aktiv()) {
        $sm_fehler[] = sm_t('FEHLER.BEIDE_LESER_VZ');
    }

    $prot = isset($_POST['vz_protocol']) ? (string) $_POST['vz_protocol'] : 'sml';
    $neu['protocol'] = ($prot === 'd0') ? 'd0' : 'sml';

    $baud = isset($_POST['vz_baudrate']) ? trim((string) $_POST['vz_baudrate']) : '';
    if (!preg_match('/^[0-9]+$/', $baud) || (int) $baud < 300 || (int) $baud > 921600) {
        $sm_fehler[] = sm_t('FEHLER.BAUDRATE');
    } else {
        $neu['baudrate'] = (int) $baud;
    }

    $par = isset($_POST['vz_parity']) ? (string) $_POST['vz_parity'] : '';
    if (!in_array($par, array('8n1', '7n1', '7e1', '8e1'), true)) {
        $sm_fehler[] = sm_t('FEHLER.RAHMUNG');
    } else {
        $neu['parity'] = $par;
    }

    $neu['localtime'] = (isset($_POST['vz_localtime']) && $_POST['vz_localtime'] === '0') ? 0 : 1;
    $neu['sendudp']   = isset($_POST['vz_sendudp']) ? 1 : 0;

    foreach (array('udpport' => 'vz_udpport', 'httpport' => 'vz_httpport') as $k => $feld) {
        $w = isset($_POST[$feld]) ? trim((string) $_POST[$feld]) : '';
        if (!preg_match('/^[0-9]+$/', $w) || (int) $w < 1 || (int) $w > 65535) {
            $sm_fehler[] = sprintf(sm_t('FEHLER.PORT'),
                                   $k === 'udpport' ? 'UDP' : 'HTTP');
        } else {
            $neu[$k] = (int) $w;
        }
    }

    // Zaehlernummer: erscheint im MQTT-Thema und im UDP-Satz. Nach der
    // Hausregel nicht hart filtern, nur Unbrauchbares entfernen.
    $ser = isset($_POST['vz_serial']) ? (string) $_POST['vz_serial'] : '';
    $ser = preg_replace('/[^A-Za-z0-9_\-]/', '', $ser);
    $neu['serial'] = ($ser !== '') ? $ser : 'vzlogger';

    // OBIS-Kanaele: einer je Zeile (oder durch Komma getrennt)
    $kanaele = array();
    foreach (preg_split('/[\r\n,]+/', isset($_POST['vz_channels']) ? (string) $_POST['vz_channels'] : '') as $c) {
        $c = trim($c);
        if ($c === '') { continue; }
        if (!preg_match('/^[\d\.:\-\*]+$/', $c)) {
            $sm_fehler[] = sprintf(sm_t('FEHLER.OBIS'),
                                   '<span class="sm-mono">' . sm_e($c) . '</span>');
            continue;
        }
        $kanaele[] = $c;
    }
    if (!$kanaele) {
        $kanaele = sm_vz_vorgaben()['channels'];
    }
    $neu['channels'] = $kanaele;
    $neu['uuids']    = sm_vz_uuids($kanaele);

    if (!$sm_fehler) {
        if (sm_vz_write($neu)) {
            $sm_cfg = sm_vz_read();
            sm_vz_conf_schreiben($sm_cfg);
            sm_log('vzLogger-Einstellungen gespeichert.');
            $sm_hinweis = sm_vz_restart($sm_cfg);
            $sm_meldung = ($sm_hinweis === '')
                        ? sm_t('MELD.VZ_GESPEICHERT_NEUSTART')
                        : sm_t('MELD.VZ_GESPEICHERT');
        } else {
            $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN_RECHTE'),
                                   '<span class="sm-mono">vzlogger.json</span>');
        }
    }
    $sm_tab = 'tab-vzlogger';
}

if (isset($_POST['vz_install'])) {
    $sm_installout = sm_vz_install();
    sm_cache_verwerfen();
    $sm_tab = 'tab-vzlogger';
}

if (isset($_POST['vz_neustart'])) {
    $sm_hinweis = sm_vz_restart($sm_cfg);
    $sm_meldung = ($sm_hinweis === '') ? sm_t('MELD.VZ_NEUSTART') : '';
    $sm_tab = 'tab-vzlogger';
}

if (isset($_POST['mq_speichern'])) {
    // Hausregel: Eingaben nicht hart filtern. Nur Steuerzeichen,
    // Anfuehrungszeichen und Leerraum entfernen - alles andere ist in einem
    // MQTT-Thema erlaubt.
    $t = isset($_POST['mq_topic']) ? (string) $_POST['mq_topic'] : '';
    $t = preg_replace('/[\x00-\x1f"\x27\s]/', '', $t);
    $t = trim($t, '/');
    if ($t === '') { $t = 'smartmeter'; }
    // Abschnittsbewusst schreiben: seit die Lesekoepfe eigene Abschnitte
    // haben, darf nicht mehr zeilenweise nach dem Schluessel gesucht werden.
    if (sm_cfg_set('MAIN', array('SENDMQTT' => isset($_POST['mq_an']) ? '1' : '0',
                                 'MQTTTOPIC' => $t))) {
        $sm_legacy = sm_legacy_read();
        sm_log('MQTT-Einstellungen gespeichert (Thema ' . $t . ').');
        $sm_meldung = sm_t('MELD.MQTT_GESPEICHERT');
    } else {
        $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN'),
                               '<span class="sm-mono">smartmeter.cfg</span>');
    }
    $sm_tab = 'tab-mqtt';
}

if (isset($_POST['test']) && is_string($_POST['test'])) {
    list($sm_test_titel, $sm_test_text) = sm_test_ausfuehren($_POST['test'], $sm_cfg);
    $sm_tab = 'tab-test';
}

/* ---------------------------------------------------------------- *
 * Legacy-Leser
 * ---------------------------------------------------------------- */
$sm_lg_ausgabe = '';

if (isset($_POST['lg_speichern'])) {
    $lesen = isset($_POST['lg_read']);
    $takt  = isset($_POST['lg_cron']) ? (string) $_POST['lg_cron'] : '5';
    if (!array_key_exists($takt, sm_takte())) {
        $sm_fehler[] = sm_t('FEHLER.TAKT');
    }
    $port = isset($_POST['lg_udpport']) ? trim((string) $_POST['lg_udpport']) : '';
    if (!preg_match('/^[0-9]+$/', $port) || (int) $port < 1 || (int) $port > 65535) {
        $sm_fehler[] = sm_t('FEHLER.LG_UDPPORT');
    }
    // Zwei Leser koennen sich eine serielle Schnittstelle nicht teilen.
    if ($lesen && $sm_cfg['enabled']) {
        $sm_fehler[] = sm_t('FEHLER.BEIDE_LESER');
    }

    if (!$sm_fehler) {
        $paare = array('READ' => $lesen ? '1' : '0', 'CRON' => $takt,
                       'SENDUDP' => isset($_POST['lg_sendudp']) ? '1' : '0',
                       'UDPPORT' => $port);
        $ok = sm_cfg_set('MAIN', $paare);

        // Je Lesekopf Bezeichnung und Profil
        $profile = sm_profile();
        foreach (sm_koepfe() as $k) {
            $s = $k['ABSCHNITT'];
            $neu = array();
            if (isset($_POST['lg_' . $s . '_name'])) {
                $neu['NAME'] = trim((string) $_POST['lg_' . $s . '_name']);
            }
            if (isset($_POST['lg_' . $s . '_meter'])) {
                $m = (string) $_POST['lg_' . $s . '_meter'];
                if (!array_key_exists($m, $profile)) {
                    $sm_fehler[] = sprintf(sm_t('FEHLER.PROFIL'), sm_e($s));
                } else {
                    $neu['METER'] = $m;
                }
            }
            if ($neu && !sm_cfg_set($s, $neu)) {
                $ok = false;
            }
        }

        if (!$ok) {
            $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN_TEIL'),
                                   '<span class="sm-mono">smartmeter.cfg</span>');
        } elseif (!$sm_fehler) {
            list($cron_ok, $cron_text) = sm_cron_setzen($lesen, $takt);
            $sm_legacy = sm_legacy_read();
            sm_cache_verwerfen();
            if ($cron_ok) {
                $sm_meldung = sm_t('MELD.GESPEICHERT') . ' ' . $cron_text;
            } else {
                /* Die Konfiguration IST geschrieben - sm_cfg_set() lief
                 * weiter oben. Nur der Cron-Eintrag fehlt. Ohne diesen
                 * Zusatz stand der Fehlertext unter der Ueberschrift
                 * "Nicht gespeichert:", und das war die falsche Aussage:
                 * der Takt steht in der Datei, nur eingerichtet ist er
                 * nicht. Die Zeile "Cron-Eintrag" im Reiter Test zeigt
                 * denselben Widerspruch. */
                $sm_fehler[] = sm_t('CRON.NUR_EINTRAG') . ' ' . $cron_text;
            }
        }
    }
    $sm_tab = 'tab-legacy';
}

if (isset($_POST['lg_abfragen'])) {
    $sm_lg_ausgabe = sm_manuell_abfragen();
    $sm_tab = 'tab-legacy';
}

if (isset($_POST['lg_suchlauf'])) {
    $sm_dev = isset($_POST['lg_such_device']) ? (string) $_POST['lg_such_device'] : '';
    list($sm_suchzeilen, $sm_suchvorschlag) = sm_suchlauf($sm_dev);
    $sm_tab = 'tab-legacy';
}

if (isset($_POST['lg_cache'])) {
    $n = sm_cache_leeren();
    $sm_meldung = sprintf(sm_t('MELD.CACHE'), $n);
    $sm_tab = 'tab-legacy';
}

if (isset($_POST['lox_token_neu'])) {
    if (sm_cfg_set('MAIN', array('TOKEN' => sm_token_erzeugen()))) {
        sm_log('Neues Zugriffstoken gesetzt.');
        sm_cache_verwerfen();
        $sm_legacy = sm_legacy_read();
        $sm_meldung = sm_t('LOX.TOKEN_NEU');
    } else {
        $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN'),
            '<span class="sm-mono">smartmeter.cfg</span>');
    }
    $sm_tab = 'tab-loxone';
}

if (isset($_POST['lox_token_weg'])) {
    if (sm_cfg_set('MAIN', array('TOKEN' => ''))) {
        sm_log('Zugriffstoken entfernt - der Endpunkt steht wieder offen.', 'WARN');
        sm_cache_verwerfen();
        $sm_legacy = sm_legacy_read();
        $sm_meldung = sm_t('LOX.TOKEN_WEG');
    } else {
        $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN'),
            '<span class="sm-mono">smartmeter.cfg</span>');
    }
    $sm_tab = 'tab-loxone';
}

// Angesteckte Lesekoepfe eintragen, falls neu
sm_koepfe_anlegen();

$sm_lcfg        = sm_cfg_read();
$sm_lcfg_read   = sm_cfg_get($sm_lcfg, 'MAIN', 'READ', '0');
$sm_lcfg_cron   = sm_cfg_get($sm_lcfg, 'MAIN', 'CRON', '5');
$sm_lcfg_udp    = sm_cfg_get($sm_lcfg, 'MAIN', 'SENDUDP', '0');
$sm_lcfg_udpport = sm_cfg_get($sm_lcfg, 'MAIN', 'UDPPORT', '7000');

$sm_koepfe  = sm_lesekoepfe();
$sm_host    = sm_hostname();

/* Diagnose und Selbstpruefung kosten Prozessstarts - gemessen 15 in einem
 * Seitenaufbau, auf dem Geraet rund 19, darunter apt-cache policy und ein
 * curl mit fuenf Sekunden Zeitgrenze. Sie laufen deshalb nur, wenn ihr
 * Reiter serverseitig der offene ist. Damit der Reiter mit einem Klick
 * erreichbar bleibt, laedt genau er die Seite neu; die uebrigen schaltet
 * das JavaScript weiterhin ohne Neuladen um. */
$sm_diag = array();
$sm_diag_alter = 0;
$sm_pruef = array();
$sm_bin = '';
$sm_binwarum = '';
$sm_pid = '';
if ($sm_tab === 'tab-vzlogger' || $sm_tab === 'tab-test') {
    list($sm_diag, $sm_diag_alter) = sm_diagnose_gepuffert($sm_cfg);
    list($sm_bin, $sm_binwarum) = sm_vz_binary();
    $sm_pid = sm_vz_running();
}
if ($sm_tab === 'tab-test') {
    $sm_pruef = sm_selbsttest($sm_reiter_ids, __FILE__);
}
$sm_logtext = ($sm_tab === 'tab-log') ? sm_logtail() : '';

// Adresse des Endpunkts und Zustand des freiwilligen Tokens.
$sm_token = $sm_legacy['TOKEN'];
$sm_wirt = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
    : $sm_host;
$sm_endpunkt = 'http://' . $sm_wirt . '/plugins/' . $sm_p['plugin'] . '/index.php'
    . ($sm_token !== '' ? '?token=' . $sm_token : '');
$sm_endpunkt_selftest = 'http://' . $sm_wirt . '/plugins/' . $sm_p['plugin']
    . '/index.php?selftest=1' . ($sm_token !== '' ? '&token=' . $sm_token : '');

$sm_version = sm_fassung();

LBWeb::lbheader(sm_t('ALLG.TITEL') . ($sm_version !== '' ? ' V' . $sm_version : ''),
                'https://wiki.loxberry.de/plugins/smartmeter/start', 'help.html');
?>

<style>
.sm-wrap { max-width: 1100px; }
.sm-wrap h3.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-wrap h2 { color: #4f7d17; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px;
  font-size: 1.15em; margin: 22px 0 8px; }
.sm-small { font-size: 0.88em; color: #555; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-mono { font-family: monospace; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
  padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important;
  text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; }
.sm-tbl td, .sm-tbl th { border: 1px solid #ddd; padding: 6px 9px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
/* Eine Tabelle, die breiter ist als das Fenster, braucht ihre eigene
   Bildlaufleiste. Ohne sie steht die letzte Spalte AUSSERHALB und ist
   unerreichbar, nicht bloss unbequem: .sm-tbl hat width:100%, und .sm-wrap
   ein max-width ohne Ueberlauf. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-row { margin: 8px 0; }
.sm-row label { display: block; font-weight: 600; font-size: 0.9em; margin-bottom: 2px; }
.sm-row input[type=text], .sm-row select, .sm-row textarea {
  width: 100%; max-width: 420px; padding: 7px; box-sizing: border-box; }
.sm-row textarea { font-family: monospace; height: 80px; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Die Rahmen-CSS des
   LoxBerry setzt appearance:none, und damit verschwindet der Pfeil, den
   sonst der Browser zeichnet - das Feld sieht aus wie ein Textfeld. Diese
   Fehlerklasse hat in diesem Haus zweimal ein Mensch gefunden und kein
   Werkzeug; hier stehen hinter einem der Felder 45 Zaehlerprofile.
   Die Raute in der SVG-Adresse wird als %23 geschrieben - eine rohe Raute
   beendet den CSS-Wert. */
.sm-wrap select.sm-auswahl {
  appearance: none; -webkit-appearance: none; -moz-appearance: none;
  background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 10px center;
  padding-right: 32px; cursor: pointer; }
.sm-tbl select.sm-auswahl { padding-right: 28px; background-position: right 7px center; }
.sm-alert { padding: 10px 12px; border-radius: 6px; margin: 10px 0; font-size: 0.9em; }
.sm-ok   { background: #eaf5e0; border: 1px solid #6dac20; }
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
.sm-info { background: #eef3f7; border: 1px solid #546e7a; }
.sm-log { background: #1e1e1e; color: #ddd; font-family: monospace; font-size: 0.82em;
  padding: 10px; border-radius: 6px; max-height: 460px; overflow: auto; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.sm-wrap .sm-knopfreihe button, .sm-wrap .sm-btn {
  border: 0 !important; border-radius: 6px !important; padding: 9px 16px !important;
  font-size: 0.9em !important; cursor: pointer; color: #fff !important;
  font-weight: 600 !important; text-shadow: none !important; box-shadow: none !important;
  opacity: 1 !important; margin: 0 !important; text-decoration: none; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-step { border-left: 3px solid #6dac20; padding: 2px 0 2px 12px; margin: 14px 0; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-family: monospace;
  white-space: pre-wrap; font-size: 0.86em; }
.sm-diag td:first-child { width: 22%; font-weight: 600; }
.sm-diag td:nth-child(2) { width: 4%; text-align: center; font-weight: 700; }
.sm-scheibe { display: inline-block; width: 12px; height: 12px; border-radius: 50%;
  margin-right: 6px; vertical-align: middle; }
.sm-gruen { background: #1a7f1a; }
.sm-rot { background: #b00000; }
</style>

<div class="sm-wrap">

<?php if ($sm_fehler) { ?>
<div class="sm-alert sm-warn"><b><?php echo sm_t('ALLG.NICHT_GESPEICHERT'); ?></b><ul>
<?php foreach ($sm_fehler as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
<?php } elseif ($sm_meldung !== '') { ?>
<div class="sm-alert sm-ok"><?php echo $sm_meldung; ?></div>
<?php } ?>
<?php if ($sm_hinweis !== '') { ?>
<div class="sm-alert sm-warn"><?php echo sm_e($sm_hinweis); ?></div>
<?php } ?>
<?php if ($sm_notizen) { ?>
<div class="sm-alert sm-info"><ul>
<?php foreach ($sm_notizen as $n) { echo '<li>' . $n . '</li>'; } ?>
</ul></div>
<?php } ?>
<?php if ($sm_ergaenzt) { ?>
<div class="sm-alert sm-info"><?php printf(sm_t('MELD.ERGAENZT'),
  sm_e(implode(', ', $sm_ergaenzt))); ?></div>
<?php } ?>

<!-- Reiterleiste: echte Verweise, das JavaScript spart nur den Seitenaufbau.
     Welcher Reiter offen ist, entscheidet der SERVER - sm-active steht schon
     im ausgelieferten HTML, an der Leiste und an jeder Flaeche. Ohne das
     waere die Seite ohne JavaScript vollstaendig leer, denn .sm-pane steht
     auf display:none.

     Die Leiste steht AUSGESCHRIEBEN da und nicht in einer Schleife: das
     Hauswerkzeug sucht data-ziel="tab-..." als Literal und meldet sonst
     einen Strich, der wie ein Haken aussieht. Der Reiter Test misst dafuer
     nach, dass Liste, Leiste und Flaechen dieselben Namen tragen.

     Test und vzLogger laden die Seite bewusst NEU (kein data-ziel), weil
     ihre Pruefungen serverseitig laufen. -->
<div class="sm-tabs">
  <a class="sm-tab<?php echo $sm_tab === 'tab-vzlogger' ? ' sm-active' : ''; ?>"
     data-ziel="tab-vzlogger" data-neuladen="1"
     href="index.php?tab=vzlogger"><?php echo sm_t('TAB.VZ'); ?></a>
  <a class="sm-tab<?php echo $sm_tab === 'tab-legacy' ? ' sm-active' : ''; ?>"
     data-ziel="tab-legacy"
     href="index.php?tab=legacy"><?php echo sm_t('TAB.LEGACY'); ?></a>
  <a class="sm-tab<?php echo $sm_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>"
     data-ziel="tab-mqtt"
     href="index.php?tab=mqtt"><?php echo sm_t('TAB.MQTT'); ?></a>
  <a class="sm-tab<?php echo $sm_tab === 'tab-loxone' ? ' sm-active' : ''; ?>"
     data-ziel="tab-loxone"
     href="index.php?tab=loxone"><?php echo sm_t('TAB.LOXONE'); ?></a>
  <a class="sm-tab<?php echo $sm_tab === 'tab-test' ? ' sm-active' : ''; ?>"
     data-ziel="tab-test" data-neuladen="1"
     href="index.php?tab=test"><?php echo sm_t('TAB.TEST'); ?></a>
  <a class="sm-tab<?php echo $sm_tab === 'tab-log' ? ' sm-active' : ''; ?>"
     data-ziel="tab-log" data-neuladen="1"
     href="index.php?tab=log"><?php echo sm_t('TAB.LOG'); ?></a>
</div>

<!-- ============================== vzLogger ============================== -->
<div class="sm-pane<?php echo $sm_tab === 'tab-vzlogger' ? ' sm-active' : ''; ?>" id="tab-vzlogger">

<?php if ($sm_installout !== '') { ?>
<h2><?php echo sm_t('VZ.H_INSTALLAUSGABE'); ?></h2>
<div class="sm-log"><?php echo sm_e($sm_installout); ?></div>
<?php } ?>

<h2><?php echo sm_t('VZ.H_ZUSTAND'); ?></h2>
<?php if ($sm_diag) { ?>
<div class="sm-breit">
<table class="sm-tbl sm-diag">
<?php foreach ($sm_diag as $z) { ?>
<tr><td><?php echo sm_e($z[0]); ?></td>
    <td style="color:<?php echo sm_farbe($z[1]); ?>"><?php echo sm_zeichen($z[1]); ?></td>
    <td><?php echo sm_e($z[2]); ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-small"><?php printf(sm_t('DIAG.ALTER_HINWEIS'), (int) $sm_diag_alter); ?></p>
<?php } ?>

<?php if ($sm_bin === '') { ?>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-vzlogger">
    <?php echo sm_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="vz_install" value="1"><?php echo sm_t('VZ.K_INSTALL'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.VZ_INSTALL'); ?></span>
</div>
<?php } else { ?>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-vzlogger">
    <?php echo sm_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="vz_neustart" value="1"><?php echo sm_t('VZ.K_NEUSTART'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.VZ_NEUSTART'); ?></span>
</div>
<?php } ?>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-vzlogger">
<?php echo sm_fmt(); ?>

<h2><?php echo sm_t('VZ.H_LESEWEG'); ?></h2>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="vz_enabled" value="1"<?php
    echo $sm_cfg['enabled'] ? ' checked' : ''; ?>> <?php echo sm_t('VZ.LABEL_ENABLED'); ?></label>
  <p class="sm-small"><?php echo sm_t('VZ.HINT_EINLESER'); ?></p>
</div>

<h2><?php echo sm_t('VZ.H_LESEKOPF'); ?></h2>
<?php if (!$sm_koepfe) { ?>
<div class="sm-alert sm-warn"><?php printf(sm_t('VZ.WARN_KEINKOPF'),
  '<span class="sm-mono">/dev/serial/smartmeter/*</span>'); ?></div>
<?php } ?>
<div class="sm-row">
  <label for="vz_device"><?php echo sm_t('ALLG.GERAET'); ?></label>
  <select data-role="none" class="sm-auswahl" id="vz_device" name="vz_device">
    <option value="">&ndash; <?php echo sm_t('ALLG.KEINES'); ?> &ndash;</option>
<?php
$gefunden = false;
foreach ($sm_koepfe as $d) {
    if ($d === $sm_cfg['device']) { $gefunden = true; }
    echo '<option value="' . sm_e($d) . '"'
       . ($d === $sm_cfg['device'] ? ' selected' : '') . '>' . sm_e($d) . '</option>';
}
// Ein gespeichertes, gerade nicht angestecktes Geraet nicht stillschweigend
// verlieren.
if (!$gefunden && $sm_cfg['device'] !== '') {
    echo '<option value="' . sm_e($sm_cfg['device']) . '" selected>'
       . sm_e($sm_cfg['device']) . ' (' . sm_t('VZ.NICHT_VORHANDEN') . ')</option>';
}
?>
  </select>
  <p class="sm-small"><?php echo sm_t('ALLG.AUSWAHLFELD'); ?></p>
</div>
<div class="sm-row">
  <label for="vz_protocol"><?php echo sm_t('VZ.LABEL_PROTOCOL'); ?></label>
  <select data-role="none" class="sm-auswahl" id="vz_protocol" name="vz_protocol">
    <option value="sml"<?php echo $sm_cfg['protocol'] === 'sml' ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_SML'); ?></option>
    <option value="d0"<?php echo $sm_cfg['protocol'] === 'd0' ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_D0'); ?></option>
  </select>
</div>
<div class="sm-row">
  <label for="vz_baudrate"><?php echo sm_t('VZ.LABEL_BAUDRATE'); ?></label>
  <input data-role="none" type="text" id="vz_baudrate" name="vz_baudrate"
         value="<?php echo sm_e($sm_cfg['baudrate']); ?>">
  <p class="sm-small"><?php echo sm_t('VZ.HINT_BAUDRATE'); ?></p>
</div>
<div class="sm-row">
  <label for="vz_parity"><?php echo sm_t('VZ.LABEL_PARITY'); ?></label>
  <select data-role="none" class="sm-auswahl" id="vz_parity" name="vz_parity">
<?php foreach (array('8n1', '7n1', '7e1', '8e1') as $par) { ?>
    <option value="<?php echo $par; ?>"<?php
      echo $sm_cfg['parity'] === $par ? ' selected' : ''; ?>><?php echo $par; ?></option>
<?php } ?>
  </select>
</div>
<div class="sm-row">
  <label for="vz_localtime"><?php echo sm_t('VZ.LABEL_LOCALTIME'); ?></label>
  <select data-role="none" class="sm-auswahl" id="vz_localtime" name="vz_localtime">
    <option value="1"<?php echo $sm_cfg['localtime'] ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_LOKALZEIT'); ?></option>
    <option value="0"<?php echo !$sm_cfg['localtime'] ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_ZAEHLERZEIT'); ?></option>
  </select>
  <p class="sm-small"><?php printf(sm_t('VZ.HINT_LOCALTIME'),
    '<span class="sm-mono">timestamp before 1990, IGNORING</span>'); ?></p>
</div>

<h2><?php echo sm_t('VZ.H_KANAELE'); ?></h2>
<div class="sm-row">
  <label for="vz_channels"><?php echo sm_t('VZ.LABEL_CHANNELS'); ?></label>
  <textarea data-role="none" id="vz_channels" name="vz_channels"><?php
    echo sm_e(implode("\n", $sm_cfg['channels'])); ?></textarea>
  <p class="sm-small"><?php echo sm_t('ALLG.VORGABE'); ?>:
  <span class="sm-mono">1-0:1.8.0</span> (<?php echo sm_t('OBIS.BEZUG'); ?>),
  <span class="sm-mono">1-0:2.8.0</span> (<?php echo sm_t('OBIS.EINSPEISUNG'); ?>),
  <span class="sm-mono">1-0:16.7.0</span> (<?php echo sm_t('OBIS.LEISTUNG'); ?>).</p>
</div>

<h2><?php echo sm_t('VZ.H_WEITERGABE'); ?></h2>
<div class="sm-row">
  <label for="vz_serial"><?php echo sm_t('VZ.LABEL_SERIAL'); ?></label>
  <input data-role="none" type="text" id="vz_serial" name="vz_serial"
         value="<?php echo sm_e($sm_cfg['serial']); ?>">
  <p class="sm-small"><?php echo sm_t('VZ.HINT_SERIAL'); ?></p>
</div>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="vz_sendudp" value="1"<?php
    echo $sm_cfg['sendudp'] ? ' checked' : ''; ?>> <?php echo sm_t('ALLG.UDP_ZUSAETZLICH'); ?></label>
  <p class="sm-small"><?php echo sm_t('VZ.HINT_UDP'); ?></p>
</div>
<div class="sm-row">
  <label for="vz_udpport"><?php echo sm_t('ALLG.UDPPORT'); ?></label>
  <input data-role="none" type="text" id="vz_udpport" name="vz_udpport"
         value="<?php echo sm_e($sm_cfg['udpport']); ?>">
</div>
<div class="sm-row">
  <label for="vz_httpport"><?php echo sm_t('VZ.LABEL_HTTPPORT'); ?></label>
  <input data-role="none" type="text" id="vz_httpport" name="vz_httpport"
         value="<?php echo sm_e($sm_cfg['httpport']); ?>">
  <p class="sm-small"><?php echo sm_t('VZ.HINT_HTTPPORT'); ?></p>
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="vz_speichern" value="1"><?php echo sm_t('VZ.K_SPEICHERN'); ?></button>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.VZ_SPEICHERN'); ?></span>
</div>
</form>

<h2><?php echo sm_t('EINST.H_SICHERUNG'); ?></h2>
<div class="sm-small"><?php echo sm_t('EINST.SICHERUNG_HINT'); ?></div>
<div class="sm-alert sm-warn"><?php echo sm_t('EINST.SICHERUNG_GEHEIM'); ?></div>
<!-- ZWEI getrennte Formulare. Das Sichern schickt einen Download und ruft
     exit auf; das Zurueckspielen braucht enctype="multipart/form-data". Wer
     beides in ein Formular legt, bekommt entweder keinen Upload oder einen
     Download, der das Speichern verschluckt. -->
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-vzlogger">
    <?php echo sm_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="sichern" value="1"><?php echo sm_t('EINST.SICHERN'); ?></button>
  </form>
  <form method="post" action="index.php" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-vzlogger">
    <?php echo sm_fmt(); ?>
    <input data-role="none" type="file" name="sicherung" accept=".txt,text/plain">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="laden" value="1"><?php echo sm_t('EINST.LADEN'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo sm_t('LEGENDE.SICHERN'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.LADEN'); ?></span>
</div>
<div class="sm-small"><?php echo sm_t('EINST.LADEN_HINT'); ?></div>
</div>

<!-- =============================== Legacy =============================== -->
<div class="sm-pane<?php echo $sm_tab === 'tab-legacy' ? ' sm-active' : ''; ?>" id="tab-legacy">

<h2><?php echo sm_t('LG.H_LESER'); ?></h2>
<p class="sm-small"><?php echo sm_t('LG.HINT_LESER'); ?></p>

<div class="sm-alert sm-info"><b><?php echo sm_t('LG.WARN_EINLESER'); ?></b>
<?php echo $sm_cfg['enabled'] ? ' ' . sm_t('LG.VZ_AN') : ' ' . sm_t('LG.VZ_AUS'); ?></div>

<?php $sm_lpid = sm_logger_pid(); ?>
<p><span class="sm-scheibe <?php echo $sm_lpid !== null ? 'sm-gruen' : 'sm-rot'; ?>"></span>
<?php echo $sm_lpid !== null
    ? sprintf(sm_t('LG.LAEUFT'), sm_e($sm_lpid))
    : sm_t('LG.LAEUFT_NICHT'); ?>
<span class="sm-small"><?php echo sm_t('LG.TAKT_LAUT_LINK'); ?>:
<?php $sm_ist = sm_cron_ist();
      $sm_takte = sm_takte();
      echo $sm_ist !== '' ? $sm_takte[$sm_ist][1] : sm_t('LG.KEIN_TAKT'); ?></span></p>
<?php list($sm_cl_ok, $sm_cl_text) = sm_cron_lage(); ?>
<p class="sm-small"><span class="sm-scheibe <?php echo $sm_cl_ok === 1 ? 'sm-gruen' : 'sm-rot'; ?>"></span>
<?php echo sm_e($sm_cl_text); ?></p>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-legacy">
<?php echo sm_fmt(); ?>

<h2><?php echo sm_t('LG.H_ABFRAGE'); ?></h2>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="lg_read" value="1"<?php
    echo $sm_lcfg_read === '1' ? ' checked' : ''; ?>> <?php echo sm_t('LG.LABEL_ENABLED'); ?></label>
</div>
<div class="sm-row">
  <label for="lg_cron"><?php echo sm_t('LG.LABEL_TAKT'); ?></label>
  <select data-role="none" class="sm-auswahl" id="lg_cron" name="lg_cron">
<?php foreach (sm_takte() as $wert => $t) { ?>
    <option value="<?php echo $wert; ?>"<?php
      /* (string) auf BEIDE Seiten. PHP wandelt einen Feldschluessel, der wie
       * eine Ganzzahl aussieht, beim Anlegen des Feldes selbst in eine
       * Ganzzahl um: aus '5' => ... wird 5 => ... . Der Wert aus der
       * Konfiguration ist dagegen eine Zeichenkette, und '5' === 5 ist
       * falsch. Ohne die Wandlung stand an KEINEM Eintrag ein selected -
       * das Auswahlfeld zeigte immer den ersten ("nur beim Systemstart"),
       * und ein unveraendertes Absenden des Formulars schrieb genau den in
       * die Konfiguration. Gemessen am 02.09.2026 in 7.4.33 und 8.4.24:
       * CRON=30 vorher, CRON=M nachher. */
      echo (string) $sm_lcfg_cron === (string) $wert ? ' selected' : ''; ?>><?php echo $t[1]; ?></option>
<?php } ?>
  </select>
  <p class="sm-small"><?php echo sm_t('LG.HINT_TAKT'); ?></p>
</div>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="lg_sendudp" value="1"<?php
    echo $sm_lcfg_udp === '1' ? ' checked' : ''; ?>> <?php echo sm_t('ALLG.UDP_ZUSAETZLICH'); ?></label>
</div>
<div class="sm-row">
  <label for="lg_udpport"><?php echo sm_t('ALLG.UDPPORT'); ?></label>
  <input data-role="none" type="text" id="lg_udpport" name="lg_udpport"
         value="<?php echo sm_e($sm_lcfg_udpport); ?>">
  <p class="sm-small"><?php echo sm_t('LG.HINT_MQTT'); ?></p>
</div>

<h2><?php echo sm_t('LG.H_KOEPFE'); ?></h2>
<?php $sm_koepfe_liste = sm_koepfe(); ?>
<?php if (!$sm_koepfe_liste) { ?>
<div class="sm-alert sm-warn"><?php echo sm_t('LG.WARN_KEINKOPF'); ?></div>
<?php } else { foreach ($sm_koepfe_liste as $sm_k) {
    $sm_s = $sm_k['ABSCHNITT']; ?>
<h3 class="sm-h3"><?php echo sm_e($sm_s); ?>
<?php if (!$sm_k['ANGESTECKT']) { ?>
  <span class="sm-small">&ndash; <?php echo sm_t('LG.NICHT_ANGESTECKT'); ?></span>
<?php } ?></h3>
<div class="sm-row">
  <label for="<?php echo sm_e($sm_s); ?>_name"><?php echo sm_t('LG.LABEL_NAME'); ?></label>
  <input data-role="none" type="text" id="<?php echo sm_e($sm_s); ?>_name"
         name="lg_<?php echo sm_e($sm_s); ?>_name"
         value="<?php echo sm_e(isset($sm_k['NAME']) ? $sm_k['NAME'] : $sm_s); ?>">
</div>
<div class="sm-row">
  <label for="<?php echo sm_e($sm_s); ?>_meter"><?php echo sm_t('LG.LABEL_PROFIL'); ?></label>
  <select data-role="none" class="sm-auswahl" id="<?php echo sm_e($sm_s); ?>_meter" name="lg_<?php echo sm_e($sm_s); ?>_meter">
<?php $sm_akt = isset($sm_k['METER']) ? $sm_k['METER'] : '0';
      /* Wie beim Abfragetakt: der Schluessel '0' ist im Feld eine Ganzzahl,
       * der Wert aus der Konfiguration eine Zeichenkette. Hier fiel es
       * bisher nicht auf, weil '0' zufaellig der erste Eintrag ist. */
      foreach (sm_profile() as $sm_pk => $sm_pn) { ?>
    <option value="<?php echo sm_e($sm_pk); ?>"<?php
      echo (string) $sm_akt === (string) $sm_pk ? ' selected' : ''; ?>><?php echo $sm_pn; ?></option>
<?php } ?>
  </select>
  <p class="sm-small"><?php echo sm_t('ALLG.GERAET'); ?>: <span class="sm-mono"><?php
    echo sm_e($sm_k['DEVICE']); ?></span> &middot; <?php echo sm_t('ALLG.AUSWAHLFELD'); ?></p>
</div>
<?php $sm_w = sm_werte($sm_s); if ($sm_w) { ?>
<p class="sm-small"><?php echo sm_t('LG.ZULETZT'); ?>:</p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:46%"><?php echo sm_t('ALLG.GROESSE'); ?></th><th><?php echo sm_t('ALLG.WERT'); ?></th></tr>
<?php foreach ($sm_w as $sm_pv) { ?>
<tr><td class="sm-mono"><?php echo sm_e($sm_pv[0]); ?></td>
    <td class="sm-mono"><?php echo sm_e($sm_pv[1]); ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<?php } } ?>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="lg_speichern" value="1"><?php echo sm_t('LG.K_SPEICHERN'); ?></button>
</div>
</form>

<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-legacy">
    <?php echo sm_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="lg_abfragen" value="1"><?php echo sm_t('LG.K_ABFRAGEN'); ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-legacy">
    <?php echo sm_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="lg_cache" value="1"><?php echo sm_t('LG.K_CACHE'); ?></button>
  </form>
</div>

<h2><?php echo sm_t('SUCHE.H'); ?></h2>
<p class="sm-small"><?php echo sm_t('SUCHE.HINT'); ?></p>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-legacy">
    <?php echo sm_fmt(); ?>
    <select data-role="none" class="sm-auswahl" name="lg_such_device">
<?php foreach ($sm_koepfe as $d) { ?>
      <option value="<?php echo sm_e($d); ?>"><?php echo sm_e($d); ?></option>
<?php } ?>
    </select>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="lg_suchlauf" value="1"<?php
      echo $sm_koepfe ? '' : ' disabled'; ?>><?php echo sm_t('SUCHE.K'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo sm_t('LEGENDE.LG_ABFRAGEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo sm_t('LEGENDE.LG_CACHE'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.LG_SPEICHERN'); ?></span>
</div>

<?php if ($sm_suchzeilen) { ?>
<h2><?php echo sm_t('SUCHE.H_ERGEBNIS'); ?></h2>
<div class="sm-log"><?php echo sm_e(implode("\n", $sm_suchzeilen)); ?></div>
<?php if ($sm_suchvorschlag !== '') { ?>
<div class="sm-alert sm-info"><?php printf(sm_t('SUCHE.UEBERNEHMEN'),
  '<span class="sm-mono">' . sm_e($sm_suchvorschlag) . '</span>'); ?></div>
<?php } ?>
<?php } ?>

<?php if ($sm_lg_ausgabe !== '') { ?>
<h2><?php echo sm_t('ALLG.AUSGABE'); ?></h2>
<div class="sm-log"><?php echo sm_e($sm_lg_ausgabe); ?></div>
<?php } ?>

<h2><?php echo sm_t('LG.H_WIE'); ?></h2>
<p class="sm-small"><?php printf(sm_t('LG.TEXT_WIE'),
  '<span class="sm-mono">bin/fetch.php</span>',
  '<span class="sm-mono">bin/sm_logger.pl</span>',
  '<span class="sm-mono">Device::SerialPort</span>'); ?></p>
</div>

<!-- ================================= MQTT ================================= -->
<div class="sm-pane<?php echo $sm_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">
<?php $sm_gw = sm_mqtt_gateway_info(); ?>
<?php if ($sm_gw !== null && !$sm_gw['autostart']) { ?>
<div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo sm_t('MQ.W_AUTOSTART'); ?></div>
<?php } ?>

<h2><?php echo sm_t('MQ.H_ZUSTAND'); ?></h2>
<table class="sm-tbl">
<tr><td style="width:34%"><?php echo sm_t('MQ.Z_AUTOSTART'); ?></td>
    <td><?php echo $sm_gw === null ? sm_t('ALLG.UNBEKANNT')
        : ($sm_gw['autostart'] ? sm_t('ALLG.JA') : sm_t('ALLG.NEIN')); ?></td></tr>
<tr><td><?php echo sm_t('MQ.Z_FASSUNG'); ?></td>
    <td><?php echo ($sm_gw === null || (int) $sm_gw['fassung'] <= 0)
        ? sm_t('ALLG.UNBEKANNT') : (int) $sm_gw['fassung']; ?></td></tr>
<tr><td><?php echo sm_t('MQ.Z_UDPIN'); ?></td>
    <td class="sm-mono"><?php echo ($sm_gw === null || (int) $sm_gw['udpin'] <= 0)
        ? sm_t('ALLG.UNBEKANNT') : (int) $sm_gw['udpin']; ?></td></tr>
</table>
<p class="sm-small"><?php echo sm_t('MQ.HINT_GATEWAY'); ?></p>

<h2><?php echo sm_t('MQ.H_EINSTELLUNGEN'); ?></h2>
<p class="sm-small"><?php echo sm_t('MQ.HINT_EINZIGE'); ?></p>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<?php echo sm_fmt(); ?>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="mq_an" value="1"<?php
    echo $sm_legacy['SENDMQTT'] === '1' ? ' checked' : ''; ?>> <?php echo sm_t('MQ.LABEL_AN'); ?></label>
</div>
<div class="sm-row">
  <label for="mq_topic"><?php echo sm_t('MQ.LABEL_TOPIC'); ?></label>
  <input data-role="none" type="text" id="mq_topic" name="mq_topic"
         value="<?php echo sm_e($sm_legacy['MQTTTOPIC']); ?>">
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mq_speichern" value="1"><?php echo sm_t('ALLG.SPEICHERN'); ?></button>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.MQ_SPEICHERN'); ?></span>
</div>
</form>

<h2><?php echo sm_t('MQ.H_ABO'); ?></h2>
<!-- EINE Stelle fuer den Abo-Satz: sm_abo_text() haengt ihn an die Fassung
     des Gateways. Der Satz stand hier und in Schritt 2 des Loxone-Reiters
     unbedingt da - unter Gateway V2 haetten damit beide Texte auf der Seite
     gestanden. Genau das ist dem Vorbild MG iSmart passiert. -->
<div class="<?php echo sm_abo_klasse(); ?>"><?php echo sm_abo_text(); ?></div>
<p class="sm-small"><?php echo sm_t('MQ.ABO_WO'); ?>:</p>
<pre class="sm-pre"><?php echo sm_e(trim($sm_legacy['MQTTTOPIC'], '/')); ?>/#</pre>

<h2><?php echo sm_t('MQ.H_THEMEN'); ?></h2>
<p class="sm-small"><?php echo sm_t('MQ.THEMEN_HINT'); ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:48%"><?php echo sm_t('MQ.SP_THEMA'); ?></th>
    <th style="width:12%"><?php echo sm_t('ALLG.EINHEIT'); ?></th>
    <th><?php echo sm_t('ALLG.BEDEUTUNG'); ?></th></tr>
<?php
/* Die Themen kommen aus derselben Funktion wie die Vorlage und wie der
 * Dienst - bis 2.3.14 zeigte diese Tabelle die OBIS-Kennzahl, waehrend der
 * Dienst unter dem Feldnamen veroeffentlichte. Kein einziger der drei
 * Namen stimmte. */
foreach (sm_vz_felder($sm_cfg) as $sm_feld) {
    $sm_md = sm_feld($sm_feld);
    /* Die Einheit kommt aus sm_einheit_fuer() - derselben Funktion, aus
     * der auch die Loxone-Vorlage schoepft, und mit demselben Weg 'vz'.
     * Diese Tabelle zeigt die Themen des vzLOGGER-Weges; bis 2.4.3 nahm
     * sie 'einheit', also die Einheit des KLASSISCHEN Weges, und stand
     * damit im Widerspruch zu dem, was der Dienst liefert.
     *
     * Maskiert wird die Einheit; nur der Gedankenstrich fuer "keine
     * Einheit" ist Auszeichnung und geht roh hinaus. */
    list($sm_eh_roh, , ) = sm_einheit_fuer($sm_md, 'vz', $sm_feld);
    $sm_eh = ($sm_eh_roh !== '') ? sm_e($sm_eh_roh) : '&ndash;';
    $sm_bd = $sm_md ? sm_t($sm_md['bed']) : $sm_feld;
    ?>
<tr><td class="sm-mono"><?php echo sm_e(sm_thema($sm_legacy['MQTTTOPIC'], $sm_cfg['serial'], $sm_feld)); ?></td>
    <td><?php echo $sm_eh; ?></td>
    <td><?php echo sm_e($sm_bd); ?><?php
      if ($sm_md && $sm_md['typ'] === 'text') { echo ' <i>(' . sm_t('ALLG.TEXTFELD') . ')</i>'; } ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-alert sm-info"><?php echo sm_t('MQ.EINHEIT_VZ'); ?></div>
</div>

<!-- ========================= Einbindung in Loxone ========================= -->
<div class="sm-pane<?php echo $sm_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">

<h2><?php echo sm_t('LOX.H_TITEL'); ?></h2>

<!-- Der Bruch aus 2.5.0. Er steht GANZ OBEN und nicht in einer Fussnote:
     wer den Reiter aufschlaegt, hat entweder schon Eingaenge angelegt -
     dann betrifft es ihn - oder nicht, dann kostet ihn der Kasten drei
     Zeilen. Umgekehrt faende ihn niemand. -->
<div class="sm-alert sm-warn">
<?php printf(sm_t('LOX.BRUCH_250'),
             '<span class="sm-mono">Breaker_State_Electricity_96.1.4</span>',
             '<span class="sm-mono">Breaker_State_Electricity_96.3.10</span>'); ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S1_TITEL'); ?></b><br><br>
<?php echo sm_t('LOX.S1_TEXT'); ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S2_TITEL'); ?></b><br><br>
<div class="<?php echo sm_abo_klasse(); ?>"><?php echo sm_abo_text(); ?></div>
<?php echo sm_t('MQ.ABO_WO'); ?>:
<pre class="sm-pre"><?php echo sm_e(trim($sm_legacy['MQTTTOPIC'], '/')); ?>/#</pre>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S3_TITEL'); ?></b><br><br>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?php echo sm_t('LOX.SP_TITEL_VE'); ?></th><th style="width:14%"><?php echo sm_t('ALLG.EINHEIT'); ?></th><th style="width:28%"><?php echo sm_t('ALLG.BEDEUTUNG'); ?></th></tr>
<?php foreach (sm_vz_felder($sm_cfg) as $sm_feld) {
    $sm_md = sm_feld($sm_feld);
    if ($sm_md && $sm_md['typ'] === 'text') { continue; }
    /* Dieselbe Quelle wie die Vorlage, die der Knopf darunter erzeugt.
     * Die Tabelle erklaert, WAS importiert wird - sie muss deshalb genau
     * das zeigen, was in der Datei steht. */
    list($sm_eh_roh, , ) = sm_einheit_fuer($sm_md, 'vz', $sm_feld);
    $sm_eh = ($sm_eh_roh !== '')
        ? '&lt;v.' . (int) $sm_md['nk'] . '&gt;&nbsp;' . sm_e($sm_eh_roh) : '&ndash;';
    $sm_bd = $sm_md ? sm_t($sm_md['bed']) : $sm_feld; ?>
<tr><td class="sm-mono"><?php echo sm_e(sm_ve_name($sm_legacy['MQTTTOPIC'], $sm_cfg['serial'], $sm_feld)); ?></td>
    <td><?php echo $sm_eh; ?></td><td><?php echo sm_e($sm_bd); ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-small"><?php echo sm_t('LOX.S3_HINT'); ?></p>

<h2><?php echo sm_t('LOX.H_VORLAGE'); ?></h2>
<div class="sm-hinweis"><?php echo sm_t('LOX.H_VORLAGE_TEXT'); ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <?php echo sm_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="mqtt"><?php echo sm_t('LOX.K_VORLAGE'); ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <?php echo sm_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="legacy"><?php echo sm_t('LOX.K_VORLAGE_LG'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo sm_t('LEGENDE.VORLAGE'); ?></span>
</div>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S4_TITEL'); ?></b><br><br>
<?php if ($sm_cfg['sendudp']) { ?>
<?php printf(sm_t('LOX.S4_AN'), '<b>' . sm_e($sm_cfg['udpport']) . '</b>'); ?>
<pre class="sm-pre"><?php
/* Die Befehlserkennung entsteht in sm_check() - derselben Funktion, aus der
 * auch die Vorlage schoepft. Bis 2.3.14 stand hier ein von Hand gebautes
 * Muster mit Schraegstrich und Komma; der UDP-Satz besteht aber aus Zeilen
 * der Form <serial>:<Feldname>:<Wert>. Es traf nichts. */
echo sm_e(sm_check($sm_cfg['serial'], sm_obis_feld($sm_cfg['channels'][0])));
?></pre>
<?php } else { ?>
<?php echo sm_t('LOX.S4_AUS'); ?>
<?php } ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S5_TITEL'); ?></b><br><br>
<?php echo sm_t('LOX.S5_TEXT'); ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S6_TITEL'); ?></b><br><br>
<?php echo sm_t('LOX.S6_TEXT'); ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?php echo sm_t('LOX.SP_BAUSTEIN'); ?></th><th><?php echo sm_t('LOX.SP_NAME'); ?></th><th><?php echo sm_t('LOX.SP_PARAMETER'); ?></th><th><?php echo sm_t('LOX.SP_EINGAENGE'); ?></th></tr>
<?php
/* Die drei Feldnamen der Zeilen 1 bis 3 kommen aus sm_obis_feld() - also
 * aus bin/sm_felder.json, derselben Datei, aus der auch die Tabelle in
 * Schritt 3 und der Dienst schoepfen. Bis 2.4.2 standen sie hier als
 * Literale und wichen bei jedem anderen Kanalsatz von der Tabelle
 * darueber ab, auf derselben Seite.
 *
 * Dazu die Gegenprobe, ob der Kanal ueberhaupt eingestellt ist: ein
 * Tarifzaehler ohne 16.7.0 bekommt sonst eine Zeile zum Nachbauen, die
 * nie einen Wert traegt. */
$sm_bl = sm_vz_felder($sm_cfg);
?>
<tr><td>1</td><td><?php echo sm_t('BAUSTEIN.VE'); ?></td><td><?php echo sm_e(sm_t('LOX.N_BEZUG')); ?></td><td><?php echo sm_t('ALLG.EINHEIT'); ?> <span class="sm-mono">&lt;v.3&gt; kWh</span></td><td><?php echo sm_quelle_mqtt('1-0:1.8.0', $sm_bl); ?></td></tr>
<tr><td>2</td><td><?php echo sm_t('BAUSTEIN.VE'); ?></td><td><?php echo sm_e(sm_t('LOX.N_EINSPEISUNG')); ?></td><td><?php echo sm_t('ALLG.EINHEIT'); ?> <span class="sm-mono">&lt;v.3&gt; kWh</span></td><td><?php echo sm_quelle_mqtt('1-0:2.8.0', $sm_bl); ?></td></tr>
<tr><td>3</td><td><?php echo sm_t('BAUSTEIN.VE'); ?></td><td><?php echo sm_e(sm_t('LOX.N_LEISTUNG')); ?></td><td><?php echo sm_t('ALLG.EINHEIT'); ?> <span class="sm-mono">&lt;v.3&gt; kW</span></td><td><?php echo sm_quelle_mqtt('1-0:16.7.0', $sm_bl); ?></td></tr>
<!-- ZAEHLER ist KEIN MQTT-Thema. Er steht ausschliesslich in der
     Schlusszeile des Endpunkts (Schritt 8); bis 2.4.2 stand hier "MQTT
     ZAEHLER", und weder der Dienst noch die Vorlage kennen ein solches
     Thema. Baustein #4 blieb damit ohne Wert - und mit ihm die ganze
     Kette #8 bis #12, also genau die Ausfallerkennung, fuer die dieser
     Schritt da ist. -->
<tr><td>4</td><td><?php echo sm_t('BAUSTEIN.VE_HTTP'); ?></td><td><?php echo sm_e(sm_t('LOX.N_ZAEHLWERK')); ?></td><td><?php echo sm_t('LOX.P_ZAEHLER'); ?></td><td><?php echo sm_t('LOX.Q_ENDPUNKT'); ?></td></tr>
<tr><td>5</td><td><?php echo sm_t('BAUSTEIN.ZAEHLER'); ?></td><td><?php echo sm_e(sm_t('LOX.N_VERBRAUCH_TAG')); ?></td><td><?php echo sm_t('LOX.P_MITTERNACHT'); ?></td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #1</td></tr>
<tr><td>6</td><td><?php echo sm_t('BAUSTEIN.STATISTIK'); ?></td><td><?php echo sm_e(sm_t('LOX.N_VERLAUF')); ?></td><td><?php echo sm_t('LOX.P_ANALOG'); ?></td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #3</td></tr>
<tr><td>7</td><td><?php echo sm_t('BAUSTEIN.VERGLEICHER'); ?></td><td><?php echo sm_e(sm_t('LOX.N_EINSPEISUNG_AKTIV')); ?></td><td><?php echo sm_t('LOX.P_SCHWELLE0'); ?></td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #3</td></tr>
<tr><td>8</td><td><?php echo sm_t('BAUSTEIN.ANALOGSPEICHER'); ?></td><td><?php echo sm_e(sm_t('LOX.N_VORWERT')); ?></td><td>&mdash;</td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #4</td></tr>
<tr><td>9</td><td><?php echo sm_t('BAUSTEIN.FORMEL'); ?></td><td><?php echo sm_e(sm_t('LOX.N_AENDERUNG')); ?></td><td><span class="sm-mono">ABS(I1-I2)</span></td><td>I1 = #4, I2 = #8</td></tr>
<tr><td>10</td><td><?php echo sm_t('BAUSTEIN.EVZ'); ?></td><td><?php echo sm_e(sm_t('LOX.N_SCHWEIGT')); ?></td><td><?php echo sm_t('LOX.P_VERZOEGERUNG'); ?> <b><?php echo (int) max(600, sm_alter_grenze() * 2); ?></b> s</td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #9 = 0</td></tr>
<tr><td>11</td><td><?php echo sm_t('BAUSTEIN.ODER'); ?></td><td><?php echo sm_e(sm_t('LOX.N_MELDUNGEN')); ?></td><td>&mdash;</td><td><?php echo sm_t('LOX.EINGAENGE'); ?> &larr; #10 &hellip;</td></tr>
<tr><td>12</td><td><?php echo sm_t('BAUSTEIN.BENACHRICHTIGUNG'); ?></td><td><?php echo sm_e(sm_t('LOX.N_ZAEHLER_PRUEFEN')); ?></td><td><?php echo sm_t('LOX.P_TEXT_FREI'); ?></td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #11</td></tr>
<tr><td>13 <i>(<?php echo sm_t('ALLG.OPTIONAL'); ?>)</i></td><td><?php echo sm_t('BAUSTEIN.STATUS'); ?></td><td><?php echo sm_e(sm_t('LOX.N_AKTUELL')); ?></td><td><?php echo sm_t('LOX.P_STATUSTEXT'); ?></td><td>v1 = #3, v2 = #1</td></tr>
</table>
</div>
<br>
<b><?php echo sm_t('LOX.S6_STATUSTEXT'); ?></b>
<pre class="sm-pre">&lt;v1.3&gt; kW &middot; <?php echo sm_t('OBIS.ZAEHLERSTAND'); ?> &lt;v2.3&gt; kWh</pre>
<b><?php echo sm_t('LOX.S6_ZU4'); ?></b> <?php echo sm_t('LOX.S6_ZU4_TEXT'); ?><br>
<b><?php echo sm_t('LOX.S6_ZU10'); ?></b> <?php echo sm_t('LOX.S6_ZU10_TEXT'); ?><br>
<b><?php echo sm_t('LOX.S6_ZU1112'); ?></b> <?php echo sm_t('LOX.S6_ZU1112_TEXT'); ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S7_TITEL'); ?></b><br><br>
<?php printf(sm_t('LOX.S7_TEXT'), '<span class="sm-mono">"last"</span>'); ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S8_TITEL'); ?></b><br><br>
<?php echo sm_t('LOX.S8_TEXT'); ?>
<pre class="sm-pre"><?php echo sm_e($sm_endpunkt); ?></pre>
<p class="sm-small"><?php echo sm_t('LOX.S8_SELFTEST'); ?></p>
<pre class="sm-pre"><?php echo sm_e($sm_endpunkt_selftest); ?></pre>
<?php
/* Die Felder stehen in derselben Reihenfolge wie in
 * webfrontend/html/index.php. GRENZE fehlte hier bis 2.4.2 - der
 * Endpunkt sendet es, das Beispiel zum Abschreiben nannte es nicht.
 *
 * Der Kommentar steht VOR dem Aufruf, nicht darin: Werkzeuge/
 * sprachplatzhalter_pruefen.py zaehlt die Argumente an den Kommata, und
 * ein Komma im Kommentar wird dort zu einem zweiten Argument. */
$sm_zustandszeile = '<span class="sm-mono">'
                  . 'SMARTMETER;OK=1;ALTER=42;ZAEHLER=137;KOEPFE=1;GRENZE=300'
                  . '</span>';
?>
<p class="sm-small"><?php printf(sm_t('LOX.S8_ZEILE'), $sm_zustandszeile); ?></p>
<?php if ($sm_token === '') { ?>
<div class="sm-alert sm-warn"><?php echo sm_t('LOX.TOKEN_OFFEN'); ?></div>
<div class="sm-knopfreihe">
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<?php echo sm_fmt(); ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="lox_token_neu" value="1"><?php echo sm_e(sm_t('LOX.TOKEN_SETZEN')); ?></button>
</form>
</div>
<?php } else { ?>
<div class="sm-alert sm-ok"><?php echo sm_t('LOX.TOKEN_AKTIV'); ?></div>
<div class="sm-knopfreihe">
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<?php echo sm_fmt(); ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="lox_token_neu" value="1"><?php echo sm_e(sm_t('LOX.TOKEN_ERNEUERN')); ?></button>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="lox_token_weg" value="1"><?php echo sm_e(sm_t('LOX.TOKEN_ENTFERNEN')); ?></button>
</form>
</div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.TOKEN'); ?></span>
</div>
</div>
</div>

<!-- ================================= Test ================================= -->
<div class="sm-pane<?php echo $sm_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">

<?php if ($sm_test_titel !== '') { ?>
<div class="sm-alert sm-ok"><b><?php echo $sm_test_titel; ?></b></div>
<?php echo $sm_test_text; ?>
<?php } ?>

<h2><?php echo sm_t('TEST.H_SELBST'); ?></h2>
<?php if ($sm_pruef) {
    $sm_striche = 0;
    foreach ($sm_pruef as $z) { if ($z[1] === 2) { $sm_striche++; } } ?>
<div class="sm-breit">
<table class="sm-tbl sm-diag">
<?php foreach ($sm_pruef as $z) {
    $sm_zn = ($z[1] === 1) ? '&#10004;' : (($z[1] === 2) ? '&ndash;' : '&#10008;');
    $sm_fb = ($z[1] === 1) ? '#1a7f1a' : (($z[1] === 2) ? '#666' : '#b00000'); ?>
<tr><td><?php echo sm_e($z[0]); ?></td>
    <td style="color:<?php echo $sm_fb; ?>"><?php echo $sm_zn; ?></td>
    <td><?php echo sm_e($z[2]); ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-small"><?php printf(sm_t('TEST.STRICHE'), count($sm_pruef), $sm_striche); ?></p>
<?php } ?>

<h2><?php echo sm_t('TEST.H_DIAGNOSE'); ?></h2>
<?php if ($sm_diag) { ?>
<div class="sm-breit">
<table class="sm-tbl sm-diag">
<?php foreach ($sm_diag as $z) { ?>
<tr><td><?php echo sm_e($z[0]); ?></td>
    <td style="color:<?php echo sm_farbe($z[1]); ?>"><?php echo sm_zeichen($z[1]); ?></td>
    <td><?php echo sm_e($z[2]); ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<h2><?php echo sm_t('TEST.H_NACHSEHEN'); ?></h2>
<div class="sm-knopfreihe">
<?php foreach (array('umgebung' => sm_t('TEST.K_UMGEBUNG'),
                     'http'     => sm_t('TEST.K_HTTP'),
                     'roh'      => sm_t('TEST.K_ROH'),
                     'legacy'   => sm_t('TEST.K_LEGACY'),
                     'mitschnitt' => sm_t('TEST.K_MITSCHNITT')) as $wert => $text) { ?>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <?php echo sm_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="<?php echo sm_e($wert); ?>"><?php
      echo $text; ?></button>
  </form>
<?php } ?>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo sm_t('LEGENDE.LESEN'); ?></span>
</div>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="sm-pane<?php echo $sm_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo sm_t('LOG.H_PROTOKOLLE'); ?></h2>
<p class="sm-small"><?php printf(sm_t('LOG.HINT'),
  '<span class="sm-mono">vzlogger.log</span>',
  '<span class="sm-mono">vzlogger_fetch.log</span>'); ?></p>
<div class="sm-alert sm-info"><?php echo sm_t('LOG.RAMDISK'); ?></div>
<div class="sm-log"><?php echo sm_e($sm_logtext); ?></div>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo '<h2>' . sm_t('LOG.H_LOXBERRY') . '</h2>';
    echo LBWeb::loglist_html();
}
?>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
    var reiter = document.querySelectorAll('.sm-tab[data-ziel]');
    var seiten = document.querySelectorAll('.sm-pane');
    function zeige(ziel) {
        for (var i = 0; i < reiter.length; i++) {
            reiter[i].classList.toggle('sm-active',
                reiter[i].getAttribute('data-ziel') === ziel);
        }
        for (var j = 0; j < seiten.length; j++) {
            seiten[j].classList.toggle('sm-active', seiten[j].id === ziel);
        }
        var f = document.querySelectorAll('input[name="activetab"]');
        for (var k = 0; k < f.length; k++) { f[k].value = ziel; }
    }
    for (var k = 0; k < reiter.length; k++) {
        reiter[k].addEventListener('click', function (e) {
            // Reiter, deren Inhalt der Server erst berechnet, laden neu.
            if (this.getAttribute('data-neuladen') === '1') { return; }
            e.preventDefault();
            zeige(this.getAttribute('data-ziel'));
        });
    }
    zeige(<?php echo json_encode($sm_tab); ?>);
})();
</script>

<?php LBWeb::lbfooter(); ?>
