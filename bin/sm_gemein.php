<?php
/**
 * Smartmeter classic - was Oberflaeche, Abholer und Endpunkt gemeinsam haben
 *
 * Diese Datei liegt unter bin/, weil sie aus DREI Baeumen erreichbar sein
 * muss: webfrontend/htmlauth (Oberflaeche), webfrontend/html (Endpunkt fuer
 * den Miniserver) und bin (Abholer aus dem Cron). Auf dem installierten
 * LoxBerry liegen die drei in getrennten Baeumen; ein require ueber eine
 * feste Zahl von ".." trifft nur den Archivfall.
 *
 * Sie ist KEIN Endpunkt und tut beim Einbinden nichts ausser Funktionen zu
 * definieren.
 *
 * Warum es sie gibt: die Altersgrenze eines Messwertes hat zwei Verbraucher
 * - den Endpunkt, der OK und ALTER an Loxone liefert, und den Reiter Test,
 * der dieselbe Frage beantwortet. Eine Grenze mit zwei Verbrauchern steht in
 * EINER Funktion; zwei Ausschreibungen mit einem Kommentar, der auf die
 * andere verweist, sind die Bauart, aus der Widersprueche entstehen.
 *
 * Alle Namen tragen das Kuerzel smg_ - die Datei landet mit sm_lib.php im
 * selben Prozess, und zwei gleichnamige Funktionen sind dort kein
 * Namensraum-Problem, sondern ein "Cannot redeclare" beim Start.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

if (!function_exists('smg_cfg_lesen')) {

/**
 * smartmeter.cfg abschnittsweise lesen.
 *
 * Nicht mit parse_ini_file(): die Datei fuehrt frei vergebene Bezeichnungen
 * der Lesekoepfe. Ein "&", "(" oder "!" darin laesst parse_ini_file() die
 * GANZE Datei verwerfen - dann waere ploetzlich kein Token gesetzt und der
 * Endpunkt stuende offen.
 */
function smg_cfg_lesen($datei)
{
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
        $alles[$abschnitt][trim(substr($z, 0, $pos))] = trim(substr($z, $pos + 1));
    }
    return $alles;
}

function smg_wert($alles, $abschnitt, $schluessel, $vorgabe = '')
{
    return (isset($alles[$abschnitt][$schluessel]) && $alles[$abschnitt][$schluessel] !== '')
        ? $alles[$abschnitt][$schluessel] : $vorgabe;
}

/** Der Abstand zweier Abfragen in Sekunden - 0 heisst "nur beim Systemstart". */
function smg_takt_sekunden($takt)
{
    $takt = (string) $takt;
    if ($takt === 'M' || $takt === '') {
        return 0;
    }
    return preg_match('/^[0-9]+$/', $takt) ? ((int) $takt) * 60 : 0;
}

/**
 * Ab wann gilt ein Messwert als zu alt?
 *
 * SEIT DEM DURCHGANG 01.10.2026 nach Entscheidung 4 des Hausherrn: OK=0, wenn
 * ALTER groesser ist als das DREIFACHE des Abfragetakts - ein einzelner
 * verpasster Durchlauf loest nichts aus, zwei verpasste noch nicht, der
 * dritte schon. Der vzLogger-Weg wird fest im Minutentakt abgeholt: 180 s.
 * Bis 2.8.5 stand hier max(300, 5 * Takt) - ein Ausfall wurde bei 5 min Takt
 * erst nach 25 statt 15 min sichtbar (gemessen, Codebericht Nr. 3).
 *
 * 0 heisst: es gibt keine Grenze. Ueber eine Betriebsart, die absichtlich
 * nur beim Systemstart liest, wird kein Altersurteil gefaellt - ein Wert,
 * der alt sein SOLL, ist kein Befund.
 */
function smg_alter_grenze($cron, $vz_an)
{
    if ($vz_an) {
        return 3 * 60;
    }
    $t = smg_takt_sekunden($cron);
    if ($t <= 0) {
        return 0;
    }
    return 3 * $t;
}

