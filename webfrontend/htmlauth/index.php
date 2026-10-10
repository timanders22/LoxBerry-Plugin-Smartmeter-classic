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
/* SEIT DEM DURCHGANG 01.10.2026:
 * O6  $sm_teilfehler - was NACH erfolgreichem Schreiben scheiterte (eigener
 *     Kasten; bis 2.8.5 stand es unter "Nicht gespeichert:").
 * O2  $sm_eingaben   - das beanstandete Formular samt Eingaben (X-2).
 * O1  $sm_ist_post   - ob die Anfrage ein POST WAR, auch wenn der Wachposten
 *     sie abweist: jeder POST endet mit einer Umleitung. */
$sm_teilfehler = array();
$sm_eingaben = null;
$sm_ist_post = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST');

/** X-2: die Felder eines beanstandeten Formulars als Text (nur gueltiges
 *  UTF-8, hoechstens 2100 Byte; eine Liste reist nicht mit). Geheimnisse hat
 *  keines der Formulare dieser Linie. */
function sm_eingaben_sammeln($formular, $felder, $falsch)
{
    $werte = array();
    foreach ($felder as $f) {
        if (!isset($_POST[$f]) || !is_string($_POST[$f])) {
            continue;
        }
        if (strlen($_POST[$f]) <= 2100 && preg_match('//u', $_POST[$f])) {
            $werte[$f] = $_POST[$f];
        }
    }
    return array('formular' => $formular, 'werte' => $werte,
                 'falsch' => array_values(array_unique($falsch)));
}
/** X-2: Ist dieses Formular das beanstandete? */
function sm_fa($formular)
{
    global $sm_eingaben;
    return is_array($sm_eingaben) && isset($sm_eingaben['formular'])
        && $sm_eingaben['formular'] === $formular;
}
/** X-2: Wert eines Feldes - nach einer Beanstandung die Eingabe, sonst der gespeicherte. */
function sm_fw($formular, $feld, $gespeichert)
{
    global $sm_eingaben;
    if (sm_fa($formular) && isset($sm_eingaben['werte'][$feld])
        && is_string($sm_eingaben['werte'][$feld])) {
        return $sm_eingaben['werte'][$feld];
    }
    return (string) $gespeichert;
}
/** X-2: Haken - nach einer Beanstandung so, wie er abgeschickt wurde. */
function sm_fh($formular, $feld, $gespeichert)
{
    global $sm_eingaben;
    if (!sm_fa($formular)) {
        return (bool) $gespeichert;
    }
    return isset($sm_eingaben['werte'][$feld]);
}
/** X-2: class- und aria-Attribut eines Feldes; beanstandet = rot markiert. */
function sm_fm($feld, $klasse = '')
{
    global $sm_eingaben;
    $falsch = is_array($sm_eingaben) && isset($sm_eingaben['falsch'])
        && is_array($sm_eingaben['falsch']) && in_array($feld, $sm_eingaben['falsch'], true);
    $k = trim($klasse . ($falsch ? ' sm-beanstandet' : ''));
    return ($k !== '' ? ' class="' . $k . '"' : '') . ($falsch ? ' aria-invalid="true"' : '');
}
/** X-2: eine eingetippte Auswahl, die es nicht gibt, bleibt als markierte Option sichtbar. */
function sm_fsel_extra($formular, $feld, $optionen)
{
    global $sm_eingaben;
    if (!sm_fa($formular) || !isset($sm_eingaben['werte'][$feld])) {
        return '';
    }
    $v = (string) $sm_eingaben['werte'][$feld];
    foreach ($optionen as $o) {
        if ((string) $o === $v) {
            return '';
        }
    }
    return '<option value="' . sm_e($v) . '" selected>' . sm_e($v) . ' (' . sm_t('ALLG.UNGUELTIG') . ')</option>';
}

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
            $sm_mq_alt = sm_legacy_read();
            list($sm_ok, $sm_hin) = sm_sichern_uebernehmen($sm_neu);
            $sm_notizen = array_merge($sm_notizen, $sm_hin);
            if (!$sm_ok) {
                $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN_TEIL'),
                    '<span class="sm-mono">smartmeter.cfg</span>');
            } else {
                $sm_cfg    = sm_vz_read();
                $sm_legacy = sm_legacy_read();
                /* Den Dienst nachziehen UND sagen, was mit ihm geschehen ist -
                 * nach seiner WIRKUNG (C3, Durchgang 01.10.2026). */
                $sm_was = '';
                if ($sm_cfg['enabled']) {
                    $sm_r = sm_vz_restart($sm_cfg);
                    if ($sm_r['lage'] === 'gestartet') {
                        $sm_was = sm_t('SICH.DIENST_NEU');
                    } else {
                        $sm_teilfehler[] = sm_e($sm_r['text']);
                    }
                } else {
                    $sm_was = sm_t('SICH.DIENST_AUS');
                }
                list($sm_cok, $sm_ctext) = sm_cron_setzen($sm_legacy['READ'] === '1',
                                                          $sm_legacy['CRON']);
                /* O6 (Durchgang 01.10.2026): Erfolg und Teilfehler stehen in
                 * ZWEI Kaesten. Bis 2.8.5 verschluckte ein gescheiterter
                 * Cron-Eintrag die ganze Erfolgsmeldung, und unter "Nicht
                 * gespeichert:" stand eine uebernommene Sicherung (gemessen,
                 * Oberflaechenbericht O6 b). */
                $sm_meldung = trim(sm_t('SICH.UEBERNOMMEN') . ' ' . $sm_was);
                if ($sm_cok) {
                    $sm_meldung .= ' ' . $sm_ctext;
                } else {
                    $sm_teilfehler[] = $sm_ctext;
                }
                /* M3: ein Praefixwechsel oder "MQTT aus" aus der Sicherung
                 * raeumt ab wie der Reiter MQTT. */
                $sm_mq = sm_mqtt_nach_aenderung($sm_mq_alt, $sm_legacy);
                $sm_notizen = array_merge($sm_notizen, $sm_mq['ok']);
                $sm_teilfehler = array_merge($sm_teilfehler, $sm_mq['fehler']);
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
 *
 * SEIT DEM DURCHGANG 01.10.2026 gilt fuer jedes Formular (Entscheidungen
 * 16 und 19, Regeln/04): bei einer Beanstandung wird NICHTS gespeichert,
 * die Eingaben kommen markiert zurueck (X-2), und nichts wird still ersetzt
 * oder entfernt - ausser Leerraum am Rand.
 * ---------------------------------------------------------------- */
if (isset($_POST['vz_speichern'])) {
    $neu = $sm_cfg;
    $sm_falsch = array();
    /* O8: eine beschaedigte vzlogger.json zeigt die Vorgaben. Speichern
     * hiesse, die echte Einstellung mit der Vorgabe zu ueberschreiben. */
    if (sm_vz_lage() === 'kaputt') {
        $sm_fehler[] = sprintf(sm_t('FEHLER.VZ_KAPUTT'),
            '<span class="sm-mono">' . sm_e($sm_p['vzjson']) . '</span>');
    }
    $neu['enabled'] = isset($_POST['vz_enabled']) ? 1 : 0;
    $neu['device']  = (isset($_POST['vz_device']) && is_string($_POST['vz_device']))
                    ? trim($_POST['vz_device']) : '';

    // Zwei Leser koennen sich eine serielle Schnittstelle nicht teilen.
    // Diese Pruefung sass bis 2.3.14 NUR im Legacy-Handler; wer hier
    // speicherte, waehrend der klassische Leser lief, bekam zwei Prozesse an
    // einem Geraet. Die Pruefung gehoert in BEIDE Handler.
    if ($neu['enabled'] && sm_legacy_aktiv()) {
        $sm_fehler[] = sm_t('FEHLER.BEIDE_LESER_VZ');
        $sm_falsch[] = 'vz_enabled';
    }

    /* O3: ein unbekanntes Protokoll wurde bis 2.8.5 still zu "sml". */
    $prot = (isset($_POST['vz_protocol']) && is_string($_POST['vz_protocol']))
          ? trim($_POST['vz_protocol']) : '';
    if (!in_array($prot, array('sml', 'd0'), true)) {
        $sm_fehler[] = sprintf(sm_t('FEHLER.PROTOKOLL'), sm_e($prot));
        $sm_falsch[] = 'vz_protocol';
    } else {
        $neu['protocol'] = $prot;
    }

    $baud = (isset($_POST['vz_baudrate']) && is_string($_POST['vz_baudrate']))
          ? trim($_POST['vz_baudrate']) : '';
    if (!preg_match('/^[0-9]+$/', $baud) || (int) $baud < 300 || (int) $baud > 921600) {
        $sm_fehler[] = sm_t('FEHLER.BAUDRATE');
        $sm_falsch[] = 'vz_baudrate';
    } else {
        $neu['baudrate'] = (int) $baud;
    }

    $par = (isset($_POST['vz_parity']) && is_string($_POST['vz_parity'])) ? $_POST['vz_parity'] : '';
    if (!in_array($par, array('8n1', '7n1', '7e1', '8e1'), true)) {
        $sm_fehler[] = sm_t('FEHLER.RAHMUNG');
        $sm_falsch[] = 'vz_parity';
    } else {
        $neu['parity'] = $par;
    }

    /* O3: alles ausser "0" war bis 2.8.5 still "1". */
    $lt = (isset($_POST['vz_localtime']) && is_string($_POST['vz_localtime'])) ? $_POST['vz_localtime'] : '';
    if ($lt !== '0' && $lt !== '1') {
        $sm_fehler[] = sm_t('FEHLER.ZEITSTEMPEL');
        $sm_falsch[] = 'vz_localtime';
    } else {
        $neu['localtime'] = (int) $lt;
    }
    $neu['sendudp']   = isset($_POST['vz_sendudp']) ? 1 : 0;

    foreach (array('udpport' => 'vz_udpport', 'httpport' => 'vz_httpport') as $k => $feld) {
        $w = (isset($_POST[$feld]) && is_string($_POST[$feld])) ? trim($_POST[$feld]) : '';
        if (!preg_match('/^[0-9]+$/', $w) || (int) $w < 1 || (int) $w > 65535) {
            $sm_fehler[] = sprintf(sm_t('FEHLER.PORT'),
                                   $k === 'udpport' ? 'UDP' : 'HTTP');
            $sm_falsch[] = $feld;
        } else {
            $neu[$k] = (int) $w;
        }
    }

    /* Zaehlernummer: erscheint im MQTT-Thema und im UDP-Satz. O3
     * (Entscheidung 19): bis 2.8.5 wurden fremde Zeichen still entfernt
     * ("Zaehler 1/2" -> "Zhler12") und ein leeres Feld still zu "vzlogger" -
     * jetzt beanstandet. */
    $ser = (isset($_POST['vz_serial']) && is_string($_POST['vz_serial'])) ? trim($_POST['vz_serial']) : '';
    if ($ser === '') {
        $sm_fehler[] = sm_t('FEHLER.SERIAL_LEER');
        $sm_falsch[] = 'vz_serial';
    } elseif (!preg_match('/^[A-Za-z0-9_\-]+$/', $ser)) {
        $sm_fehler[] = sprintf(sm_t('FEHLER.SERIAL'), sm_e($ser));
        $sm_falsch[] = 'vz_serial';
    } else {
        $neu['serial'] = $ser;
    }

    // OBIS-Kanaele: einer je Zeile (oder durch Komma getrennt)
    $kanaele = array();
    $kanal_falsch = false;
    $kroh = (isset($_POST['vz_channels']) && is_string($_POST['vz_channels'])) ? $_POST['vz_channels'] : '';
    foreach (preg_split('/[\r\n,]+/', $kroh) as $c) {
        $c = trim($c);
        if ($c === '') { continue; }
        if (!preg_match('/^[\d\.:\-\*]+$/', $c)) {
            $sm_fehler[] = sprintf(sm_t('FEHLER.OBIS'),
                                   '<span class="sm-mono">' . sm_e($c) . '</span>');
            $kanal_falsch = true;
            continue;
        }
        $kanaele[] = $c;
    }
    /* O3: eine leere Liste wurde bis 2.8.5 still zu den drei Vorgaben. */
    if (!$kanaele && !$kanal_falsch) {
        $sm_fehler[] = sm_t('FEHLER.KANAELE_LEER');
        $kanal_falsch = true;
    }
    if ($kanal_falsch) {
        $sm_falsch[] = 'vz_channels';
    }
    $neu['channels'] = $kanaele;
    $neu['uuids']    = sm_vz_uuids($kanaele);

    if (!$sm_fehler) {
        if (sm_vz_write($neu)) {
            $sm_cfg = sm_vz_read();
            sm_log('vzLogger-Einstellungen gespeichert.');
            /* C6: ohne neue vzlogger.conf wird nicht neu gestartet - er liefe
             * mit der alten. Bis 2.8.5 wurde der Rueckgabewert verworfen. */
            if (!sm_vz_conf_schreiben($sm_cfg)) {
                $sm_meldung = sm_t('MELD.VZ_GESPEICHERT');
                $sm_teilfehler[] = sm_t('FEHLER.VZ_CONF');
            } else {
                $sm_r = sm_vz_restart($sm_cfg);
                if ($sm_r['lage'] === 'gestartet') {
                    $sm_meldung = sm_t('MELD.VZ_GESPEICHERT_NEUSTART');
                } elseif ($sm_r['lage'] === 'angehalten') {
                    $sm_meldung = sm_t('MELD.VZ_GESPEICHERT_ANGEHALTEN');
                } else {
                    $sm_meldung = sm_t('MELD.VZ_GESPEICHERT');
                    $sm_teilfehler[] = sm_e($sm_r['text']);
                }
            }
        } else {
            $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN_RECHTE'),
                                   '<span class="sm-mono">vzlogger.json</span>');
        }
    } else {
        $sm_eingaben = sm_eingaben_sammeln('vz', array('vz_enabled', 'vz_device', 'vz_protocol',
            'vz_baudrate', 'vz_parity', 'vz_localtime', 'vz_channels', 'vz_serial', 'vz_sendudp',
            'vz_udpport', 'vz_httpport'), $sm_falsch);
    }
    $sm_tab = 'tab-vzlogger';
}