/**
 * Einen Wert fuer das UDP-Relais des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE und trennt Thema und Wert am Leerraum. Ein
 * Zeilenumbruch im Wert zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen.
 *
 * Bis 2.3.14 hatte diese Saeuberung nur bin/fetch.php; bin/fetch_vzlogger.pl
 * schickte den Wert roh - und Last_Update ist ein Text mit Leerzeichen.
 * Zwei Wege, die dieselben Werte tragen sollen, behandeln sie gleich.
 */
function smg_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/** Den Katalog bin/sm_felder.json lesen. Leeres Feld, wenn es ihn nicht gibt. */
function smg_katalog($datei)
{
    $leer = array('obis' => array(), 'felder' => array());
    if (!is_readable($datei)) {
        return $leer;
    }
    $d = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($d) || !isset($d['felder']) || !is_array($d['felder'])) {
        return $leer;
    }
    return array(
        'obis'   => (isset($d['obis']) && is_array($d['obis'])) ? $d['obis'] : array(),
        'felder' => $d['felder'],
    );
}

/**
 * Den umlaufenden Zaehler eine Stelle weiterdrehen.
 *
 * 0..999, danach wieder 0. Er liegt auf der Ramdisk neben den Datendateien:
 * dass er nach einem Neustart bei -1 beginnt, ist die richtige Aussage.
 *
 * Warum ein Zaehler und nicht nur ein Zeitstempel: ein Raspberry Pi hat
 * keine Echtzeituhr. Nach dem Booten steht er in der Vergangenheit, und
 * sobald NTP greift, springt die Zeit - ein Alter in Sekunden wird dann
 * negativ und meldet nach max(0, ...) "gerade eben gemessen".
 */
function smg_zaehler_weiter($datei)
{
    $alt = -1;
    if (is_file($datei)) {
        $w = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9]{1,3}$/', $w)) {
            $alt = (int) $w;
        }
    }
    $neu = ($alt < 0) ? 0 : (($alt + 1) % 1000);
    $tmp = $datei . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, (string) $neu) !== false) {
        if (!@rename($tmp, $datei)) {
            @unlink($tmp);
        }
    }
    return $neu;
}

/** Den Stand des Zaehlers lesen. -1 heisst "noch nie gelaufen". */
function smg_zaehler_lesen($datei)
{
    if (!is_file($datei)) {
        return -1;
    }
    $w = trim((string) @file_get_contents($datei));
    return preg_match('/^[0-9]{1,3}$/', $w) ? (int) $w : -1;
}

/**
 * Eine Protokolldatei kappen: ab $grenze Bytes bleiben die letzten
 * $behalten Zeilen stehen.
 *
 * Steht hier, weil es ZWEI Verbraucher gibt, die in getrennten Baeumen
 * liegen: sm_fetch_log() im Abholer (bin/fetch.php, Ziel /dev/shm) und
 * sm_log() in der Oberflaeche (webfrontend/htmlauth/sm_lib.php, Ziel
 * log/plugins). Bis 2.4.1 stand die Kappung in beiden Rumpfen wortgleich
 * ausgeschrieben - dieselbe Bauart, aus der Widersprueche entstehen.
 *
 * Beide Ziele liegen auf einer Ramdisk (log/plugins ist auf dem LoxBerry
 * ein tmpfs): eine wachsende Datei frisst dort Arbeitsspeicher, nicht
 * Plattenplatz.
 *
 * Rueckgabe: true, wenn wirklich gekappt wurde. Liefert filesize() ein
 * false - Datei verschwand zwischen is_file() und filesize() -, faellt
 * der Vergleich auf "nicht kappen", und die naechste Zeile wird trotzdem
 * geschrieben. Nicht kappen ist der harmlosere der beiden Ausgaenge.
 */
function smg_log_kappen($datei, $grenze = 512000, $behalten = 200)
{
    /* PHP merkt sich die Antworten von stat(). Ohne diese Zeile sieht
     * filesize() innerhalb EINES Prozesses die erste Groesse und danach
     * nie wieder eine neue - gemessen am 29.08.2026: unter PHP 7.4.33
     * wuchs eine Datei im selben Prozess auf 1 220 000 Byte, ohne je
     * gekappt zu werden; unter 8.4.24 nicht. Beide Aufrufer sind heute
     * kurzlebig, ein frischer Prozess kappt also richtig - aber die
     * Funktion darf nicht davon abhaengen, wer sie wie oft ruft.
     * Der zweite Parameter beschraenkt das Leeren auf DIESE Datei. */
    clearstatcache(true, $datei);
    if (!is_file($datei) || filesize($datei) <= $grenze) {
        return false;
    }
    $rest = array_slice(file($datei, FILE_IGNORE_NEW_LINES) ?: array(), -$behalten);
    @file_put_contents($datei, implode("\n", $rest) . "\n");
    return true;
}

/* ==================================================================
 * SEIT DEM DURCHGANG 01.10.2026: Lesekoepfe einzeln, MQTT-Zustaende
 * ================================================================== */

/** vzlogger.json lesen: das Feld, oder null (fehlt, unlesbar, kein JSON). */
function smg_vz_json($datei)
{
    if (!is_readable($datei)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($datei), true);
    return is_array($d) ? $d : null;
}

/** Die Zaehlernummer des vzLogger-Weges - gesaeubert wie in bin/fetch_vzlogger.pl. */
function smg_vz_serial($vzd)
{
    $s = (is_array($vzd) && isset($vzd['serial']) && is_scalar($vzd['serial'])) ? (string) $vzd['serial'] : '';
    $s = preg_replace('/[^A-Za-z0-9_\-]/', '', $s);
    return ($s !== '') ? $s : 'vzlogger';
}

/**
 * Die EINGESCHALTETEN Lesekoepfe, in der Reihenfolge der Konfiguration (A5).
 *
 * vzLogger an: genau einer, unter seiner Zaehlernummer. Sonst, wenn der
 * klassische Leser an ist (READ=1): jeder Abschnitt mit DEVICE und einem
 * Zaehlerprofil - genau die, die bin/fetch.php liest. Ein angesteckter Stick
 * ohne Profil (METER=0) wird nicht gelesen und zaehlt deshalb nicht.
 * KOPF1 im Endpunkt ist der erste dieser Liste.
 */
function smg_koepfe($cfg, $vzd)
{
    $liste = array();
    if (is_array($vzd) && !empty($vzd['enabled'])) {
        $liste[] = smg_vz_serial($vzd);
        return $liste;
    }
    if (smg_wert($cfg, 'MAIN', 'READ', '0') !== '1') {
        return $liste;
    }
    foreach ($cfg as $ab => $w) {
        if ($ab === 'MAIN' || !is_array($w) || !isset($w['DEVICE'])) {
            continue;
        }
        $m = isset($w['METER']) ? (string) $w['METER'] : '';
        if ($m === '' || $m === '0') {
            continue;
        }
        $liste[] = (string) $ab;
    }
    return $liste;
}

/** Last_UpdateUnix einer Datendatei - der juengste darin; 0 = keiner. */
function smg_ts_datei($datei)
{
    if (!is_file($datei)) {
        return 0;
    }
    $inhalt = (string) @file_get_contents($datei);
    $ts = 0;
    if (preg_match_all('/:Last_UpdateUnix:([0-9]+)/', $inhalt, $m)) {
        foreach ($m[1] as $t) {
            if ((int) $t > $ts) { $ts = (int) $t; }
        }
    }
    return $ts;
}

/**
 * Alter und Urteil JE eingeschaltetem Lesekopf (A5).
 *
 * Bis 2.8.5 rechnete der Endpunkt nur das juengste Last_UpdateUnix ueber
 * alle Koepfe - schwieg der zweite Zaehler seit Stunden, stand trotzdem
 * OK=1 da (gemessen, Codebericht Nr. 4). Ohne Zeitstempel: ALTER -1, OK 0.
 * Ein Zeitstempel aus der Zukunft (Zeitsprung nach dem Booten) gilt ebenso
 * als -1 - max(0, ...) hiesse "gerade eben gemessen".
 */