if (isset($_POST['vz_install'])) {
    $sm_installout = sm_vz_install();
    sm_cache_verwerfen();
    $sm_tab = 'tab-vzlogger';
}

if (isset($_POST['vz_neustart'])) {
    /* C3/O5: die Meldung nach der Wirkung. Bis 2.8.5 hiess es bei
     * ausgeschalteter Betriebsart "vzlogger wurde neu gestartet". */
    $sm_r = sm_vz_restart($sm_cfg);
    if ($sm_r['lage'] === 'gestartet') {
        $sm_meldung = sm_t('MELD.VZ_NEUSTART');
    } elseif ($sm_r['lage'] === 'angehalten') {
        $sm_meldung = sm_t('MELD.VZ_ANGEHALTEN');
    } else {
        $sm_teilfehler[] = sm_e($sm_r['text']);
    }
    $sm_tab = 'tab-vzlogger';
}

if (isset($_POST['mq_speichern'])) {
    /* M2/O3 (Entscheidung 19): bis 2.8.5 wurden Steuerzeichen,
     * Anfuehrungszeichen und Leerraum still entfernt, ein leeres Feld still
     * zu "smartmeter", und # und + angenommen - in einem PUBLISH-Thema
     * unzulaessig. Jetzt EINE Regel fuer Formular und Zurueckspielen
     * (smg_praefix_fehler); still bleibt nur Leerraum am Rand. */
    $t = (isset($_POST['mq_topic']) && is_string($_POST['mq_topic'])) ? trim($_POST['mq_topic']) : '';
    $sm_pf = smg_praefix_fehler($t);
    if ($sm_pf !== '') {
        $sm_fehler[] = sm_praefix_text($sm_pf);
        $sm_eingaben = sm_eingaben_sammeln('mq', array('mq_an', 'mq_topic'), array('mq_topic'));
    } else {
        $sm_mq_alt = sm_legacy_read();
        // Abschnittsbewusst schreiben: seit die Lesekoepfe eigene Abschnitte
        // haben, darf nicht mehr zeilenweise nach dem Schluessel gesucht werden.
        if (sm_cfg_set('MAIN', array('SENDMQTT' => isset($_POST['mq_an']) ? '1' : '0',
                                     'MQTTTOPIC' => $t))) {
            $sm_legacy = sm_legacy_read();
            sm_log('MQTT-Einstellungen gespeichert (Thema ' . $t . ').');
            $sm_meldung = sm_t('MELD.MQTT_GESPEICHERT');
            /* M3: Praefixwechsel oder "MQTT aus" - retained Zustaende unter
             * dem bisherigen Praefix abraeumen und nachlesen. */
            $sm_mq = sm_mqtt_nach_aenderung($sm_mq_alt, $sm_legacy);
            $sm_notizen = array_merge($sm_notizen, $sm_mq['ok']);
            $sm_teilfehler = array_merge($sm_teilfehler, $sm_mq['fehler']);
        } else {
            $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN'),
                                   '<span class="sm-mono">smartmeter.cfg</span>');
        }
    }
    $sm_tab = 'tab-mqtt';
}