function smg_kopf_lage($shm, $koepfe, $grenze)
{
    $aus = array();
    $jetzt = time();
    foreach ($koepfe as $s) {
        $ts = smg_ts_datei($shm . '/' . $s . '.data');
        $alter = ($ts > 0) ? $jetzt - $ts : -1;
        if ($alter < 0) {
            $alter = -1;
        }
        $ok = ($alter >= 0 && ($grenze <= 0 || $alter <= $grenze)) ? 1 : 0;
        $aus[] = array('serial' => (string) $s, 'ok' => $ok, 'alter' => $alter);
    }
    return $aus;
}

/**
 * Die Felder, die als ZUSTAND retained hinausgehen (Entscheidung 3, M3).
 *
 * Alles andere - Zaehlerstaende, Leistungen, Zeitstempel, Kosten, das
 * Lebenszeichen - bleibt fluechtig. Ein Zustand, den ein Neustart von Broker
 * oder Gateway sonst bis zum naechsten Lauf verschluckt, ist hier der
 * Schaltzustand des Zaehlers, der Tarif und der Meldungscode. Leere Werte
 * gehen nie hinaus (DATA_WERT schreibt sie nicht, die Sender ueberspringen
 * sie) - eine leere retain-Nutzlast loeschte das Thema.
 */
function smg_mqtt_zustaende()
{
    return array('Breaker_State_Electricity_96.3.10', 'Tarif_Indicator_Electricity_96.14.0',
                 'Message_Code_96.13.1');
}

/** Geht dieses Feld retained hinaus? */
function smg_mqtt_retained($feld)
{
    return in_array((string) $feld, smg_mqtt_zustaende(), true);
}

/** Der UDP-Eingang des MQTT-Gateways aus general.json, 0 = keiner. */
function smg_mqtt_udpport($home)
{
    $gen = @json_decode((string) @file_get_contents($home . '/config/system/general.json'), true);
    $udp = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udp = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udp && isset($gen['mqtt']['udpinport'])) { $udp = (int) $gen['mqtt']['udpinport']; }
    return ($udp > 0 && $udp < 65536) ? $udp : 0;
}

/**
 * Den Broker fragen, was er unter den Filtern $filter zurueckbehaelt - in
 * EINER Verbindung (Bauart mo_mqtt_behalten_liste(), Robonect 1.1.15).
 *
 * Rueckgabe array('lage' => 'ok'|'unbekannt', 'werte' => array(thema => wert)).
 * 'ok': Anmeldung (CONNACK 0) und JEDER Filter (SUBACK unter 0x80)
 * bestaetigt; was nicht unter 'werte' steht, ist leer. 'unbekannt': nicht zu
 * fragen - das heisst NIE "nichts belegt". Belegt ist ein Thema nur am
 * EMPFANGENEN Paket mit Retain-Merkmal und nicht leerer Nutzlast. MQTT 3.1.1
 * von Hand, ohne fremde Bibliothek; das Kennwort aus general.json steht nur
 * im CONNECT-Paket, nie in einem Protokoll und nie auf einer Befehlszeile.
 */
function smg_mqtt_behalten($home, array $filter)
{
    $aus = array('lage' => 'unbekannt', 'werte' => array());
    $filter = array_values(array_filter(array_map('strval', $filter), 'strlen'));
    if (!$filter) {
        $aus['lage'] = 'ok';
        return $aus;
    }
    if (!function_exists('stream_socket_client')) {
        return $aus;
    }
    $d = @json_decode((string) @file_get_contents($home . '/config/system/general.json'), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) {
        return $aus;
    }
    $m = $d['Mqtt'];
    $hol = function ($k) use ($m) {
        return (isset($m[$k]) && is_scalar($m[$k])) ? (string) $m[$k] : '';
    };
    $host = trim($hol('Brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser');
    $kennwort = $hol('Brokerpass');

    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) {
        return $aus;
    }
    stream_set_timeout($s, 1);
    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0;
        $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };
    $flags = 0x02;
    $nutz = $zk('smrueck' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    // Ganz geschrieben, nicht nur "nicht false" (Regeln/03: eine kurze
    // Schreibung ist kein Erfolg).
    $verbinden = chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz;
    if (@fwrite($s, $verbinden) === strlen($verbinden)) {
        $ack = $paket();
        if ($ack !== null && ($ack[0] >> 4) === 2 && strlen($ack[1]) >= 2 && ord($ack[1][1]) === 0) {
            $sub = pack('n', 1);
            foreach ($filter as $t) { $sub .= $zk($t) . chr(0); }
            @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
            $bestaetigt = false;
            $abgelehnt = false;
            $ende = microtime(true) + 3.0;
            while (microtime(true) < $ende) {
                $pk = $paket();
                if ($pk === null) { break; }
                $art = $pk[0] >> 4;
                if ($art === 9) {
                    $rc = (string) substr($pk[1], 2);
                    if (strlen($rc) !== count($filter)) { $abgelehnt = true; }
                    for ($i = 0; $i < strlen($rc); $i++) {
                        if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                    }
                    if ($abgelehnt) { break; }
                    $bestaetigt = true;
                    $ende = min($ende, microtime(true) + 1.0);
                } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                    $tl = unpack('n', substr($pk[1], 0, 2));
                    $t = substr($pk[1], 2, $tl[1]);
                    $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                    $wert = (string) substr($pk[1], $versatz);
                    if (($pk[0] & 1) && $wert !== '') {
                        $aus['werte'][$t] = $wert;
                    }
                }
            }
            if ($bestaetigt && !$abgelehnt) {
                $aus['lage'] = 'ok';
            } else {
                $aus['werte'] = array();
            }
        }
        @fwrite($s, chr(0xE0) . chr(0));
    }
    fclose($s);
    return $aus;
}

/**
 * Die Lesekoepfe, unter denen diese Linie Zustaende gesendet haben kann:
 * jeder Abschnitt der Konfiguration mit DEVICE, der vzLogger-Weg und jeder
 * Kopf mit einem Merker <kopf>.retained auf der Ramdisk (bin/fetch.php,
 * bin/fetch_vzlogger.pl). Nur unter DIESEN Namen wird abgeraeumt - ein
 * fremdes Thema unter demselben Praefix bleibt stehen.
 */
function smg_mqtt_koepfe($ordner, $cfg, $vzd)
{
    $koepfe = array();
    foreach ($cfg as $ab => $w) {
        if ($ab !== 'MAIN' && is_array($w) && isset($w['DEVICE'])) {
            $koepfe[] = (string) $ab;
        }
    }
    if (is_array($vzd)) {
        $koepfe[] = smg_vz_serial($vzd);
    }
    $merker = @glob('/dev/shm/' . $ordner . '/*.retained');
    if (is_array($merker)) {
        foreach ($merker as $m) {
            $koepfe[] = basename($m, '.retained');
        }
    }
    return array_values(array_unique($koepfe));
}

/**
 * Welche der Themen gehoeren zu den retained Zustaenden DIESER Linie unter
 * $praefix? <praefix>/<kopf>/<Zustandsfeld> fuer einen der Koepfe aus
 * smg_mqtt_koepfe() und <praefix>/abgleich/<n>/aktiv. Fremde Themen unter
 * demselben Praefix fasst das Abraeumen nicht an.
 */