if (isset($_POST['test']) && is_string($_POST['test'])) {
    list($sm_test_titel, $sm_test_text) = sm_test_ausfuehren($_POST['test'], $sm_cfg);
    $sm_tab = 'tab-test';
}

/* ---------------------------------------------------------------- *
 * Fahrplan-Abgleich (2.7.0)
 * ---------------------------------------------------------------- */
if (isset($_POST['ab_speichern'])) {
    $sm_ab_url = (isset($_POST['ab_url']) && is_string($_POST['ab_url']))
        ? trim($_POST['ab_url']) : '';
    $sm_ab_an  = isset($_POST['ab_aktiv']);
    /* Die Adresse wird ABGEWIESEN, nicht zurechtgebogen. Nur http und
     * https: ein file:// oder ein Pfad waere ein Weg, dem Plugin eine
     * beliebige Datei unterzuschieben. */
    if ($sm_ab_url !== '' && !preg_match('#^https?://#i', $sm_ab_url)) {
        $sm_fehler[] = sm_t('AB.FEHLER_URL');
        $sm_eingaben = sm_eingaben_sammeln('ab', array('ab_aktiv', 'ab_url'), array('ab_url'));
    } elseif ($sm_ab_an && $sm_ab_url === '') {
        // Einschalten ohne Adresse waere ein Schalter ohne Wirkung.
        $sm_fehler[] = sm_t('AB.FEHLER_LEER');
        $sm_eingaben = sm_eingaben_sammeln('ab', array('ab_aktiv', 'ab_url'), array('ab_url'));
    } elseif (!sm_cfg_set('ABGLEICH', array(
            'AKTIV' => $sm_ab_an ? '1' : '0', 'FAHRPLAN_URL' => $sm_ab_url))) {
        $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN_TEIL'),
                               '<span class="sm-mono">smartmeter.cfg</span>');
    } else {
        $sm_meldung = sm_t('MELD.GESPEICHERT');
    }
    $sm_tab = 'tab-loxone';
}

/* ---------------------------------------------------------------- *
 * Kostenrechnung (2.8.0)
 * ---------------------------------------------------------------- */
if (isset($_POST['ko_speichern'])) {
    $sm_ko_url = (isset($_POST['ko_url']) && is_string($_POST['ko_url']))
        ? trim($_POST['ko_url']) : '';
    $sm_ko_an  = isset($_POST['ko_aktiv']);
    /* Dieselbe Abweisung wie beim Fahrplan: nur http und https. Ein
     * file:// waere ein Weg, dem Plugin eine beliebige Datei
     * unterzuschieben. */
    if ($sm_ko_url !== '' && !preg_match('#^https?://#i', $sm_ko_url)) {
        $sm_fehler[] = sm_t('KO.FEHLER_URL');
        $sm_eingaben = sm_eingaben_sammeln('ko', array('ko_aktiv', 'ko_url'), array('ko_url'));
    } elseif ($sm_ko_an && $sm_ko_url === ''
              && trim(sm_cfg_get(sm_cfg_read(), 'ABGLEICH', 'FAHRPLAN_URL', '')) === '') {
        /* Leer heisst "nimm die Adresse des Fahrplans" - steht dort auch
         * nichts, waere das Einschalten ein Schalter ohne Wirkung. */
        $sm_fehler[] = sm_t('KO.FEHLER_LEER');
        $sm_eingaben = sm_eingaben_sammeln('ko', array('ko_aktiv', 'ko_url'), array('ko_url'));
    } elseif (!sm_cfg_set('KOSTEN', array(
            'AKTIV' => $sm_ko_an ? '1' : '0', 'PREIS_URL' => $sm_ko_url))) {
        $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN_TEIL'),
                               '<span class="sm-mono">smartmeter.cfg</span>');
    } else {
        $sm_meldung = sm_t('MELD.GESPEICHERT');
    }
    $sm_tab = 'tab-loxone';
}

/* ---------------------------------------------------------------- *
 * Legacy-Leser
 * ---------------------------------------------------------------- */
$sm_lg_ausgabe = '';

if (isset($_POST['lg_speichern'])) {
    /* O4 (Durchgang 01.10.2026, Entscheidung 16): ERST alles pruefen, DANN
     * einmal schreiben. Bis 2.8.5 schrieb dieser Handler MAIN, bevor er die
     * Profile pruefte - ein unbekanntes Profil meldete "Nicht gespeichert",
     * waehrend CRON, UDPPORT und NAME schon in der Datei standen und der
     * Cron-Eintrag nicht nachgezogen wurde (gemessen, Oberflaechenbericht O4). */
    $sm_falsch = array();
    $lesen = isset($_POST['lg_read']);
    $takt  = (isset($_POST['lg_cron']) && is_string($_POST['lg_cron'])) ? $_POST['lg_cron'] : '';
    if (!array_key_exists($takt, sm_takte())) {
        $sm_fehler[] = sm_t('FEHLER.TAKT');
        $sm_falsch[] = 'lg_cron';
    }
    $port = (isset($_POST['lg_udpport']) && is_string($_POST['lg_udpport'])) ? trim($_POST['lg_udpport']) : '';
    if (!preg_match('/^[0-9]+$/', $port) || (int) $port < 1 || (int) $port > 65535) {
        $sm_fehler[] = sm_t('FEHLER.LG_UDPPORT');
        $sm_falsch[] = 'lg_udpport';
    }
    // Zwei Leser koennen sich eine serielle Schnittstelle nicht teilen.
    if ($lesen && $sm_cfg['enabled']) {
        $sm_fehler[] = sm_t('FEHLER.BEIDE_LESER');
        $sm_falsch[] = 'lg_read';
    }

    // Je Lesekopf Bezeichnung und Profil - gesammelt, nicht geschrieben.
    $sm_alles = sm_cfg_read();
    $profile = sm_profile();
    $sm_felder = array('lg_read', 'lg_cron', 'lg_sendudp', 'lg_udpport');
    foreach (sm_koepfe() as $k) {
        $s = $k['ABSCHNITT'];
        $sm_felder[] = 'lg_' . $s . '_name';
        $sm_felder[] = 'lg_' . $s . '_meter';
        if (isset($_POST['lg_' . $s . '_name']) && is_string($_POST['lg_' . $s . '_name'])) {
            $sm_alles[$s]['NAME'] = trim($_POST['lg_' . $s . '_name']);
        }
        if (isset($_POST['lg_' . $s . '_meter'])) {
            $m = is_string($_POST['lg_' . $s . '_meter']) ? $_POST['lg_' . $s . '_meter'] : '';
            if (!array_key_exists($m, $profile)) {
                $sm_fehler[] = sprintf(sm_t('FEHLER.PROFIL'), sm_e($s));
                $sm_falsch[] = 'lg_' . $s . '_meter';
            } else {
                $sm_alles[$s]['METER'] = $m;
            }
        }
    }

    if (!$sm_fehler) {
        if (!isset($sm_alles['MAIN']) || !is_array($sm_alles['MAIN'])) {
            $sm_alles['MAIN'] = array();
        }
        $sm_alles['MAIN']['READ']    = $lesen ? '1' : '0';
        $sm_alles['MAIN']['CRON']    = $takt;
        $sm_alles['MAIN']['SENDUDP'] = isset($_POST['lg_sendudp']) ? '1' : '0';
        $sm_alles['MAIN']['UDPPORT'] = $port;
        // EIN Schreibvorgang fuer MAIN und alle Koepfe.
        if (!sm_cfg_write($sm_alles)) {
            $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN'),
                                   '<span class="sm-mono">smartmeter.cfg</span>');
        } else {
            list($cron_ok, $cron_text) = sm_cron_setzen($lesen, $takt);
            $sm_legacy = sm_legacy_read();
            sm_cache_verwerfen();
            $sm_meldung = sm_t('MELD.GESPEICHERT');
            if ($cron_ok) {
                $sm_meldung .= ' ' . $cron_text;
            } else {
                /* Die Konfiguration IST geschrieben - nur der Cron-Eintrag
                 * fehlt. O6: das steht im eigenen Kasten, nicht unter
                 * "Nicht gespeichert:". Die Zeile "Cron-Eintrag" im Reiter
                 * Test zeigt denselben Widerspruch. */
                $sm_teilfehler[] = sm_t('CRON.NUR_EINTRAG') . ' ' . $cron_text;
            }
        }
    } else {
        $sm_eingaben = sm_eingaben_sammeln('lg', $sm_felder, $sm_falsch);
    }
    $sm_tab = 'tab-legacy';
}