function smg_mqtt_eigene($praefix, $themen, $koepfe)
{
    $aus = array();
    $vor = (string) $praefix . '/';
    foreach ($themen as $t) {
        $t = (string) $t;
        if ($vor === '/' || strncmp($t, $vor, strlen($vor)) !== 0) {
            continue;
        }
        $rest = explode('/', substr($t, strlen($vor)));
        if (count($rest) === 2 && in_array($rest[0], $koepfe, true) && smg_mqtt_retained($rest[1])) {
            $aus[] = $t;
        } elseif (count($rest) === 3 && $rest[0] === 'abgleich'
                  && preg_match('/^[0-9]+$/', $rest[1]) && $rest[2] === 'aktiv') {
            $aus[] = $t;
        }
    }
    return array_values(array_unique($aus));
}

/**
 * Die Themen, die abgeraeumt werden, wenn der Broker NICHT zu fragen ist:
 * jeder Kopf aus smg_mqtt_koepfe() mit den drei Zustandsfeldern, dazu
 * abgleich/<n>/aktiv jeder bekannten Regel.
 */
function smg_mqtt_ersatz($home, $ordner, $koepfe, $praefix)
{
    $t = array();
    foreach (array_unique($koepfe) as $s) {
        foreach (smg_mqtt_zustaende() as $z) {
            $t[] = $praefix . '/' . $s . '/' . $z;
        }
    }
    $ab = @json_decode((string) @file_get_contents($home . '/data/plugins/' . $ordner . '/abgleich.json'), true);
    if (is_array($ab) && isset($ab['regeln']) && is_array($ab['regeln'])) {
        foreach (array_keys($ab['regeln']) as $n) {
            if (preg_match('/^[0-9]+$/', (string) $n)) {
                $t[] = $praefix . '/abgleich/' . $n . '/aktiv';
            }
        }
    }
    return array_values(array_unique($t));
}

/**
 * Die retained Zustaende dieser Linie unter EINEM Praefix leeren
 * (Entscheidungen 3 und 26; Bauart mo_mqtt_raeumen(), Robonect 1.1.15).
 *
 * Geloescht wird ueber den UDP-Eingang des Gateways mit "retain <thema> "
 * und leerer Nutzlast. Belegt ist das Abraeumen erst, wenn der Broker selbst
 * sagt, dass nichts mehr dasteht: fwrite() meldet auch fuer ein verworfenes
 * Datagramm Erfolg (Regeln/07; Gedaechtnis "Merker auf sendto-Erfolg" ist
 * genau dieser Fehler). Vorher und nach jeder Runde wird nachgelesen; ist
 * der Broker nicht zu fragen, gehen die Ersatzthemen hinaus, und die Zeilen
 * sagen, dass nicht nachgelesen wurde. Schreibt kein Protokoll, legt nichts an.
 *
 * Rueckgabe array('zeilen', 'rc' => 0 geleert oder nicht nachpruefbar,
 * 1 es steht noch etwas bzw. Eingang nicht erreichbar, 2 kein Eingang;
 * 'eingang', 'nachgelesen', 'offen').
 */
function smg_mqtt_raeumen($home, $praefix, $koepfe, $ersatz, $runden = 3, $pause_us = 1000000)
{
    $aus = array('zeilen' => array(), 'rc' => 0, 'eingang' => false, 'nachgelesen' => false,
                 'offen' => array());
    $praefix = (string) $praefix;
    $udp = smg_mqtt_udpport($home);
    if (!$udp) {
        $aus['zeilen'][] = '<INFO> MQTT: in der general.json steht kein UDP-Eingang des Gateways - '
            . 'zurueckbehaltene Zustaende unter ' . $praefix . '/ wurden nicht geleert.';
        $aus['rc'] = 2;
        return $aus;
    }
    $aus['eingang'] = true;
    $f = smg_mqtt_behalten($home, array($praefix . '/#'));
    $nachgelesen = ($f['lage'] === 'ok');
    $offen = $nachgelesen ? smg_mqtt_eigene($praefix, array_keys($f['werte']), $koepfe)
                          : array_values(array_unique($ersatz));
    if ($nachgelesen && !$offen) {
        $aus['zeilen'][] = '<OK> MQTT: der Broker bestaetigt: unter ' . $praefix
            . '/ steht kein zurueckbehaltener Zustand dieses Plugins - nichts zu leeren.';
        $aus['nachgelesen'] = true;
        return $aus;
    }
    if (!$offen) {
        $aus['zeilen'][] = '<INFO> MQTT: der Broker liess sich nicht befragen, und es ist kein Thema '
            . 'unter ' . $praefix . '/ bekannt - nichts gesendet.';
        return $aus;
    }
    $e1 = 0;
    $e2 = '';
    $fp = function_exists('stream_socket_client')
        ? @stream_socket_client('udp://127.0.0.1:' . (int) $udp, $e1, $e2, 2) : false;
    if (!$fp) {
        $aus['zeilen'][] = '<WARNING> MQTT: der UDP-Eingang des Gateways ist nicht erreichbar (Port '
            . (int) $udp . ') - zurueckbehaltene Zustaende unter ' . $praefix . '/ wurden nicht geleert.';
        $aus['rc'] = 1;
        $aus['eingang'] = false;
        $aus['offen'] = $offen;
        return $aus;
    }
    $zahl = count($offen);
    $datagramme = 0;
    $gelaufen = 0;
    for ($r = 1; $r <= max(1, (int) $runden) && $offen; $r++) {
        if ($r > 1) { usleep((int) $pause_us); }
        $gelaufen = $r;
        foreach ($offen as $t) {
            // Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: die
            // Form, die das Gateway als Loeschung liest.
            $zeile = 'retain ' . $t . ' ';
            if (@fwrite($fp, $zeile) === strlen($zeile)) { $datagramme++; }
        }
        usleep(300000);
        $f = smg_mqtt_behalten($home, array($praefix . '/#'));
        if ($f['lage'] === 'ok') {
            $nachgelesen = true;
            $offen = array_values(array_intersect($offen, array_keys($f['werte'])));
        } else {
            $nachgelesen = false;
        }
    }
    fclose($fp);
    $aus['nachgelesen'] = $nachgelesen;
    $aus['offen'] = $offen;
    $aus['zeilen'][] = '<INFO> MQTT: ' . $zahl . ' zurueckbehaltene(r) Zustand/Zustaende unter ' . $praefix
        . '/ mit leerer Nutzlast an den UDP-Eingang ' . (int) $udp . ' gesendet (' . $gelaufen
        . ' Runde(n), ' . $datagramme . ' Datagramme).';
    if ($nachgelesen && !$offen) {
        $aus['zeilen'][] = '<OK> MQTT: der Broker bestaetigt: unter ' . $praefix
            . '/ steht kein zurueckbehaltener Zustand dieses Plugins mehr.';
        return $aus;
    }
    if ($nachgelesen) {
        $aus['zeilen'][] = '<WARNING> MQTT: ' . count($offen) . ' Thema/Themen stehen noch zurueckbehalten ('
            . implode(', ', array_slice($offen, 0, 5)) . (count($offen) > 5 ? ', ...' : '')
            . '). Von Hand: mosquitto_pub -r -n -t <thema> (mit den Broker-Zugangsdaten).';
        $aus['rc'] = 1;
        return $aus;
    }
    $aus['zeilen'][] = '<INFO> MQTT: der Broker liess sich nicht befragen - nicht nachgelesen. Der '
        . 'UDP-Eingang verwirft unter Last Datagramme; was stehen bleibt, laesst sich mit '
        . 'mosquitto_pub -r -n -t <thema> von Hand loeschen.';
    return $aus;
}

/** Die Datei mit den vorgemerkten frueheren Praefixen (im Konfigurationsordner). */
function smg_mqtt_stand_datei($home, $ordner)
{
    return $home . '/config/plugins/' . $ordner . '/mqtt_stand.json';
}