if (isset($_POST['lg_abfragen'])) {
    $sm_lg_ausgabe = sm_manuell_abfragen();
    $sm_tab = 'tab-legacy';
}

if (isset($_POST['lg_suchlauf'])) {
    $sm_dev = (isset($_POST['lg_such_device']) && is_string($_POST['lg_such_device'])) ? $_POST['lg_such_device'] : '';
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

/* ==================================================================
 * PRG: JEDER POST ENDET MIT EINER UMLEITUNG (O1, Durchgang 01.10.2026)
 * ==================================================================
 *
 * Regeln/04, Entscheidung 19. Bis 2.8.5 antwortete jeder POST mit 200 und
 * der fertigen Seite: F5 schickte "Token neu" ein zweites Mal und machte
 * die Adresse im Miniserver erneut ungueltig; ebenso wiederholte es die
 * Paketinstallation, den Neustart und die serielle Abfrage (gemessen,
 * Oberflaechenbericht O1). Was der Handler zu sagen hat, reist in der
 * Einmalmeldung. Die Downloads (Vorlage, Sicherung) haben ihre Datei oben
 * schon geliefert. Laesst sich die Einmalmeldung nicht schreiben, wird wie
 * bisher direkt gezeigt - eine verlorene Meldung waere schlimmer als ein
 * F5-Risiko.
 * ================================================================== */
if ($sm_ist_post) {
    $sm_inhalt = array('tab' => $sm_tab, 'meldung' => $sm_meldung, 'fehler' => $sm_fehler,
                       'teilfehler' => $sm_teilfehler, 'hinweis' => $sm_hinweis,
                       'notizen' => $sm_notizen, 'test_titel' => $sm_test_titel,
                       'test_text' => $sm_test_text, 'installout' => $sm_installout,
                       'lg_ausgabe' => $sm_lg_ausgabe, 'suchzeilen' => $sm_suchzeilen,
                       'suchvorschlag' => $sm_suchvorschlag, 'eingaben' => $sm_eingaben);
    if (sm_flash_schreiben($sm_inhalt)) {
        header('Location: index.php?tab=' . rawurlencode(substr($sm_tab, 4)), true, 303);
        exit;
    }
} else {
    $sm_flash = sm_flash_lesen();
    if ($sm_flash) {
        if (isset($sm_flash['tab']) && is_string($sm_flash['tab'])
            && in_array($sm_flash['tab'], $sm_reiter, true)) {
            $sm_tab = $sm_flash['tab'];
        }
        foreach (array('meldung', 'hinweis', 'test_titel', 'test_text', 'installout',
                       'lg_ausgabe', 'suchvorschlag') as $sm_fk) {
            if (isset($sm_flash[$sm_fk]) && is_string($sm_flash[$sm_fk])) {
                ${'sm_' . $sm_fk} = $sm_flash[$sm_fk];
            }
        }
        foreach (array('fehler', 'teilfehler', 'notizen', 'suchzeilen') as $sm_fk) {
            if (isset($sm_flash[$sm_fk]) && is_array($sm_flash[$sm_fk])) {
                foreach ($sm_flash[$sm_fk] as $sm_ft) {
                    if (is_string($sm_ft)) {
                        ${'sm_' . $sm_fk}[] = $sm_ft;
                    }
                }
            }
        }
        if (isset($sm_flash['eingaben']) && is_array($sm_flash['eingaben'])) {
            $sm_eingaben = $sm_flash['eingaben'];
        }
    }
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
/* C1 (Durchgang 01.10.2026): das Token kodiert. Bis 2.8.5 stand es roh in
 * der Adresse zum Abschreiben - mit "&" darin ergab sie 403 (gemessen,
 * Codebericht Nr. 6, S2). Das Zurueckspielen nimmt solche Tokens jetzt
 * nicht mehr an; die Kodierung haelt auch einen Altbestand abschreibbar. */
$sm_endpunkt = 'http://' . $sm_wirt . '/plugins/' . $sm_p['plugin'] . '/index.php'
    . ($sm_token !== '' ? '?token=' . rawurlencode($sm_token) : '');
$sm_endpunkt_selftest = 'http://' . $sm_wirt . '/plugins/' . $sm_p['plugin']
    . '/index.php?selftest=1' . ($sm_token !== '' ? '&token=' . rawurlencode($sm_token) : '');
/* Der Lastgang - dasselbe Bauteil, dasselbe Token. Eine Adresse, die
 * angezeigt wird, damit jemand sie abschreibt, traegt JEDEN Parameter,
 * den der eigene Endpunkt verlangt. */
$sm_lastgang_url = 'http://' . $sm_wirt . '/plugins/' . $sm_p['plugin']
    . '/lastgang.php' . ($sm_token !== '' ? '?token=' . rawurlencode($sm_token) : '');
$sm_lastgang = sm_lastgang_lage();

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
/* X-2 (Durchgang 01.10.2026): ein beanstandetes Feld. */
.sm-wrap .sm-beanstandet { border: 2px solid #b00000 !important; background: #fff4f4 !important; }
.sm-rot { background: #b00000; }
/* Ergaenzung (Welle Bild, Entscheidung 45): Bild der Bausteine aus dem gemeinsamen Musterprojekt. */
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
</style>

<div class="sm-wrap">

<?php if ($sm_fehler) { ?>
<div class="sm-alert sm-warn"><b><?php echo sm_t('ALLG.NICHT_GESPEICHERT'); ?></b><ul>
<?php foreach ($sm_fehler as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
<?php } elseif ($sm_meldung !== '') { ?>
<div class="sm-alert sm-ok"><?php echo $sm_meldung; ?></div>
<?php } ?>
<?php if ($sm_teilfehler) { ?>
<div class="sm-alert sm-warn"><b><?php echo sm_t('ALLG.TEIL'); ?></b><ul>
<?php foreach ($sm_teilfehler as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
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

<?php /* Kopf (Entscheidung Nr. 43, seit 2.8.6): Statusuebersicht ueber den
   Reitern, immer sichtbar. Keine Netzabfrage und kein Prozessstart: beide
   Prozessfragen lesen /proc, die uebrigen Werte stehen oben schon fest. */
$sm_k_vz = $sm_cfg['enabled'] ? sm_vz_running() : '';
$sm_k_lg = $sm_lcfg_read === '1' ? sm_logger_pid() : null;
?>
<table class="sm-tbl" style="max-width:620px">
<tr><th><?php echo sm_e(sm_t('KOPF.EIGENSCHAFT')); ?></th><th><?php echo sm_e(sm_t('KOPF.WERT')); ?></th></tr>
<tr><td><?php echo sm_e(sm_t('KOPF.VZ')); ?></td>
    <td><?php
/* Abgeschaltet ist kein Fehler (der andere Leseweg kann der gewaehlte
   sein) - deshalb ohne Scheibe. */
if (!$sm_cfg['enabled']) {
    echo sm_e(sm_t('KOPF.AUS'));
} elseif ($sm_k_vz !== '') {
    echo '<span class="sm-scheibe sm-gruen"></span>' . sm_e(sprintf(sm_t('KOPF.LAEUFT'), $sm_k_vz));
} else {
    echo '<span class="sm-scheibe sm-rot"></span>' . sm_e(sm_t('KOPF.LAEUFT_NICHT'));
}
?></td></tr>
<tr><td><?php echo sm_e(sm_t('KOPF.LEGACY')); ?></td>
    <td><?php
if ($sm_lcfg_read !== '1') {
    echo sm_e(sm_t('KOPF.AUS'));
} elseif ($sm_k_lg !== null) {
    echo '<span class="sm-scheibe sm-gruen"></span>' . sm_e(sprintf(sm_t('KOPF.LAEUFT'), $sm_k_lg));
} else {
    echo '<span class="sm-scheibe sm-rot"></span>' . sm_e(sm_t('KOPF.LAEUFT_NICHT'));
}
?></td></tr>
<tr><td><?php echo sm_e(sm_t('KOPF.KOEPFE')); ?></td>
    <td><?php echo count($sm_koepfe); ?></td></tr>
<tr><td><?php echo sm_e(sm_t('KOPF.LASTGANG')); ?></td>
    <td><?php echo sm_e(sprintf(sm_t('KOPF.LASTGANG_WERT'), (int) $sm_lastgang['stunden_heute'])); ?></td></tr>
</table>

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
<div class="sm-hinweis"><?php echo sm_t('KOPF.WAS_IST_DAS'); ?></div>

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

<?php
/* O8 (Durchgang 01.10.2026): eine beschaedigte vzlogger.json wird gesagt,
 * nicht still durch die Vorgaben ersetzt. */
if (sm_vz_lage() === 'kaputt') { ?>
<div class="sm-alert sm-warn"><?php printf(sm_t('VZ.KAPUTT'),
  '<span class="sm-mono">' . sm_e($sm_p['vzjson']) . '</span>'); ?></div>
<?php }
/* X-2: nach einer Beanstandung die Eingaben, sonst die gespeicherten Werte. */
$sm_vz_dev  = sm_fw('vz', 'vz_device', $sm_cfg['device']);
$sm_vz_prot = sm_fw('vz', 'vz_protocol', $sm_cfg['protocol']);
$sm_vz_par  = sm_fw('vz', 'vz_parity', $sm_cfg['parity']);
$sm_vz_lt   = sm_fw('vz', 'vz_localtime', $sm_cfg['localtime'] ? '1' : '0');
?>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-vzlogger">
<?php echo sm_fmt(); ?>

<h2><?php echo sm_t('VZ.H_LESEWEG'); ?></h2>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="vz_enabled" value="1"<?php
    echo sm_fm('vz_enabled'); echo sm_fh('vz', 'vz_enabled', $sm_cfg['enabled']) ? ' checked' : ''; ?>> <?php echo sm_t('VZ.LABEL_ENABLED'); ?></label>
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
    if ($d === $sm_vz_dev) { $gefunden = true; }
    echo '<option value="' . sm_e($d) . '"'
       . ($d === $sm_vz_dev ? ' selected' : '') . '>' . sm_e($d) . '</option>';
}
// Ein gespeichertes, gerade nicht angestecktes Geraet nicht stillschweigend
// verlieren.
if (!$gefunden && $sm_vz_dev !== '') {
    echo '<option value="' . sm_e($sm_vz_dev) . '" selected>'
       . sm_e($sm_vz_dev) . ' (' . sm_t('VZ.NICHT_VORHANDEN') . ')</option>';
}
?>
  </select>
  <p class="sm-small"><?php echo sm_t('ALLG.AUSWAHLFELD'); ?></p>
</div>
<div class="sm-row">
  <label for="vz_protocol"><?php echo sm_t('VZ.LABEL_PROTOCOL'); ?></label>
  <select data-role="none"<?php echo sm_fm('vz_protocol', 'sm-auswahl'); ?> id="vz_protocol" name="vz_protocol">
    <option value="sml"<?php echo $sm_vz_prot === 'sml' ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_SML'); ?></option>
    <option value="d0"<?php echo $sm_vz_prot === 'd0' ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_D0'); ?></option>
    <?php echo sm_fsel_extra('vz', 'vz_protocol', array('sml', 'd0')); ?>
  </select>
</div>
<div class="sm-row">
  <label for="vz_baudrate"><?php echo sm_t('VZ.LABEL_BAUDRATE'); ?></label>
  <input data-role="none" type="text"<?php echo sm_fm('vz_baudrate'); ?> id="vz_baudrate" name="vz_baudrate"
         value="<?php echo sm_e(sm_fw('vz', 'vz_baudrate', $sm_cfg['baudrate'])); ?>">
  <p class="sm-small"><?php echo sm_t('VZ.HINT_BAUDRATE'); ?></p>
</div>
<div class="sm-row">
  <label for="vz_parity"><?php echo sm_t('VZ.LABEL_PARITY'); ?></label>
  <select data-role="none"<?php echo sm_fm('vz_parity', 'sm-auswahl'); ?> id="vz_parity" name="vz_parity">
<?php foreach (array('8n1', '7n1', '7e1', '8e1') as $par) { ?>
    <option value="<?php echo $par; ?>"<?php
      echo $sm_vz_par === $par ? ' selected' : ''; ?>><?php echo $par; ?></option>
<?php } ?>
    <?php echo sm_fsel_extra('vz', 'vz_parity', array('8n1', '7n1', '7e1', '8e1')); ?>
  </select>
</div>
<div class="sm-row">
  <label for="vz_localtime"><?php echo sm_t('VZ.LABEL_LOCALTIME'); ?></label>
  <select data-role="none"<?php echo sm_fm('vz_localtime', 'sm-auswahl'); ?> id="vz_localtime" name="vz_localtime">
    <option value="1"<?php echo $sm_vz_lt === '1' ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_LOKALZEIT'); ?></option>
    <option value="0"<?php echo $sm_vz_lt === '0' ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_ZAEHLERZEIT'); ?></option>
    <?php echo sm_fsel_extra('vz', 'vz_localtime', array('1', '0')); ?>
  </select>
  <p class="sm-small"><?php printf(sm_t('VZ.HINT_LOCALTIME'),
    '<span class="sm-mono">timestamp before 1990, IGNORING</span>'); ?></p>
</div>

<h2><?php echo sm_t('VZ.H_KANAELE'); ?></h2>
<div class="sm-row">
  <label for="vz_channels"><?php echo sm_t('VZ.LABEL_CHANNELS'); ?></label>
  <textarea data-role="none"<?php echo sm_fm('vz_channels'); ?> id="vz_channels" name="vz_channels"><?php
    echo sm_e(sm_fw('vz', 'vz_channels', implode("\n", $sm_cfg['channels']))); ?></textarea>
  <p class="sm-small"><?php echo sm_t('ALLG.VORGABE'); ?>:
  <span class="sm-mono">1-0:1.8.0</span> (<?php echo sm_t('OBIS.BEZUG'); ?>),
  <span class="sm-mono">1-0:2.8.0</span> (<?php echo sm_t('OBIS.EINSPEISUNG'); ?>),
  <span class="sm-mono">1-0:16.7.0</span> (<?php echo sm_t('OBIS.LEISTUNG'); ?>).</p>
</div>

<h2><?php echo sm_t('VZ.H_WEITERGABE'); ?></h2>
<div class="sm-row">
  <label for="vz_serial"><?php echo sm_t('VZ.LABEL_SERIAL'); ?></label>
  <input data-role="none" type="text"<?php echo sm_fm('vz_serial'); ?> id="vz_serial" name="vz_serial"
         value="<?php echo sm_e(sm_fw('vz', 'vz_serial', $sm_cfg['serial'])); ?>">
  <p class="sm-small"><?php echo sm_t('VZ.HINT_SERIAL'); ?></p>
</div>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="vz_sendudp" value="1"<?php
    echo sm_fh('vz', 'vz_sendudp', $sm_cfg['sendudp']) ? ' checked' : ''; ?>> <?php echo sm_t('ALLG.UDP_ZUSAETZLICH'); ?></label>
  <p class="sm-small"><?php echo sm_t('VZ.HINT_UDP'); ?></p>
</div>
<div class="sm-row">
  <label for="vz_udpport"><?php echo sm_t('ALLG.UDPPORT'); ?></label>
  <input data-role="none" type="text"<?php echo sm_fm('vz_udpport'); ?> id="vz_udpport" name="vz_udpport"
         value="<?php echo sm_e(sm_fw('vz', 'vz_udpport', $sm_cfg['udpport'])); ?>">
</div>
<div class="sm-row">
  <label for="vz_httpport"><?php echo sm_t('VZ.LABEL_HTTPPORT'); ?></label>
  <input data-role="none" type="text"<?php echo sm_fm('vz_httpport'); ?> id="vz_httpport" name="vz_httpport"
         value="<?php echo sm_e(sm_fw('vz', 'vz_httpport', $sm_cfg['httpport'])); ?>">
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
<?php
/* X-3 (Durchgang 01.10.2026): wuerde ein gespeicherter Wert das eigene
 * Zurueckspielen nicht bestehen, steht das gelb am Knopf - nur Namen, nie
 * Werte. Geliefert wird die Sicherung trotzdem, mit einer Zeile _warnung. */
$sm_x3 = sm_sichern_selbstpruefung(sm_sichern_text(false));
if ($sm_x3) { ?>
<div class="sm-alert sm-warn"><?php printf(sm_t('EINST.X3_WARNUNG'),
  '<span class="sm-mono">' . sm_e(implode(', ', $sm_x3)) . '</span>'); ?></div>
<?php } ?>
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
    echo sm_fm('lg_read'); echo sm_fh('lg', 'lg_read', $sm_lcfg_read === '1') ? ' checked' : ''; ?>> <?php echo sm_t('LG.LABEL_ENABLED'); ?></label>
</div>
<div class="sm-row">
  <label for="lg_cron"><?php echo sm_t('LG.LABEL_TAKT'); ?></label>
  <select data-role="none"<?php echo sm_fm('lg_cron', 'sm-auswahl'); ?> id="lg_cron" name="lg_cron">
<?php $sm_lg_takt = sm_fw('lg', 'lg_cron', $sm_lcfg_cron);
      foreach (sm_takte() as $wert => $t) { ?>
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
      echo (string) $sm_lg_takt === (string) $wert ? ' selected' : ''; ?>><?php echo $t[1]; ?></option>
<?php } ?>
    <?php echo sm_fsel_extra('lg', 'lg_cron', array_map('strval', array_keys(sm_takte()))); ?>
  </select>
  <p class="sm-small"><?php echo sm_t('LG.HINT_TAKT'); ?></p>
</div>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="lg_sendudp" value="1"<?php
    echo sm_fh('lg', 'lg_sendudp', $sm_lcfg_udp === '1') ? ' checked' : ''; ?>> <?php echo sm_t('ALLG.UDP_ZUSAETZLICH'); ?></label>
</div>
<div class="sm-row">
  <label for="lg_udpport"><?php echo sm_t('ALLG.UDPPORT'); ?></label>
  <input data-role="none" type="text"<?php echo sm_fm('lg_udpport'); ?> id="lg_udpport" name="lg_udpport"
         value="<?php echo sm_e(sm_fw('lg', 'lg_udpport', $sm_lcfg_udpport)); ?>">
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
  <input data-role="none" type="text"<?php echo sm_fm('lg_' . $sm_s . '_name'); ?> id="<?php echo sm_e($sm_s); ?>_name"
         name="lg_<?php echo sm_e($sm_s); ?>_name"
         value="<?php echo sm_e(sm_fw('lg', 'lg_' . $sm_s . '_name', isset($sm_k['NAME']) ? $sm_k['NAME'] : $sm_s)); ?>">
</div>
<div class="sm-row">
  <label for="<?php echo sm_e($sm_s); ?>_meter"><?php echo sm_t('LG.LABEL_PROFIL'); ?></label>
  <select data-role="none"<?php echo sm_fm('lg_' . $sm_s . '_meter', 'sm-auswahl'); ?> id="<?php echo sm_e($sm_s); ?>_meter" name="lg_<?php echo sm_e($sm_s); ?>_meter">
<?php $sm_akt = sm_fw('lg', 'lg_' . $sm_s . '_meter', isset($sm_k['METER']) ? $sm_k['METER'] : '0');
      /* Wie beim Abfragetakt: der Schluessel '0' ist im Feld eine Ganzzahl,
       * der Wert aus der Konfiguration eine Zeichenkette. Hier fiel es
       * bisher nicht auf, weil '0' zufaellig der erste Eintrag ist. */
      foreach (sm_profile() as $sm_pk => $sm_pn) { ?>
    <option value="<?php echo sm_e($sm_pk); ?>"<?php
      echo (string) $sm_akt === (string) $sm_pk ? ' selected' : ''; ?>><?php echo $sm_pn; ?></option>
<?php } ?>
    <?php echo sm_fsel_extra('lg', 'lg_' . $sm_s . '_meter', array_map('strval', array_keys(sm_profile()))); ?>
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
    echo sm_fh('mq', 'mq_an', $sm_legacy['SENDMQTT'] === '1') ? ' checked' : ''; ?>> <?php echo sm_t('MQ.LABEL_AN'); ?></label>
</div>
<div class="sm-row">
  <label for="mq_topic"><?php echo sm_t('MQ.LABEL_TOPIC'); ?></label>
  <input data-role="none" type="text"<?php echo sm_fm('mq_topic'); ?> id="mq_topic" name="mq_topic"
         value="<?php echo sm_e(sm_fw('mq', 'mq_topic', $sm_legacy['MQTTTOPIC'])); ?>">
  <p class="sm-small"><?php echo sm_t('MQ.HINT_TOPIC'); ?></p>
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
<?php
/* M1 (Durchgang 01.10.2026): die Themen, die WIRKLICH hinausgehen - je
 * Leseweg, dazu Lebenszeichen, Kosten und Abgleich, mit Spalte "retained".
 * Bis 2.8.5 zeigte diese Tabelle immer die Themen des vzLogger-Weges, auch
 * wenn der klassische Leser unter <praefix>/<kopf>/... sendete; keines der
 * gezeigten Themen kam an (gemessen, MQTT-Bericht Nr. 8). */
foreach (sm_mqtt_themen() as $sm_gr) {
    list($sm_gr_titel, $sm_gr_zeilen, $sm_gr_hinweis) = $sm_gr; ?>
<h3 class="sm-h3"><?php echo sm_e($sm_gr_titel); ?></h3>
<?php if ($sm_gr_hinweis !== '') { ?>
<p class="sm-small"><?php echo sm_e($sm_gr_hinweis); ?></p>
<?php }
    if ($sm_gr_zeilen) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:44%"><?php echo sm_t('MQ.SP_THEMA'); ?></th>
    <th style="width:10%"><?php echo sm_t('ALLG.EINHEIT'); ?></th>
    <th style="width:10%"><?php echo sm_t('MQ.SP_RETAINED'); ?></th>
    <th><?php echo sm_t('ALLG.BEDEUTUNG'); ?></th></tr>
<?php foreach ($sm_gr_zeilen as $sm_zl) {
        list($sm_th, $sm_eh, $sm_bd, $sm_rt, $sm_tx) = $sm_zl; ?>
<tr><td class="sm-mono"><?php echo sm_e($sm_th); ?></td>
    <td><?php echo $sm_eh !== '' ? sm_e($sm_eh) : '&ndash;'; ?></td>
    <td><?php echo $sm_rt === null ? '&ndash;' : ($sm_rt ? sm_t('ALLG.JA') : sm_t('ALLG.NEIN')); ?></td>
    <td><?php echo sm_e($sm_bd); ?><?php
      if ($sm_tx) { echo ' <i>(' . sm_t('ALLG.TEXTFELD') . ')</i>'; } ?></td></tr>
<?php } ?>
</table>
</div>
<?php }
} ?>
<div class="sm-alert sm-info"><?php echo sm_t('MQ.RETAINED_HINT'); ?></div>
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
    list($sm_eh_roh, , , $sm_nk_vz) = sm_einheit_fuer($sm_md, 'vz', $sm_feld);
    $sm_eh = ($sm_eh_roh !== '')
        ? '&lt;v.' . (int) $sm_nk_vz . '&gt;&nbsp;' . sm_e($sm_eh_roh) : '&ndash;';
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
<?php
/* Welle Bild 7 (2.8.8, Musterprojekt): zwei Baustein-Listen, je Leseweg eine, beide immer
 * sichtbar. Sie sind im LoxBerry-Plugins Musterprojekt in Loxone Config gebaut und mit
 * leitungen_setzen.py verbunden (Musterprojekt/baustein_listen.txt, Abschnitte "Smartmeter
 * klassisch" und "Smartmeter vzLogger") - eine Zeile = ein Baustein, nur die Hauptvariante.
 *
 * Die Namen #1 bis #4 kommen aus derselben Quelle wie die Vorlagen, die der Knopf in
 * Schritt 3 erzeugt: sm_ve_name() mit dem Themenpraefix und der Zaehlernummer und die
 * Lebenszeichen aus sm_vorlage_status(). Zaehlernummer: auf dem vzLogger-Weg die
 * Einstellung serial (wie sm_vorlage()), auf dem klassischen Weg der erste Lesekopf (wie
 * sm_vorlage_legacy()); ist nichts eingerichtet, steht der Platzhalter. Der Vorlagentitel im
 * Typ ist der Titel der Vorlage. Bausteinnamen #5 bis #14 in beiden Sprachen wie im
 * Musterprojekt. Was die beiden Wege unterscheidet (Vorlage, kWh/Wh, kW/W), steht in
 * $sm_bl_wege; alle Zellen sind Klartext und gehen durch sm_e(), '' wird ein Strich. */
$sm_bl_praefix = $sm_legacy['MQTTTOPIC'];
$sm_bl_koepfe  = sm_koepfe();
$sm_bl_status  = sm_vorlage_status($sm_bl_praefix);
$sm_bl_wege = array(
    array('titel'    => sm_t('LOX.BL_KLASSISCH'),
          'seite'    => 'Smartmeter klassisch',
          'bild'     => 'einbindung_loxone_klassisch.png',
          'vorlage'  => sm_t('LOX.VORLAGE_TITEL_LG'),
          'nummer'   => $sm_bl_koepfe ? (string) $sm_bl_koepfe[0]['ABSCHNITT'] : '',
          'energie'  => 'kWh',
          'leistung' => 'kW'),
    array('titel'    => sm_t('LOX.BL_VZLOGGER'),
          'seite'    => 'Smartmeter vzLogger',
          'bild'     => 'einbindung_loxone_vzlogger.png',
          'vorlage'  => sm_t('LOX.VORLAGE_TITEL'),
          'nummer'   => (string) $sm_cfg['serial'],
          'energie'  => 'Wh',
          'leistung' => 'W'),
);
foreach ($sm_bl_wege as $sm_bl_w) {
    $sm_bl_nr = ($sm_bl_w['nummer'] !== '') ? $sm_bl_w['nummer'] : sm_t('LOX.BL_ZAEHLERNUMMER');
    $sm_bl_e  = $sm_bl_w['energie'];
    $sm_bl_p  = $sm_bl_w['leistung'];
    $sm_bl_vh = sprintf(sm_t('BAUSTEIN.VHTTP_TYP'), $sm_bl_w['vorlage']);
    /* Je Zeile: Typ, Name, Parameter, Eingaenge verbinden mit. */
    $sm_bl_zeilen = array(
        1   => array($sm_bl_vh,
                     sm_ve_name($sm_bl_praefix, $sm_bl_nr, sm_obis_feld('1-0:1.8.0')),
                     sprintf(sm_t('BAUSTEIN.B1_PARAM'), $sm_bl_e, $sm_bl_nr),
                     ''),
        2   => array($sm_bl_vh,
                     sm_ve_name($sm_bl_praefix, $sm_bl_nr, sm_obis_feld('1-0:16.7.0')),
                     sprintf(sm_t('BAUSTEIN.B2_PARAM'), $sm_bl_p),
                     ''),
        3   => array($sm_bl_vh,
                     $sm_bl_status[0][0],
                     sm_t('BAUSTEIN.B3_PARAM'),
                     ''),
        4   => array($sm_bl_vh,
                     $sm_bl_status[2][0],
                     sm_t('BAUSTEIN.B4_PARAM'),
                     ''),
        5   => array(sm_t('BAUSTEIN.B5_TYP'),
                     sm_t('BAUSTEIN.B5_NAME'),
                     sm_t('BAUSTEIN.B5_PARAM'),
                     ''),
        6   => array(sm_t('BAUSTEIN.B6_TYP'),
                     sm_t('BAUSTEIN.B6_NAME'),
                     sm_t('BAUSTEIN.B6_PARAM'),
                     ''),
        7   => array(sm_t('BAUSTEIN.B7_TYP'),
                     sprintf(sm_t('BAUSTEIN.B7_NAME'), $sm_bl_e),
                     '',
                     sm_t('BAUSTEIN.B7_VERB')),
        8   => array(sm_t('BAUSTEIN.B8_TYP'),
                     sprintf(sm_t('BAUSTEIN.B8_NAME'), $sm_bl_e),
                     sm_t('BAUSTEIN.B8_PARAM'),
                     sm_t('BAUSTEIN.B8_VERB')),
        9   => array(sm_t('BAUSTEIN.B9_TYP'),
                     sm_t('BAUSTEIN.B9_NAME'),
                     sm_t('BAUSTEIN.B9_PARAM'),
                     sm_t('BAUSTEIN.B9_VERB')),
        10  => array(sm_t('BAUSTEIN.B10_TYP'),
                     sm_t('BAUSTEIN.B10_NAME'),
                     '',
                     sm_t('BAUSTEIN.B10_VERB')),
        11  => array(sm_t('BAUSTEIN.B11_TYP'),
                     sm_t('BAUSTEIN.B11_NAME'),
                     sm_t('BAUSTEIN.B11_PARAM'),
                     sm_t('BAUSTEIN.B11_VERB')),
        12  => array(sm_t('BAUSTEIN.B12_TYP'),
                     sm_t('BAUSTEIN.B12_NAME'),
                     '',
                     sm_t('BAUSTEIN.B12_VERB')),
        13  => array(sm_t('BAUSTEIN.B13_TYP'),
                     sm_t('BAUSTEIN.B13_NAME'),
                     sm_t('BAUSTEIN.B13_PARAM'),
                     sm_t('BAUSTEIN.B13_VERB')),
        14  => array(sm_t('BAUSTEIN.B14_TYP'),
                     sm_t('BAUSTEIN.B14_NAME'),
                     sprintf(sm_t('BAUSTEIN.B14_PARAM'), $sm_bl_p, $sm_bl_e),
                     sm_t('BAUSTEIN.B14_VERB')),
    ); ?>
<h3 class="sm-h3"><?php echo sm_e($sm_bl_w['titel']); ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?php echo sm_t('LOX.SP_BAUSTEIN'); ?></th><th><?php echo sm_t('LOX.SP_NAME'); ?></th><th><?php echo sm_t('LOX.SP_PARAMETER'); ?></th><th><?php echo sm_t('LOX.SP_EINGAENGE'); ?></th></tr>
<?php foreach ($sm_bl_zeilen as $sm_bl_n => $sm_bl_z) { ?>
<tr><td><?php echo (int) $sm_bl_n; ?></td><td><?php echo sm_e($sm_bl_z[0]); ?></td><td<?php echo $sm_bl_n <= 4 ? ' class="sm-mono"' : ''; ?>><?php echo sm_e($sm_bl_z[1]); ?></td><td><?php echo $sm_bl_z[2] !== '' ? sm_e($sm_bl_z[2]) : '&mdash;'; ?></td><td><?php echo $sm_bl_z[3] !== '' ? sm_e($sm_bl_z[3]) : '&mdash;'; ?></td></tr>
<?php } ?>
</table>
</div>
<figure class="sm-bild">
<img src="<?php echo sm_e($sm_bl_w['bild']); ?>" alt="<?php echo sm_e(sprintf(sm_t('LOX.BILD_ALT'), $sm_bl_w['titel'])); ?>" loading="lazy">
<figcaption><?php echo sm_e(sprintf(sm_t('LOX.BILD_UNTERSCHRIFT'), $sm_bl_w['seite'])); ?></figcaption>
</figure>
<p class="sm-small"><?php echo sm_t('LOX.MUSTERPROJEKT'); ?></p>
<?php } ?>
<div class="sm-alert sm-info">
<?php echo sm_t('BAUSTEIN.H_TAGESVERBRAUCH'); ?><br>
<?php echo sm_t('BAUSTEIN.H_STATISTIK'); ?><br>
<?php echo sm_t('BAUSTEIN.H_EINHEITEN'); ?><br>
<?php echo sm_t('BAUSTEIN.H_BENACHRICHTIGUNG'); ?><br>
<?php echo sm_t('BAUSTEIN.H_ODER'); ?>
</div>
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
                  . 'SMARTMETER;OK=1;ALTER=42;ZAEHLER=137;KOEPFE=1;GRENZE=900;KOPF1_OK=1;KOPF1_ALTER=42'
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

<div class="sm-step">
<b><?php echo sm_t('LOX.S9_TITEL'); ?></b><br><br>
<?php echo sm_t('LOX.S9_TEXT'); ?>
<pre class="sm-pre"><?php echo sm_e($sm_lastgang_url); ?></pre>
<p class="sm-small"><?php echo sm_t('LOX.S9_EINTRAGEN'); ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:26%"><?php echo sm_t('LOX.S9_SP_FELD'); ?></th>
    <th><?php echo sm_t('LOX.S9_SP_WERT'); ?></th></tr>
<tr><td><?php echo sm_t('LOX.S9_F_QUELLE'); ?></td><td class="sm-mono">objekt</td></tr>
<tr><td><?php echo sm_t('LOX.S9_F_PFAD'); ?></td><td class="sm-mono">stunden</td></tr>
<tr><td><?php echo sm_t('LOX.S9_F_EINHEIT'); ?></td><td class="sm-mono">wh</td></tr>
<tr><td><?php echo sm_t('LOX.S9_F_URL'); ?></td><td class="sm-mono"><?php echo sm_e($sm_lastgang_url); ?></td></tr>
</table>
</div>
<?php
/* Der Zustand - gemessen an der Historie, nicht behauptet. Ein Kasten,
 * der nur die Adresse zeigt, sagt nicht, ob dort etwas ankommt. */
$sm_lg_klasse = $sm_lastgang['stunden_heute'] >= 20 ? 'sm-ok'
              : ($sm_lastgang['zeilen'] > 0 ? 'sm-warn' : 'sm-info');
?>
<div class="sm-alert <?php echo $sm_lg_klasse; ?>"><?php
printf(sm_t('LOX.S9_LAGE'), (int) $sm_lastgang['stunden_heute'],
       (int) $sm_lastgang['zeilen'], (int) $sm_lastgang['offen']);
?></div>
<?php if ($sm_lastgang['offen'] > 0) { ?>
<div class="sm-alert sm-warn"><?php echo sm_t('LOX.S9_OFFEN'); ?></div>
<?php } ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('AB.TITEL'); ?></b><br><br>
<?php echo sm_t('AB.TEXT'); ?>
<div class="sm-alert sm-info"><?php echo sm_t('AB.SCHRANKE'); ?></div>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<?php echo sm_fmt(); ?>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="ab_aktiv" value="1"<?php
    echo sm_fh('ab', 'ab_aktiv', sm_cfg_get(sm_cfg_read(), 'ABGLEICH', 'AKTIV', '0') === '1') ? ' checked' : '';
    ?>> <?php echo sm_t('AB.LABEL_AKTIV'); ?></label>
</div>
<div class="sm-row">
  <label for="ab_url"><?php echo sm_t('AB.LABEL_URL'); ?></label>
  <input data-role="none" type="text"<?php echo sm_fm('ab_url'); ?> id="ab_url" name="ab_url"
         value="<?php echo sm_e(sm_fw('ab', 'ab_url', sm_cfg_get(sm_cfg_read(), 'ABGLEICH', 'FAHRPLAN_URL', ''))); ?>">
  <p class="sm-small"><?php echo sm_t('AB.HINT_URL'); ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.AB_SPEICHERN'); ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ab_speichern" value="1"><?php
    echo sm_t('ALLG.SPEICHERN'); ?></button>
</div>
</form>
<?php
$sm_ab = sm_abgleich_stand();
if ($sm_ab['da'] && $sm_ab['regeln']) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?php echo sm_t('AB.SP_REGEL'); ?></th>
    <th><?php echo sm_t('AB.SP_SOLL'); ?></th>
    <th><?php echo sm_t('AB.SP_IST'); ?></th>
    <th><?php echo sm_t('AB.SP_FEHLT'); ?></th>
    <th><?php echo sm_t('AB.SP_URTEIL'); ?></th></tr>
<?php foreach ($sm_ab['regeln'] as $sm_nr => $sm_r) {
    $sm_u = isset($sm_r['urteil']) ? $sm_r['urteil'] : '';
    /* Nur "zieht_nicht" ist ein Befund. "unsicher" und die uebrigen sind
     * KEIN Urteil - sie duerfen deshalb nicht rot aussehen. */
    $sm_farbe = ($sm_u === 'zieht') ? '#6dac20'
              : (($sm_u === 'zieht_nicht') ? '#e0620d' : '#546e7a');
    ?>
<tr><td class="sm-mono"><?php echo sm_e($sm_nr); ?></td>
    <td class="sm-mono"><?php echo isset($sm_r['soll']) ? sm_e(number_format((float) $sm_r['soll'], 3, ',', '')) : '&ndash;'; ?></td>
    <td class="sm-mono"><?php echo isset($sm_r['ist']) ? sm_e(number_format((float) $sm_r['ist'], 3, ',', '')) : '&ndash;'; ?></td>
    <td class="sm-mono"><?php echo isset($sm_r['fehlt']) ? sm_e(number_format((float) $sm_r['fehlt'], 3, ',', '')) : '&ndash;'; ?></td>
    <td style="color:<?php echo $sm_farbe; ?>"><?php
      /* Hinweis aus dem Durchgang 01.10.2026: ohne Urteil stand hier
       * woertlich "&ndash;" (doppelt maskiert), ein unbekanntes Urteil
       * erschien als Schluesselname AB.U_... - jetzt ein Strich bzw. das
       * Wort mit dem Zusatz "unbekannt". */
      if (!is_string($sm_u) || $sm_u === '') {
          echo '&ndash;';
      } elseif (sm_t('AB.U_' . strtoupper($sm_u)) !== 'AB.U_' . strtoupper($sm_u)) {
          echo sm_e(sm_t('AB.U_' . strtoupper($sm_u)));
      } else {
          echo sm_e(sprintf(sm_t('AB.U_UNBEKANNT'), $sm_u));
      } ?></td></tr>
<?php } ?>
</table>
</div>
<?php if (!$sm_ab['quelle_ok']) { ?>
<div class="sm-alert sm-warn"><?php
  printf(sm_t('AB.QUELLE_WEG'), sm_e($sm_ab['grund'])); ?></div>
<?php } ?>
<?php } elseif (sm_cfg_get(sm_cfg_read(), 'ABGLEICH', 'AKTIV', '0') === '1') { ?>
<div class="sm-alert sm-info"><?php echo sm_t('AB.NOCH_NICHTS'); ?></div>
<?php } ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('KO.TITEL'); ?></b><br><br>
<?php echo sm_t('KO.TEXT'); ?>
<div class="sm-alert sm-info"><?php echo sm_t('KO.NUR_HEUTE'); ?></div>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<?php echo sm_fmt(); ?>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="ko_aktiv" value="1"<?php
    echo sm_fh('ko', 'ko_aktiv', sm_cfg_get(sm_cfg_read(), 'KOSTEN', 'AKTIV', '0') === '1') ? ' checked' : '';
    ?>> <?php echo sm_t('KO.LABEL_AKTIV'); ?></label>
</div>
<div class="sm-row">
  <label for="ko_url"><?php echo sm_t('KO.LABEL_URL'); ?></label>
  <input data-role="none" type="text"<?php echo sm_fm('ko_url'); ?> id="ko_url" name="ko_url"
         value="<?php echo sm_e(sm_fw('ko', 'ko_url', sm_cfg_get(sm_cfg_read(), 'KOSTEN', 'PREIS_URL', ''))); ?>">
  <p class="sm-small"><?php echo sm_t('KO.HINT_URL'); ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.KO_SPEICHERN'); ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ko_speichern" value="1"><?php
    echo sm_t('ALLG.SPEICHERN'); ?></button>
</div>
</form>
<?php
$sm_ko = sm_kosten_stand();
if ($sm_ko['da'] && $sm_ko['ts']) {
    /* Ein fehlender Wert bekommt einen Strich. Eine 0 waere eine Aussage -
     * sie hiesse, es habe nichts gekostet. */
?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?php echo sm_t('KO.SP_GROESSE'); ?></th><th><?php echo sm_t('KO.SP_WERT'); ?></th></tr>
<tr><td><?php echo sm_t('KO.Z_STUNDE'); ?></td><td class="sm-mono"><?php
  echo $sm_ko['stunde_ct'] === null ? '&ndash;'
     : sm_e(number_format((float) $sm_ko['stunde_ct'], 2, ',', '.')) . '&nbsp;ct'; ?></td></tr>
<tr><td><?php echo sm_t('KO.Z_HEUTE'); ?></td><td class="sm-mono"><?php
  echo $sm_ko['heute_ct'] === null ? '&ndash;'
     : sm_e(number_format((float) $sm_ko['heute_ct'] / 100.0, 2, ',', '.')) . '&nbsp;EUR'; ?></td></tr>
<tr><td><?php echo sm_t('KO.Z_MENGE'); ?></td><td class="sm-mono"><?php
  echo $sm_ko['heute_kwh'] === null ? '&ndash;'
     : sm_e(number_format((float) $sm_ko['heute_kwh'], 3, ',', '.')) . '&nbsp;kWh'; ?></td></tr>
<tr><td><?php echo sm_t('KO.Z_STUNDEN'); ?></td><td class="sm-mono"><?php
  echo (int) $sm_ko['stunden']; ?></td></tr>
</table>
</div>
<?php if ((int) $sm_ko['offen'] > 0 || (int) $sm_ko['ohne_preis'] > 0) { ?>
<div class="sm-alert sm-warn"><?php
  printf(sm_t('KO.UEBERGANGEN'), (int) $sm_ko['offen'], (int) $sm_ko['ohne_preis']); ?></div>
<?php }
   if (!$sm_ko['quelle_ok']) { ?>
<div class="sm-alert sm-warn"><?php
  printf(sm_t('KO.QUELLE_WEG'), sm_e($sm_ko['grund'])); ?></div>
<?php } ?>
<?php } elseif (sm_cfg_get(sm_cfg_read(), 'KOSTEN', 'AKTIV', '0') === '1') { ?>
<div class="sm-alert sm-info"><?php echo sm_t('KO.NOCH_NICHTS'); ?></div>
<?php } ?>
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