/** Die vorgemerkten frueheren Praefixe - nur zulaessige, ohne Doppel. */
function smg_mqtt_vorgemerkt($home, $ordner)
{
    $d = @json_decode((string) @file_get_contents(smg_mqtt_stand_datei($home, $ordner)), true);
    $aus = array();
    if (is_array($d) && isset($d['praefix_alt']) && is_array($d['praefix_alt'])) {
        foreach ($d['praefix_alt'] as $v) {
            if (is_string($v) && smg_praefix_fehler($v) === '' && !in_array($v, $aus, true)) {
                $aus[] = $v;
            }
        }
    }
    return $aus;
}

/**
 * Ein Praefix vormerken ($weg = false) oder aus der Liste nehmen ($weg = true).
 * Geschrieben wird nur, wenn sich die Liste aendert. Rueckgabe: geglueckt?
 */
function smg_mqtt_vormerken($home, $ordner, $praefix, $weg = false)
{
    $alt = smg_mqtt_vorgemerkt($home, $ordner);
    $neu = array();
    foreach ($alt as $v) {
        if ($v !== $praefix) { $neu[] = $v; }
    }
    if (!$weg) { $neu[] = (string) $praefix; }
    $neu = array_values(array_slice($neu, -10));
    if ($neu === $alt) {
        return true;
    }
    $datei = smg_mqtt_stand_datei($home, $ordner);
    if (!is_dir(dirname($datei))) {
        return false;
    }
    $tmp = $datei . '.tmp.' . getmypid();
    $js = json_encode(array('praefix_alt' => $neu));
    if ($js === false || @file_put_contents($tmp, $js . "\n") === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Ist ein Themenpraefix zulaessig? Rueckgabe '' oder ein Grundwort.
 *
 * EINE Regel fuer Formular und Zurueckspielen (M2, C1; Entscheidung 19):
 * leer, Leerraum innen, Anfuehrungszeichen, Steuerzeichen, die Platzhalter
 * # und + (in einem PUBLISH-Thema verboten - der Broker trennt das Gateway)
 * und ein Schraegstrich am Rand oder doppelt (leere Ebene) werden
 * BEANSTANDET, nicht entfernt. Leerraum am RAND schneidet der Aufrufer still ab.
 */
function smg_praefix_fehler($t)
{
    $t = (string) $t;
    if ($t === '') {
        return 'leer';
    }
    if (preg_match('/[\x00-\x1f\x7f]/', $t)) {
        return 'steuerzeichen';
    }
    if (preg_match('/\s/u', $t) || preg_match('/\s/', $t)) {
        return 'leerraum';
    }
    if (strpbrk($t, '"\'') !== false) {
        return 'anfuehrung';
    }
    if (strpbrk($t, '#+') !== false) {
        return 'platzhalter';
    }
    if ($t[0] === '/' || substr($t, -1) === '/' || strpos($t, '//') !== false) {
        return 'schraegstrich';
    }
    if (strlen($t) > 100) {
        return 'lang';
    }
    return '';
}

/**
 * Die Abodatei des MQTT-Gateways nachfuehren: config/plugins/<ordner>/
 * mqtt_subscriptions.cfg mit einer Zeile "<praefix>/#" (M3; Regeln/07: das
 * Gateway V1 liest diese Datei jedes Plugins und abonniert daraus). Geschrieben
 * wird nur, wenn sie abweicht. Rueckgabe array(pfad, steht das Abo darin?,
 * wurde geschrieben?).
 */
function smg_abo_datei($home, $ordner, $praefix, $schreiben)
{
    $pfad = $home . '/config/plugins/' . $ordner . '/mqtt_subscriptions.cfg';
    $soll = (string) $praefix . '/#';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', preg_split('/\r?\n/', $roh)), true);
    $geschrieben = false;
    if ($schreiben && $roh !== $soll . "\n" && smg_praefix_fehler($praefix) === ''
        && is_dir(dirname($pfad))) {
        $tmp = $pfad . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $soll . "\n") !== false && @chmod($tmp, 0644)
            && @rename($tmp, $pfad)) {
            $da = true;
            $geschrieben = true;
        } else {
            @unlink($tmp);
        }
    }
    return array($pfad, $da, $geschrieben);
}

}
