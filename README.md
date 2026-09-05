# Smartmeter Classic

LoxBerry-Plugin zum Auslesen von Stromzählern über optische IR-Leseköpfe —
mit **beiden** Lesewegen: dem klassischen Perl-Leser und vzlogger.

> **Abspaltung.** Dieses Plugin führt das eingestellte
> [Smartmeter-Plugin](https://github.com/mschlenstedt/LoxBerry-Plugin-Smartmeter)
> von Michael Schlenstedt weiter. Dessen Nachfolger
> [Smartmeter-NG](https://github.com/mschlenstedt/LoxBerry-Plugin-Smartmeter-NG)
> setzt ausschließlich auf vzlogger und hat den Legacy-Leser entfernt.
> Einzelheiten und Lizenzangaben in [`NOTICE`](NOTICE).

## Warum zwei Lesewege

vzlogger ist der modernere Weg und für die meisten Zähler die bessere Wahl.
Er ist aber nicht überall verfügbar oder erfolgreich:

- Auf manchen Installationen lässt sich kein passendes vzlogger-Paket
  installieren.
- Manche Zähler liefern Telegramme, die vzlogger verwirft, bevor man den Grund
  kennt.

Der klassische Perl-Leser braucht kein zusätzliches Programm und liest solche
Zähler weiterhin. **Beide Wege stehen in diesem Plugin nebeneinander** — man
wählt, was funktioniert.

> Nur **einer** von beiden darf gleichzeitig aktiv sein: eine serielle
> Schnittstelle kann immer nur ein Prozess öffnen. Die Diagnose warnt, wenn
> beide auf dasselbe Gerät zugreifen.

## Oberfläche

| Reiter | Inhalt |
|---|---|
| **Smartmeter (vzLogger)** | Einstellungen und eine achtstufige Diagnose |
| **Smartmeter (Legacy)** | der klassische Leser mit Zählerprofilen |
| **MQTT** | die einzige Stelle, an der MQTT eingestellt wird |
| **Einbindung in Loxone** | MQTT-Themen, UDP-Port, HTTP-Adresse — mit den gespeicherten Werten |
| **Test** | Knopfreihen nach Farbregel |
| **Logdateien** | vzlogger- und Abholprotokoll |

### Die Diagnose

Der Reiter *vzLogger* prüft acht Dinge einzeln und zeigt Haken, Ausrufezeichen
oder Kreuz: Betriebsart, Programm, Prozess, Konfiguration, Lesekopf, Belegung
der Schnittstelle, HTTP-Schnittstelle, Messwerte. **Die erste rote Zeile von
oben ist die Ursache**, alles darunter meist die Folge.

Darunter stehen die letzten Zeilen des vzlogger-Protokolls. Genau daran hing
die Fehlersuche, aus der dieses Plugin entstanden ist: vzlogger las den Zähler
einwandfrei und verwarf jedes Telegramm, weil der Zähler keine gestellte Uhr
sendet. Ohne Protokoll ist das nicht zu finden.

## vzlogger

Das Plugin liefert **kein** vzlogger mit. Es installiert es bei Bedarf aus der
signierten Paketquelle von volkszaehler.org — der Paketmanager löst die
Abhängigkeiten passend zur eigenen Debian-Fassung und Architektur auf.

Der vom Paket mitgebrachte systemd-Dienst wird dabei abgeschaltet und
maskiert; vzlogger wird vom Plugin gestartet und von einem Wächter überwacht,
der es nach Update, Neustart und Absturz binnen einer Minute zurückholt.

Beim Deinstallieren wird vzlogger nur entfernt, wenn dieses Plugin es
installiert hat.

## Werte an den Miniserver

**MQTT ist der Standardweg.** Themen:

    <Praefix>/<Zaehlernummer>/<Kennzahl>

**Beide Betriebsarten senden dasselbe Schema** — dieselben Schlüssel, dasselbe
Thema, denselben UDP-Satz. Ein Wechsel zwischen klassischem Leser und vzlogger
ändert im Miniserver nichts, solange im Reiter *vzLogger* dieselbe
**Zählernummer** eingetragen ist.

Das MQTT-Gateway ersetzt in den Themen nur `/` und `%` durch `_`; Punkte bleiben
stehen. Der virtuelle Eingang heißt also
`smartmeter_1234_Consumption_Total_OBIS_1.8.0`.

### In der vzLogger-Betriebsart abgeleitete Werte

vzlogger liefert weder Zeitstempel noch die kalkulierten Leistungen. Das Plugin
ergänzt sie, damit vorhandene Auswertungen weiterlaufen:

| Schlüssel | Herkunft |
|---|---|
| `Last_Update`, `Last_UpdateLoxEpoche` | Rechner-Uhrzeit zum Abholzeitpunkt |
| `Consumption_CalculatedPower_OBIS_1.99.0` | aus `16.7.0`, positiver Anteil |
| `Delivery_CalculatedPower_OBIS_2.99.0` | aus `16.7.0`, negativer Anteil |

**Der klassische Leser rechnet die beiden letzten anders** — aus dem
Zählerfortschritt. Für Zähler mit `16.7.0` ist die Ableitung genauer, aber es
ist nicht dieselbe Größe. Wer beide Betriebsarten vergleicht, wird kleine
Unterschiede sehen.

Ob im MQTT-Gateway ein Abo einzutragen ist, hängt an dessen Fassung:

- **Gateway V1** — das Abo `<Praefix>/#` muss eingetragen sein, sonst kommt am
  Miniserver nichts an.
- **Gateway V2** — das Gateway erkennt die Themengruppe von selbst; ein Abo ist
  nicht einzutragen, der Kern schaltet die Knöpfe dafür sogar ab.

Welche Fassung läuft, steht im Reiter *MQTT* — und dort steht auch nur der
Satz, der auf diese Anlage zutrifft. Der Reiter *Einbindung in Loxone* zeigt
die vollständigen Themen und die Namen der virtuellen Eingänge.

UDP steht weiterhin zur Verfügung, wo es gebraucht wird.

## Zähler ohne gestellte Uhr

Viele Haushaltszähler senden keinen gültigen Zeitstempel. vzlogger verwirft
solche Telegramme mit `timestamp before 1990, IGNORING` — der Zähler wird
gelesen, aber kein Wert kommt an.

Die Einstellung **Zeitstempel** steht deshalb standardmäßig auf
*Rechner-Uhrzeit*. Der Zeitstempel des Zählers wird nur gebraucht, wenn eine
Middleware eine Zeitreihe erwartet; bei der Weitergabe an den Miniserver
stempelt dieser selbst.

## Voraussetzungen

- LoxBerry ab 3.0.0 (so steht es auch in der `plugin.cfg`)
- optischer IR-Lesekopf am USB — die udev-Regel legt das Plugin an
- für die vzLogger-Betriebsart ein installierbares vzlogger-Paket

## Fassung 2.3.2 — zweisprachig und aufgeräumt

### Deutsch und Englisch

Die Oberfläche schrieb ihre Texte bisher unmittelbar auf Deutsch ins HTML.
`templates/lang/language_de.ini` und `language_en.ini` lagen zwar im Plugin,
enthielten aber nur 20 Schlüssel, die **kein** Programmteil las — eine
begonnene und nie zu Ende geführte Übersetzung.

Jetzt geht jeder sichtbare Text durch `sm_t()` in `sm_lib.php`. Beide
Sprachdateien haben **238 Schlüssel und sind deckungsgleich**; jeder wird
benutzt, keiner fehlt. **Englisch ist die Rückfallebene:** fehlt ein Schlüssel
in der gewählten Sprache, greift der englische; fehlt auch der, erscheint der
Schlüsselname selbst — Absicht, denn eine leere Seite verschweigt den Fehler,
ein sichtbares `VZ.LABEL_DEVICE` nicht.

Nicht übersetzt sind die 39 Gerätebezeichnungen (*Iskra MT681*, *Landis &
Gyr E220*) — sie sind in jeder Sprache dieselben. Die Auswahlliste hat 43
Einträge; übersetzt werden die vier allgemeinen (*noch nicht festgelegt*,
*von Hand einstellen*, *Allgemeines D0*, *Allgemeines SML*).
`bin/sm_logger.pl` kennt 41 Protokolle — das sind die 39 Gerätemodelle plus
`genericd0` und `genericsml`. Die Zahlen 41 und 45 standen hier bis 2.4.2
und waren beide falsch; nachgezählt am 02.09.2026 an `sm_profile()` und an
den `$protocol eq`-Zweigen des Lesers.

Zwei Dinge fielen beim Umbau nebenbei an:

- **Die Reiter waren `<div>`, keine Verweise.** Alle Flächen stehen bis zum
  Lauf des JavaScripts auf `display:none` — ohne JavaScript war die Seite
  also vollständig leer. Die Reiter sind jetzt echte Links, und der Server
  setzt die Klasse `sm-active` sowohl am Reiter als auch an der Fläche. Das
  JavaScript spart nur noch den Seitenaufbau.
- **`data-role="none"` fehlte an allen 35 Formularelementen.** jQuery Mobile,
  das die LoxBerry-Oberfläche lädt, baut Knöpfe, Auswahlfelder und
  Eingabefelder sonst um, und die Gestaltung des Plugins greift nicht.

### Aufgeräumt

- **Eigenes Plugin-Symbol.** Bis 2.3.1 lag hier das Symbol des Originals (das
  lächelnde Haus mit Strommast): 181 KB nachgezeichnete Pfade, fast ein Drittel
  des gesamten Plugins. Zwei gleiche Symbole nebeneinander in der
  Pluginverwaltung sind eine Falle, keine Verwandtschaftsangabe — und die
  Apache-Lizenz lizenziert Kennzeichen ausdrücklich *nicht* mit (Abschnitt 6).
  Das neue Symbol zeigt einen Zähler mit optischer Schnittstelle und braucht
  rund 2 KB.
- **Toter Zweig im `daemon`.** Er suchte ein mitgeliefertes vzlogger unter
  `bin/plugins/<ordner>/vzlogger/`. Diesen Ordner legt kein Skript an — das
  Plugin liefert kein vzlogger mit, sondern installiert es aus der Paketquelle.
  Der Zweig stammt aus Smartmeter-NG und konnte hier nie zutreffen.
- **`libdatetime-perl`** steht jetzt in `dpkg/apt`. `bin/sm_logger.pl` — der
  klassische Leser, also der Grund für diese Abspaltung — benutzt `DateTime`
  und `DateTime::TimeZone`; deklariert war beides bisher nirgends (auch im
  Original nicht). Ist das Paket ohnehin vorhanden, ist der Eintrag folgenlos.
- **`use DateTime::TimeZone;`** war auskommentiert, die Klasse wird aber
  benutzt. Es ging bisher nur gut, weil `DateTime` das Modul selbst nachlädt.
- **`.gitattributes`** enthielt die msysgit-Vorlage mit Regeln für
  Visual-Studio-Projekte, Word und RTF. Ersetzt durch das, was hier zählt:
  LF für Shell- und Perl-Skripte. Ein CRLF hinter der Shebang-Zeile macht aus
  `#!/bin/bash` ein `#!/bin/bash\r` — „bad interpreter".
- **Hilfeseite:** aus einer Markdown-Vorlage übernommene `>`-Zeichen standen
  als `&gt;` mitten im Fließtext. Ebenso ein Verweis auf `NOTICE`, der ins
  Leere ging (die Datei liegt nicht unter `webfrontend`).

## Fassung 2.3.3 — nachgemessen und korrigiert

Eine Durchsicht durch einen anderen Programmierer brachte zwölf Punkte. Sechs
trafen zu, drei teilweise, drei nicht. Alles wurde am Code nachgestellt,
bevor etwas geändert wurde.

### Vier Rückfälle trugen den Namen des Originalplugins

Der Ordnername wird überall aus dem Ablageort der jeweiligen Datei abgeleitet
— richtig so. Griff die Ableitung aber nicht, stand als Rückfall `smartmeter`
im Code. **So heißt der Ordner des Originalplugins**, das neben dieser
Abspaltung installiert sein kann; LoxBerry hält beide auseinander, weil die
Kennungen verschieden sind.

Der schwerste der vier steckte in `sm_cron_setzen()`. Die Funktion räumt
zuerst „tabula rasa" jede Verknüpfung dieses Namens aus allen `cron.*`-Ordnern
weg, bevor sie die neue setzt:

```php
$name = sm_cfg_get(sm_cfg_read(), 'MAIN', 'SCRIPTNAME', 'smartmeter');
foreach (sm_cron_ordner() as $ordner) { @unlink($basis . $ordner . '/' . $name); }
```

War `smartmeter.cfg` nicht lesbar — vor dem ersten Speichern, nach einem
abgebrochenen Update —, griff der Rückfall, und ein Speichern im Reiter
*Legacy* hätte die **Cron-Einträge des fremden Plugins gelöscht**. Rückfall ist
jetzt der ermittelte Pluginordner; `sm_cron_ist()` als Gegenstück ebenso, sonst
suchte das Lesen woanders als das Schreiben.

Dieselbe Korrektur in `sm_lib.php` (Konfiguration, `/dev/shm`, Protokoll) und
im Endpunkt `webfrontend/html/index.php`, der sonst die Ramdisk des fremden
Plugins gelesen und dessen Zählerwerte an den Miniserver geliefert hätte.

Nicht angefasst: das MQTT-Thema. Dessen Vorgabe `smartmeter` ist eine
Einstellung, keine Ableitung — sie zu ändern verschöbe die Themen bei jeder
bestehenden Installation.

### `PRERELEASECFG` war leer

Bei eingeschaltetem Auto-Update. Die `prerelease.cfg` wird ohnehin gepflegt und
führt dieselbe Fassung wie die `release.cfg`; sie ist jetzt eingetragen.

### Die Leseschleife endete nie von selbst

Der schwerste Fund, und keiner aus der Liste. In `READ_SERIAL` stand:

```perl
our $count = 5;
while ($count > 0) {
    my ($count, $saw) = $port->read(255);   # eigenes $count!
    ...
    else { $count--; }                      # zählt das INNERE herunter
}
```

Das `my` legt bei **jedem** Durchlauf eine neue Variable an. Die Bedingung der
`while`-Schleife steht außerhalb ihres Gültigkeitsbereichs und sah weiterhin
die äußere Variable mit dem Wert 5. Nachgestellt mit derselben Struktur:

| | Runden | äußerer Zähler danach |
|---|---|---|
| mit `my` (bisher) | 200 000 (Notbremse) | 5 |
| ohne `my` | 5 | 0 |

Beendet hat die Schleife also ausschließlich das `alarm()`. Folge: **jede**
Abfrage dauerte genau so lange wie die Zeitgrenze des Zählerprofils — je nach
Zähler 5 bis 120 Sekunden — auch wenn der Zähler sofort geantwortet hatte.
Gemessen mit einem nachgestellten Lesekopf und 3 s Grenze:

| Lesekopf | bisher | jetzt |
|---|---|---|
| antwortet sofort | 3,00 s, 6 493 444 Runden | 0,00 s, 8 Runden, dieselben Daten |
| schweigt | 3,00 s | 0,00 s, 5 Runden |
| sendet endlos Müll | 3,00 s, **58 MB** im Puffer | 0,05 s, bei 1 MB abgebrochen |

Der Müll-Fall war der einzige, den die Durchsicht ahnte — allerdings mit der
falschen Begründung („der Iterator wird nur heruntergezählt, wenn keine Bytes
kommen"). Auf 120 s hochgerechnet wären es rund 2,3 GB gewesen, auf einem
Raspberry Pi das Ende. Es gibt jetzt zwei Netze: der Zähler beendet die
Schleife wieder selbst, und Rundenzahl wie Puffergröße sind zusätzlich
begrenzt.

Daran hing auch die minutenlang hängende Oberfläche beim Knopf *Jetzt
abfragen*. Die vorgeschlagene Zeitbrücke ist trotzdem eingebaut (`timeout 25`),
denn ein Lesekopf, der gar nicht mehr antwortet, darf die Bedienung nicht
mitnehmen.

### Die Datendateien wurden nicht unteilbar geschrieben

Trifft zu. `open(F, ">…/$serial.data")` kürzt die Datei und füllt sie neu;
dazwischen ist sie leer, und genau dorthin liest der Miniserver. Gemessen mit
einem Schreiber und einem Leser auf `/dev/shm`:

| Verfahren | unvollständige oder leere Lesevorgänge |
|---|---|
| kürzen und füllen (bisher) | **69,75 %** |
| temp + `rename` (jetzt) | **0,00 %** |

Ehrlicherweise: das ist eine Dauerbelastung mit 180 000 Schreibvorgängen in
sechs Sekunden. Im Betrieb wird einmal je Minute geschrieben, und das
unbrauchbare Fenster dauert rund **35 Mikrosekunden**. Es trifft also selten —
aber wenn es trifft, steht in der Loxone-Statistik eine 0, für die es keine
Erklärung gibt. `rename()` ist im selben Dateisystem unteilbar und kostet
nichts.

### Der Endpunkt schrieb PHP-Meldungen zwischen die Messwerte

Trifft zu, an zwei Stellen. Beide gemessen, in 7.4 und 8.1:

* `array_pop(array_filter(explode(…)))` → *„Only variables should be passed by
  reference"* in **beiden** Fassungen.
* `in_array($daten['extension'], …)` ohne `isset` → 7.4 *„Undefined index"*,
  8.1 *„Undefined array key"*, sobald eine Datei ohne Endung im Ordner liegt.

Der Miniserver kann zwischen einer Warnung und einem Messwert nicht
unterscheiden. `webfrontend/html/index.php` ist deshalb neu gefasst: keine
Meldungen im Datenstrom, feste Reihenfolge der Zählerdateien, und ein
**freiwilliges** Token (`?token=…`, Vergleich mit `hash_equals`). Freiwillig,
weil ein Pflichttoken bei jedem bestehenden Aufbau die Werte im Miniserver
abreißen ließe, ohne dass jemand versteht, warum.

Die Konfiguration wird dabei zeilenweise gelesen, nicht mit
`parse_ini_file()`. Gegenprobe mit einer Lesekopf-Bezeichnung
`Keller & Garage (Haupt!)`: `parse_ini_file()` verwirft die **ganze** Datei —
das Token wäre plötzlich leer und der Endpunkt offen.

### Der Parser schrieb Diagnosen auf die Standardausgabe

Im Ergebnis richtig, in der Begründung nicht. Die beanstandete Zeile
`echo("ERROR: …")` ist toter Code — `error()` beginnt mit `return;`.
Tatsächlich landeten **PHP-eigene Warnungen** im Datenstrom, und
`sm_logger.pl` nimmt die Standardausgabe des Parsers als Messwerte entgegen.
Mit einem verstümmelten Dump gemessen:

| | bisher | jetzt |
|---|---|---|
| PHP 7.4 | `Warning: pack(): Type H: illegal hex digit` | STDOUT leer (0 Zeichen) |
| PHP 8.1 | dieselbe **und** `Warning: Uninitialized string offset 0` | STDOUT leer (0 Zeichen) |

Drei weitere, tatsächlich erreichbare `echo`-Stellen (`… bytes skipped …`)
gingen ebenfalls nach STDOUT und gehen jetzt nach STDERR.

Zum `hexdec`-Überlauf: er tritt erst oberhalb von 2^63 ein (gemessen:
`hexdec('FFFFFFFFFFFFFFFF')` ergibt einen `double`). Der einzige realistische
Fall — negative 64-Bit-Werte — ist im Parser bereits eigens behandelt. Ein
Zählerstand von 9,2 Trillionen Wh ist keiner. Hier wurde bewusst **nichts**
geändert: an einem funktionierenden Parser ohne Anlass zu schrauben ist das
größere Risiko.

### Überlappende Cron-Läufe

Der Hinweis nannte `reboot_cron_runner.sh` — das Skript liegt aber in
`cron.reboot` und läuft einmal beim Systemstart, nicht im Minutentakt. Im
Minutentakt laufen `fetch.php` (wenn der Benutzer diesen Takt wählt) und
`fetch_vzlogger.pl` (fest in `cron/crontab`). Beide haben jetzt eine Sperre
über `flock(LOCK_EX|LOCK_NB)`; ein zweiter Aufruf endet sofort und ohne
Meldung. Ohne sie griffen zwei Prozesse auf **denselben** Lesekopf zu — eine
serielle Schnittstelle lässt sich nicht teilen.

### Die Upgrade-Sicherung — Einwand halb richtig, Abhilfe falsch

Beanstandet war, `$1` sei bereits ein absoluter Pfad, `/tmp/$ARGV1_upgrade`
ergebe also `/tmp//tmp/uploads/xyz_upgrade`. Das trifft **nicht** zu. Der
Installer ruft das Skript so auf (`sbin/plugininstall.pl`):

```
"$script" "$tempfile" "$pname" "$pfolder" "$pversion" "$lbhomedir" "$tempfolder"
```

`$1` ist `$tempfile` — eine Zufallskennung aus zehn Zeichen (`&generate(10)`).
Der absolute Arbeitsordner kommt als **sechstes** Argument. Die vorgeschlagene
Abhilfe `cp -r … "$ARGV1/config"` wurde nachgestellt:

```
cp: cannot create directory 'IRFLu1Zh7s/config': No such file or directory
```

Sie hätte die Sicherung also gar nicht angelegt — und weil der Installer
zwischen `preupgrade` und `postupgrade` die **mitgelieferte**
`config/smartmeter.cfg` über die des Benutzers kopiert, wäre nach jedem
Upgrade alles auf Werkseinstellung zurückgesetzt gewesen. Genau der
Datenverlust, den der Hinweis verhindern wollte.

Richtig ist der zweite Teil: `/tmp` ist flüchtig. Gesichert wird jetzt in den
Arbeitsordner des Installers (sechstes Argument), den dieser **nach**
`postupgrade` selbst aufräumt, mit Rückfall auf den alten Weg für ältere
LoxBerry-Fassungen. Beide Wege nachgestellt — Takt, MQTT-Thema, Token und
Lesekopf-Bezeichnungen überstehen das Upgrade, es bleiben keine Reste liegen.

Die Sicherung der **Protokolle** ist ersatzlos entfallen: `log/plugins` liegt
auf dem LoxBerry fest auf der Ramdisk (`sbin/createtmpfsfoldersinit.sh`) und
ist nach jedem Neustart ohnehin leer.

### `REBOOT=true` — der Hinweis stimmt, der Grund war ein anderer

Es gab sehr wohl einen Grund: die udev-Regel, die den Lesekopf unter dem
stabilen Namen `/dev/serial/smartmeter/<Seriennummer>` erreichbar macht,
wurde ausschließlich von `daemon/daemon` angelegt — und der läuft erst beim
Systemstart. Ohne Neustart also kein Lesekopf.

`postroot.sh` legt sie jetzt schon während der Installation an (als root) und
lädt sie mit `udevadm control --reload-rules` plus `udevadm trigger` nach. Die
erzeugte Regelzeile wurde Zeichen für Zeichen mit der aus `daemon/daemon`
verglichen — identisch. `REBOOT` steht auf `false`.

### Was nicht zutraf

**Code Injection über die Legacy-Seite.** Ein `var_export` gibt es im ganzen
Plugin nicht. Von den Eingaben der Legacy-Seite werden Takt, UDP-Port und
Zählerprofil gegen Positivlisten geprüft; frei ist nur die **Bezeichnung** des
Lesekopfs, und die erreicht keine Shell — `sm_logger.pl` kennt sie nicht
einmal. Alle Argumente in `fetch.php` gehen durch `escapeshellarg()`.

**`libdevice-serialport-perl` fehle in `dpkg/apt`.** Es steht dort, Zeile 2.

**Reihenfolge im Uninstall.** Der Einwand setzt voraus, systemd könne den mit
`pkill` beendeten Prozess neu starten. Das kann es nicht: dieses Plugin
startet `vzlogger` gar nicht über systemd, sondern in `daemon/daemon` mit
`nohup`, und `vzlogger_pkg.sh` **maskiert** die mitgelieferte
`vzlogger.service` ausdrücklich. Die Reihenfolge ist trotzdem gedreht — sie
kostet nichts und deckt den Fall ab, dass jemand von Hand demaskiert hat.

**`systemctl unmask` sei überflüssig und störe dpkg.** Das Gegenteil: eine
Maskierung ist ein Verweis `/etc/systemd/system/vzlogger.service → /dev/null`,
den kein Paket besitzt. `apt-get purge` entfernt nur Dateien des Pakets — der
Verweis bliebe liegen und würde eine spätere Neuinstallation von `vzlogger`
stillschweigend lahmlegen. Das `unmask` bleibt.

**Die eigene `.htaccess` sei fehleranfällig.** Sie schützt nichts, was
LoxBerry nicht ohnehin schützt, ist aber auch nicht schädlich und wurde
unverändert gelassen — sie ohne Not zu entfernen wäre eine Änderung, deren
Wirkung sich erst auf fremden Systemen zeigt.

## Fassung 2.3.14 — eine Quelle je Sache

### Sichern und Zurückspielen über zwei Knöpfe

Im Reiter *Smartmeter (vzLogger)* stehen zwei neue Knöpfe. *Einstellungen
sichern* lädt eine Textdatei mit allen Einstellungen **beider** Lesewege
herunter, *Sicherung zurückspielen* liest sie wieder ein.

Die Datei trägt das Zugriffstoken im Klartext. Das ist Absicht: ohne das Token
wäre sie nach dem Zurückspielen wertlos, weil der Miniserver mit der alten
Adresse nicht mehr durchkäme. Der Reiter sagt das auch, und die Datei warnt in
ihrem Kopf.

Zurückgespielt wird nur eine Datei, die sich **durchgängig** lesen lässt.
Findet sich auch nur eine unverständliche Zeile, bleibt alles, wie es ist — eine
halb eingelesene Konfiguration wäre schlimmer als gar keine, weil sie aussieht
wie eine ganze. Nachgemessen in vier Fällen
(`Pruefung-Smartmeter-classic-2.4.0/sicherung_pruefstand.php`, 22 von 22).

### MQTT-Gateway V1 und V2

Das Gateway ist seit LoxBerry 3 Bestandteil des Systems. Ab Fassung 2 erkennt
es die Themengruppe von selbst und schaltet die Abo-Knöpfe ab; bis Fassung 1
muss `<Praefix>/#` von Hand eingetragen werden.

Der Satz dazu steht jetzt an **einer** Stelle (`sm_abo_text()`) und richtet sich
nach der gemessenen Fassung. Lässt sie sich nicht feststellen, nennt die Seite
beide Fälle, statt einen zu behaupten. Nachgemessen an der **gerenderten** Seite
in allen drei Fällen (`Werkzeuge/gateway_wirkung.py`).

### Ein Feldkatalog statt dreier Listen

Die Feldnamen standen an drei Stellen: im Perl-Leser, in der Themen-Tabelle des
Reiters *MQTT* und in der Loxone-Vorlage. Keine der drei stimmte mit den
anderen überein — die Tabelle zeigte die OBIS-Kennzahl, während der Dienst unter
dem Feldnamen veröffentlichte.

Jetzt liest alles `bin/sm_felder.json`: 66 Felder und 7 OBIS-Kennzahlen. Erzeugt
wird die Datei von `Werkzeuge/sm_felder_erzeugen.py` aus dem Quelltext des
Lesers; ein Feld ohne Regel bricht den Lauf ab, statt still zu fehlen. Der
Selbsttest prüft nach, dass der Dienst den Katalog wirklich liest und keine
zweite Liste mehr führt.

### Ausfallerkennung

Schweigt der Zähler, behält ein virtueller Eingang in Loxone seinen letzten
Wert — auf dem Bildschirm sieht das aus wie ein ruhiger Tag. Dagegen gibt es
jetzt dreierlei:

- `Last_UpdateUnix` wird **nur** geschrieben, wenn wirklich ein Wert gelesen
  wurde. Ein Zeitstempel, den ein leerer Durchlauf mitschreibt, beweist nichts.
- `ZAEHLER` zählt jede erfolgreiche Ablesung und läuft bei 999 auf 0 zurück. Er
  ändert sich auch dann, wenn der Zählerstand stillsteht.
- `bin/healthcheck` beantwortet den Healthcheck des LoxBerry — auch dann, wenn
  niemand die Plugin-Seite öffnet.

Das Alter wird beim **Lesen** gerechnet, nicht beim Schreiben eingefroren.

### Formularschutz

Jedes Formular trägt ein Merkmal, das ein Wachposten **vor** allen Handlern
prüft. Er sitzt am Eingang und nicht in den einzelnen Handlern: einen Handler
kann man beim Erweitern vergessen, den Eingang nicht.

Abgeleitet wird das Merkmal aus einem eigenen Schlüssel `FORMKEY`, nicht aus
dem Aktionstoken — das ist hier freiwillig und darf leer sein, und
`hash_equals('', '')` ist wahr. Nachgemessen in drei Fällen, darunter der, ohne
den die anderen nichts wert wären: mit richtigem Merkmal **muss** gespeichert
werden (`Pruefung-Smartmeter-classic-2.4.0/csrf_pruefstand.php`).

### Der Abfragetakt „dauerhaft“ hieß nicht, was er tat

Der Eintrag versprach einen ständig mitlaufenden Leser. Die Kette dahinter ist
drei Glieder lang und endet nach **einem** Durchlauf: die Verknüpfung in
`cron.reboot` ruft `reboot_cron_runner.sh`, das ruft `fetch.php` genau einmal,
und `sm_logger.pl` endet ebenfalls nach einem Durchlauf. Der Takt liefert also
eine Ablesung je Neustart und danach keine mehr. Er heißt jetzt *nur beim
Systemstart*.

### Weitere Berichtigungen

- `bin/fetch.php` war als einzige PHP-Datei durchgehend CRLF. Der Kernel suchte
  damit einen Interpreter namens `php\r` — der Cron-Eintrag des klassischen
  Lesers konnte nie anlaufen. Aufgefallen war es nicht, weil *Jetzt einmal
  abfragen* `php` ausdrücklich davorsetzt.
- Der Rückspielweg in `postinstall.sh` war tot: er erkannte die verlorene
  Konfiguration an einer Prüfsumme, die zwei `sed`-Zeilen darüber längst
  verändert hatten. Er steht jetzt **vor** dem `sed` und entscheidet nach
  Inhalt.
- `preupgrade.sh` behauptete, LoxBerry lösche den Konfigordner beim Upgrade
  nicht. `purge_installation` läuft im Upgrade-Zweig; es überlebt dort nichts.
- Beim Deinstallieren blieben drei Zweitschriften neben dem Konfigordner
  liegen — mitsamt Token. Sie werden jetzt überschrieben, entfernt und
  **nachgezählt**.
- `CUSTOM_LOGLEVELS` stand auf `true`, ohne dass irgendwo ein Loglevel gelesen
  wurde. Wer im Verwaltungsfenster etwas einstellte, stellte nichts ein.
- `TOKEN` und `FORMKEY` fehlten in der mitgelieferten `smartmeter.cfg`. Die
  Oberfläche trug sie beim ersten Aufruf nach und meldete dem Anwender einen
  Mangel, den die Auslieferung selbst verursacht hatte.

### Sprachdateien aus einer Quelle

Alle vier Sprachdateien — `language_de.ini`, `language_en.ini`, `help_de.ini`,
`help_en.ini` — erzeugt jetzt `Werkzeuge/sm_sprache_erzeugen.py` aus **einer**
Tabelle: 457 Schlüssel und 68 Hilfetexte, jedes Paar an einer Stelle. Wer eine
Sprachdatei von Hand ändert, zieht den Erzeuger mit; die Köpfe der erzeugten
Dateien sagen das auch dort.

## Fassung 2.4.2 — zwei Wahrheiten unter einem Namen

`sm_log()` war **zweimal definiert**, beide Male ohne `function_exists`-Wache:
in `bin/fetch.php` und in `webfrontend/htmlauth/sm_lib.php`. Gefunden hat es
`Werkzeuge/php_bilanz.py`.

Der Reflex wäre die Wache nach dem Hausmuster gewesen — so hält es Raumklima
mit `rk_e`, so hält es Kodi NG mit `ko_e`. Hier wäre sie **falsch** gewesen,
denn die beiden Rümpfe waren nicht gleich. Sie schrieben in **verschiedene
Dateien**:

| | `bin/fetch.php` | `webfrontend/htmlauth/sm_lib.php` |
|---|---|---|
| Ziel | `/dev/shm/<ordner>/fetch.log` | `<home>/log/plugins/<ordner>/smartmeter.log` |
| Lebensdauer | wird bei jedem Lauf geleert | dauerhaft |
| Verzeichnis anlegen | nein | ja |
| Bildschirmausgabe | ja bei `--verbose` | nein |

Auf dem installierten LoxBerry liegen `bin/` und `webfrontend/htmlauth/` in
getrennten Bäumen; die beiden trafen sich also nie, und es gab kein
`Cannot redeclare`. Im entpackten Archiv liegen sie zusammen — und dort sind
es zwei Wahrheiten unter einem Namen. Eine Wache hätte nicht die Wahrheit
hergestellt, sondern die **Ladereihenfolge** darüber entscheiden lassen, in
welche Datei protokolliert wird. Das ist genau die Bauart, aus der stille
Widersprüche entstehen.

Zwei Aufgaben, zwei Namen: die Funktion in `bin/fetch.php` heißt jetzt
**`sm_fetch_log()`** (15 Aufrufstellen mitgezogen), `sm_log()` bleibt der
Oberfläche.

### Die Kappung stand zweimal da

In beiden Rümpfen stand die Kappung — ab 500 kB bleiben die letzten 200
Zeilen — **wortgleich ausgeschrieben**. Beide Ziele liegen auf einer Ramdisk,
beide brauchen sie, und eine Regel mit zwei Verbrauchern steht in EINER
Funktion. Sie heißt jetzt `smg_log_kappen()` und liegt in `bin/sm_gemein.php`
— der Datei, die schon heute aus allen drei Bäumen erreichbar ist.

`sm_log()` ruft sie über `function_exists()` auf, denselben Rückfall nimmt
`sm_zaehler_lesen()`: fällt der gemeinsame Vorlauf aus, wird nur nicht
gekappt — die Zeile selbst geht trotzdem hinaus, und die Selbstprüfung im
Reiter Test meldet den Ausfall. `bin/fetch.php` braucht den Rückfall nicht:
es bricht schon vorher ab, wenn `sm_gemein.php` fehlt.

Gemessen unter PHP 7.4.33 und PHP 8.4.24, vier Fälle je Fassung: unter der
Grenze wird nicht gekappt; über der Grenze bleiben genau die **letzten** 200
Zeilen stehen (aus 12 000 Zeilen / 732 000 Byte wurden 11 801 bis 12 000);
eine fehlende Datei wird nicht angelegt; eigene Werte für Grenze und
Zeilenzahl wirken.

### Der Stat-Zwischenspeicher

Beim Nachmessen fiel ein zweiter, älterer Fehler auf. PHP merkt sich die
Antworten von `stat()`. Innerhalb **eines** Prozesses sah `filesize()`
deshalb die erste Größe und danach nie wieder eine neue — die Kappung fiel
still aus:

| 20 000 Zeilen im selben Prozess | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Der Rumpf bis 2.4.1 verhielt sich in derselben Messung genauso — es ist
**kein Rückschritt**, sondern ein Fehler, der schon immer dastand. Folgen
hatte er bisher keine: beide Aufrufer sind kurzlebig, und ein **frischer**
Prozess kappt richtig (unter 7.4 gegengeprüft). Eine Funktion darf aber
nicht davon abhängen, wer sie wie oft ruft. `clearstatcache(true, $datei)`
steht jetzt als erste Zeile in `smg_log_kappen()` — der zweite Parameter
beschränkt das Leeren auf diese eine Datei.

Dass das eine Zeile an einer Stelle ist und nicht zwei an zweien, ist der
eigentliche Gewinn der Zusammenlegung oben.

## Fassung 2.4.3 — was der Vergleich nicht verglich

Eine Durchsicht Zeile für Zeile, gemessen unter PHP 7.4.33 **und** 8.4.24.
Nichts kommt hinzu: keine neuen Themen, keine neuen
Konfigurationsschlüssel, keine geänderte Loxone-Vorlage.

### Der Abfragetakt stellte sich bei jedem Speichern auf „nur beim Systemstart"

Der schwerste Befund. `sm_takte()` liefert ein Feld mit den Schlüsseln
`'M', '1', '3', '5', …`. PHP wandelt einen Feldschlüssel, der wie eine
Ganzzahl aussieht, beim Anlegen selbst in eine Ganzzahl um — aus `'5' =>`
wird `5 =>`. Der Wert aus der Konfiguration ist dagegen eine Zeichenkette,
und `'5' === 5` ist falsch. Der Vergleich im Auswahlfeld traf damit **an
keinem einzigen Eintrag** zu.

Was daraus folgte, ist am nachgebauten LoxBerry gemessen, in beiden
PHP-Fassungen:

| | gemessen |
|---|---|
| `CRON=30` in der Konfiguration | im `<select>` steht **kein** `selected` |
| der Browser sendet deshalb | `lg_cron=M` (den ersten Eintrag) |
| nach einem unveränderten Absenden | `CRON=M` |

Wer im Reiter *Legacy* nur einen Lesekopfnamen änderte oder ein Häkchen
setzte, verlor den Takt — und bekam danach einen Zählerstand je Neustart
des LoxBerry statt alle fünf Minuten. In Loxone behält der virtuelle
Eingang seinen letzten Wert; es sah aus wie ein ruhiger Tag. Genau davor
warnt der Kommentar über `sm_takte()` selbst.

Dieselbe Klasse steckte im Auswahlfeld für das Zählerprofil (Schlüssel
`'0'`). Dort fiel sie nicht auf, weil `0` zufällig der erste Eintrag ist.
Beide Stellen vergleichen jetzt `(string)` gegen `(string)`.

### Der SML-Zerleger starb unter PHP 8 mitten im Telegramm

`bin/php_sml_parser.class.php` fragte das Fortsetzungsbit eines
TL-Feldes mit `$TYPE[0] & 0x8` ab — einem Vergleich auf dem **Zeichen**.
`'8'` und `'9'` sind numerische Zeichenketten und gingen durch, `'A'` bis
`'F'` nicht:

| | hohes Nibble A–F |
|---|---|
| PHP 7.4.33 | `Warning: A non-numeric value encountered`, Ergebnis 0 — die Schleife lief **gar nicht**, die Länge blieb falsch |
| PHP 8.4.24 | `TypeError: Unsupported operand types: string & int` — Abbruch |

Betroffen ist genau die lange Liste, für die das Bit da ist. Elf Zeilen
tiefer steht dieselbe Prüfung in derselben Datei richtig
(`hexdec($TYPE_LEN[0]) & 0x8`), mit dem Kommentar „manche DTZ41 vom
Bayernwerk schicken 20 OBIS-Kennzahlen". Zwei Schreibweisen einer Prüfung,
eine davon falsch.

Dazu aus derselben Datei:

* **Skalierungsfaktor 0.** `if($result['scaler'])` ließ eine 0 stehen,
  statt sie zu 10⁰ = 1 zu machen. `bin/sml_parser.php` multipliziert im
  Wh-Zweig damit — der Zählerstand wurde 0 und ging ungeprüft nach Loxone.
  Der W-Zweig derselben Datei fängt `scaler == 0` ausdrücklich ab.
* **Der CRC-Vergleich** stand auf `==`. `"0E12" == "0E34"` ist wahr, in
  beiden PHP-Fassungen. Der Befund wird heute nirgends ausgewertet, der
  Vergleich war also latent — jetzt steht `===` da.
* `readSmlTime()` gab im `default`-Zweig eine nie gesetzte Variable zurück.
* `parse_sml_string()` und `parse_sml_file()` warfen bei jedem Aufruf einen
  `ArgumentCountError`; sie reichen `$crc` jetzt durch.

### Baustein #4 der Loxone-Anleitung konnte nie einen Wert bekommen

Die Bausteinliste im Reiter *Einbindung in Loxone* nannte als Quelle für
`Strom_Zaehlwerk` ein MQTT-Thema `ZAEHLER`. Ein solches Thema gibt es
nicht: `ZAEHLER` steht ausschließlich in der Schlusszeile des Endpunkts.
Damit blieb #4 ohne Wert — und mit ihm die ganze daran hängende Kette #8
bis #12, also genau die Ausfallerkennung, für die der Schritt da ist.
Zeile 4 verweist jetzt auf den Endpunkt aus Schritt 8.

Im selben Zug: die Feldnamen der Zeilen 1 bis 3 standen als Literale da,
während die Tabelle darüber sie aus `bin/sm_felder.json` erzeugt — bei
einem anderen Kanalsatz wichen zwei Tabellen auf derselben Seite
voneinander ab. Sie kommen jetzt aus derselben Quelle, und ein Kanal, der
gar nicht eingestellt ist, wird als solcher gekennzeichnet. Die
Namensvorschläge der Bausteine stehen jetzt vollständig in den
Sprachdateien; bis 2.4.2 war einer von dreizehn übersetzt.

### Zurückspielen einer Sicherung

* Ein Lesekopf, dessen Gerät hier fehlt, wurde **unsichtbar**: der Pfad
  wurde entfernt, und `sm_koepfe()` überspringt jeden Abschnitt ohne
  `DEVICE`. Auf einer frischen Anlage — also genau dem Zweck der Datei —
  war der Kopf danach nicht zu sehen und nicht zu bearbeiten, während die
  Meldung sagte, die Einstellung sei übernommen. Der Pfad bleibt jetzt
  stehen; dass das Gerät fehlt, sagt `ANGESTECKT`.
* Die Datei wurde **schwächer geprüft als das Formular**, obwohl der
  Kommentar „dieselben Grenzen" versprach: Zählernummer, Kanäle und die
  Schalter gingen ungeprüft durch, und die Regel „nur ein Leser" fehlte im
  dritten Handler. Alles nachgezogen.
* Ein gescheiterter Cron-Eintrag landete in der **grünen** Kachel.
* `vzlogger.conf` wurde nicht nachgezogen, wenn eine fremde Schreibung
  scheiterte — `vzlogger.json` und `vzlogger.conf` liefen still
  auseinander.

### Selbstprüfungen, die beruhigten

* Der gepufferte Endpunkt-Befund (`endpunkt.json`) wurde **nie** verworfen.
  Nach *Token entfernen* — der Endpunkt steht damit jedem Gerät im Netz
  offen — zeigte die Zeile bis zu 300 s weiter den alten Haken.
* Die Zeile „offener Reiter" prüfte die Leiste nur auf „mindestens einer"
  und meldete dann eine Zahl, die sie gar nicht erhoben hatte. Nachgestellt:
  fünf von sechs Reitern zerstört, die Zeile trug trotzdem einen Haken.
* Die Zeile „INI-Muster" beschrieb eine Anführungszeichen-Prüfung, die
  nicht stattfindet, und zählte Dateien statt Zeilen.
* Die UTF-8-Prüfung der Loxone-Vorlage stand hinter `simplexml_load_string()`
  und konnte deshalb nie greifen.
* Das Zugriffstoken stand im Klartext in der Prüfzeile **und** in
  `endpunkt.json`, zwei Blöcke über der Stelle, die es bewusst maskiert.

### Der klassische Leser (`bin/sm_logger.pl`)

* `CALCULATE_POWER`: der Wächter „kein Messwert vorhanden" stand **hinter**
  `sprintf("%.3f", …)` — und `"0.000"` ist in Perl wahr. Die Warnung ist nie
  erschienen. Dazu eine mögliche **Division durch Null**, wenn zwei Läufe in
  dieselbe Sekunde fallen; das ist in Perl ein tödlicher Laufzeitfehler.
* Bezug und Lieferung teilten sich undeklarierte Paketvariablen. Ist
  `<serial>.lastdel` leer — und das Programm legt sie selbst leer an, ein
  Haushalt ohne Einspeisung füllt sie nie —, rechnete der Lieferungs-Zweig
  bei **jedem** Lauf mit dem Zählerstand und dem Zeitstempel des Bezugs. Die
  Variablen sind jetzt lexikalisch.
* Der Zähler der gemessenen Werte stieg auch dann, wenn `print` scheiterte.
  Genau dieser Zähler entscheidet, ob `Last_UpdateUnix` geschrieben wird —
  der Zeitstempel, aus dem Endpunkt und Healthcheck das Alter ableiten.
* `close()` wurde vor dem `rename()` nicht beurteilt; auf einer Ramdisk
  meldet erst `close` einen Schreibfehler.
* Der Rückgabewert von `sml_parser.php` wurde nie angesehen, der
  Zeitgrenzabbruch (`$@`) nie ausgewertet, und der Geräte-Pfad wurde
  unverankert geprüft.

### Weiteres

* `webfrontend/html/index.php`: der zweite Bibliothekspfad lag eine Ebene
  zu tief (`dirname` dreimal statt viermal) und traf nie.
* `sm_e()` lieferte bei einem einzigen ungültigen Byte einen **Leerstring**
  statt des Textes — getroffen hat das den Mitschnitt, also ausgerechnet
  das Werkzeug für die Fehlersuche. Jetzt mit `ENT_SUBSTITUTE`.
* `sm_log_ende()` zeigte als älteste Zeile ein Bruchstück.
* `uninstall`: `rm -rf "/dev/shm/$3"` ohne Prüfung auf ein leeres `$3`.
* `bin/vzlogger_pkg.sh`: ein Abbruch zwischen `block_` und
  `unblock_service_start` konnte `policy-rc.d` dauerhaft zerstören —
  danach startete auf dem Rechner kein Paket mehr seine Dienste. Jetzt mit
  `trap`.
* `preupgrade.sh`/`postupgrade.sh`: eine gescheiterte Sicherung wurde als
  geglückte Rückspielung gemeldet; eine gescheiterte Rückspielung war
  stumm.
* `dpkg/apt` verlangte `libstring-escape-perl` — `String::Escape` kommt in
  keiner Datei des Plugins vor.
* `daemon/daemon` und `postroot.sh` schreiben dieselbe udev-Regel mit zwei
  verschiedenen Kopfzeilen.

### Was ausdrücklich NICHT gemessen ist

Kein Zähler, kein Lesekopf, kein laufendes `vzlogger`, kein Broker, kein
Miniserver. Damit ungeprüft: das Verhalten von `Device::SerialPort`, echte
D0- und SML-Telegramme (und damit die tatsächliche Reichweite der
Zerleger-Befunde), die 41 Zählerprofile, der Suchlauf über die vier
allgemeinen Lesewege und das MQTT-Gateway in Fassung 2. Die Berichtigungen
am Zerleger sind an den Ausdrücken gemessen, nicht an einem Telegramm.

## Fassung 2.5.0 — die Einheit wird gemessen, nicht angenommen

Diese Fassung enthält alles aus 2.4.3 (siehe oben) und dazu drei
Änderungen, die eine neue Nebennummer verlangen: ein Feldname ändert sich,
und die Loxone-Vorlage sieht anders aus.

### Der Schaltzustand trug die falsche OBIS-Kennzahl im Namen

`Breaker_State_Electricity_96.1.4` wird aus **96.3.10** gelesen — die
Kennzahl im Namen stimmte nicht. Schlimmer: 96.1.4 gehört im selben
Katalog schon `Version_Information_96.1.4`. Zwei verschiedene Größen unter
einer Kennzahl, und in einer Feldliste sieht das aus wie ein Tippfehler,
den man „gleich mal aufräumt".

| | bis 2.4.3 | ab 2.5.0 |
|---|---|---|
| Feldname | `Breaker_State_Electricity_96.1.4` | `Breaker_State_Electricity_96.3.10` |
| gelesen aus | 96.3.10 | 96.3.10 |

**Das ist ein Bruch.** Der Feldname ist zugleich der Name des virtuellen
Eingangs in Loxone. Wer diesen Wert verwendet, benennt den Eingang einmal
um oder lädt die Vorlage neu — alle übrigen Eingänge bleiben unberührt.
Betroffen sind nur Zähler, die 96.3.10 überhaupt senden. Der Hinweis steht
mit beiden Namen oben im Reiter *Einbindung in Loxone*, nicht in einer
Fußnote.

### Die vzLogger-Vorlage trug die Einheiten des klassischen Lesers

`bin/sm_felder.json` führt je Feld zwei Einheiten und sagt im eigenen Kopf,
warum:

* `einheit` — „gilt für den **klassischen** Weg; `bin/sml_parser.php`
  rechnet Wh auf kWh und W auf kW um"
* `einheit_vz` — „**LEER** und das mit Absicht: vzlogger rechnet nicht um,
  es reicht den Wert des Zählers durch."

Die Vorlage nahm bis 2.4.3 für **beide** Wege `einheit`. Auf dem
vzLogger-Weg stand damit `<v.3> kWh` an einem Eingang, der möglicherweise
rohe Wh bekommt — und `MaxVal="1000000"` kappte einen Zählerstand in Wh
schon nach 1000 kWh. Ein Eingang, der bei einem Messwert stehenbleibt,
sieht in der Visualisierung aus wie ein ruhiger Zähler.

Ab 2.5.0 entscheidet **eine** Funktion, `sm_einheit_fuer($feld, $weg)`, und
aus ihr schöpfen alle drei Stellen: die Themen-Tabelle im Reiter *MQTT*,
die Tabelle in Schritt 3 und die erzeugte Vorlage. Solange `einheit_vz`
leer ist, heißt das auf dem vzLogger-Weg:

* **keine Einheit** am virtuellen Eingang — lieber eine nackte Zahl als
  eine falsche Beschriftung, die niemand nachprüft,
* **weite Grenzen** (±10⁹) statt der Grenzen, die für die umgerechnete
  Einheit gelten.

Der klassische Weg bleibt unverändert: dort wird umgerechnet, dort sind die
Einheiten belegt.

### Und der Knopf, mit dem sich die Einheit belegen lässt

Ohne Zähler war nicht zu entscheiden, welche Einheit vzlogger durchreicht —
also stand sie nicht da. Damit das nicht so bleibt, gibt es jetzt

    bin/fetch_vzlogger.pl --roh

und dafür den Knopf **vzLogger-Rohwerte** im Reiter *Test*. Er ruft
denselben Code, der die Werte später veröffentlicht, und zeigt je Kanal
UUID, OBIS-Kennzahl, Feldname und den **rohen** Wert. Er **liest nur**:
keine Datendatei, kein MQTT, kein UDP, und der Umlaufzähler bleibt stehen —
ein Messstück, das den Messgegenstand verändert, misst sich selbst.

Die letzte Spalte ist ein **Hinweis aus dem Zahlenwert**, keine Messung: ein
Zählerstand um 12 345 ist eher kWh, einer um 12 345 678 eher Wh. Maßgeblich
ist das Datenblatt des Zählers. Wer die Einheit belegt hat, trägt sie als
`einheit_vz` in `Werkzeuge/sm_felder_erzeugen.py` ein und lässt den Erzeuger
laufen — dann steht sie in der Vorlage, und mit ihr wieder die passenden
Grenzen.

Nachgemessen ist der Zweig gegen eine **Attrappe** der
vzlogger-Schnittstelle (`Pruefung-Smartmeter-classic-2.4.0/roh_pruefstand.php`,
17 von 17, beide PHP-Fassungen): die Zuordnung der Kanäle, der jüngste Wert
statt des ersten, ein Kanal ohne Werte, ein fremder Kanal, die Gegenprobe
ohne Antwort — und vor allem, dass nichts geschrieben wird.

### Was ausdrücklich NICHT gemessen ist

Weiterhin kein Zähler, kein Lesekopf, kein laufendes vzlogger, kein Broker,
kein Miniserver. Die Attrappe der vzlogger-Schnittstelle ist aus
`bin/fetch_vzlogger.pl` abgelesen, nicht aus vzlogger. Und `einheit_vz` ist
nach wie vor leer — das ist der Zustand, den diese Fassung messbar macht,
nicht der, den sie behebt.

## Fassung 2.6.0 — der Verbrauch je Stunde

Bis 2.5.0 hält das Plugin nur den **letzten** Zählerstand, und der liegt auf
der Ramdisk. Nach jedem Neustart des LoxBerry ist er weg. Damit lässt sich
nicht beantworten, was in der letzten Stunde verbraucht wurde — und genau
das braucht ein dynamischer Tarif, weil dort jede Stunde einen eigenen Preis
hat.

### Was NICHT gebaut wurde, und warum

Ein eigener Abruf der Börsenpreise. In diesem Konto liegen bereits drei
Spotpreis-Linien (aWATTar, Octopus, Tibber), und die machen die Preisseite
gründlich: Netzentgelt, Stromsteuer, Konzessionsabgabe, Umlagen,
Anbieter-Aufschlag und Umsatzsteuer sind dort einzeln einstellbar, dazu der
zweite Preissatz nach § 14a EnWG. Der Unterschied ist nicht klein — 8,00 ct
Börsenpreis werden dort zu **26,01 ct** Endpreis. Wer nur „EPEX mal kWh"
rechnet, bekommt eine Zahl, die um Faktor 3 danebenliegt und trotzdem
plausibel aussieht.

Ein vierter Abruf wäre die vierte Preisquelle und die zweite Stelle für
Netzentgelte und Steuersätze gewesen. Statt dessen gilt: **das
Spotpreis-Plugin liefert den Preis, dieses Plugin die Menge.** Jede Sache
einmal.

### Der Lastgang

Die Spotpreis-Plugins gewichten ihren Tarifvergleich mit einem eingebauten
**Haushaltsprofil**, weil ihnen niemand sagt, wann wirklich verbraucht wurde.
Ihr `spot_lastgang()` holt dafür stündliche Werte von einer frei
einstellbaren JSON-Adresse — geliefert hat sie bisher niemand. Jetzt gibt es
sie:

    /plugins/<Ordner>/lastgang.php?token=…

    {"ok":1,"einheit":"wh","stunden":{"1756000000":812.5, …},
     "anzahl":48,"heute":24,"offen":0}

Im Spotpreis-Plugin unter *Eigener Lastgang* einzutragen: Quelle `objekt`,
Pfad `stunden`, Einheit `wh`. Der Reiter *Einbindung in Loxone* zeigt die
Adresse samt Token und sagt, wie viele der 24 Stunden des heutigen Tages
gedeckt sind — das Spotpreis-Plugin verlangt mindestens 20.

### Wie die Historie entsteht

Es gibt zwei Leser: `bin/fetch.php` (klassisch, PHP) und
`bin/fetch_vzlogger.pl` (vzLogger, Perl). Die Fortschreibung in beide
einzubauen hieße, dieselbe Rechnung in zwei Sprachen zu pflegen — genau der
Fehler, den 2.4.0 mit `bin/sm_felder.json` abgestellt hat. Beide schreiben
aber dieselbe Datei im selben Schema, und die ist damit die vorhandene
gemeinsame Schnittstelle:

    /dev/shm/<Ordner>/<serial>.data      Zeilen "SERIAL:Feldname:Wert"

`bin/sm_historie.php` liest sie im Minutentakt, bildet Differenzen und
schreibt je Stunde eine Zeile. Es redet mit **keinem Gerät**; läuft kein
Leser, wächst die Historie nicht — und das ist die richtige Antwort, nicht
eine gerechnete Fortsetzung.

### Zwei Dateien, und warum

Der erste Entwurf hielt alles in der CSV und schrieb sie bei jedem
Minutenlauf neu. Bei 24 Monaten sind das rund 17 500 Zeilen, **1440 mal am
Tag**, auf einer SD-Karte. Jetzt sammelt ein kleiner Merker die laufende
Stunde, und erst wenn sie vorbei ist, wird **eine** Zeile angehängt: 24
Schreibvorgänge am Tag statt 1440 vollständiger Neuschreibungen. Der
Rückschnitt auf 24 Monate schreibt die Datei doch einmal ganz — einmal
täglich um drei Uhr nachts.

Die Historie überlebt ein Update: `data/plugins/<Ordner>/` wird bei **jedem**
Upgrade abgeräumt (`purge_installation` in `plugininstall.pl`, und zwar bevor
`postupgrade.sh` läuft). Gesichert wird deshalb **neben** den Datenordner,
wie es das Spotpreis-Plugin für seine `history.csv` vormacht.

### Die Fallen, jede einzeln nachgemessen

| Fall | Was geschieht |
|---|---|
| Zähler springt zurück (Zählerwechsel) | Differenz **verworfen**, nicht gedreht — gedreht sähe sie aus wie 1001 kWh in einer Stunde |
| Uhr springt zurück | keine Differenz. Ein Raspberry Pi hat keine Echtzeituhr; nach dem Booten steht er in der Vergangenheit |
| Lücke über zwei Stunden | bleibt eine **Lücke**. Die Energie auf die Stunden dazwischen zu verteilen wäre gleichmäßiger Verbrauch — genau die Annahme, die dieses Modul abschaffen soll |
| Neustart | Ramdisk leer, Historie und Merker stehen weiter |
| Leseweg gewechselt | die erste Differenz danach wird verworfen (andere Einheit, evtl. anderer Zählpunkt) |
| Zwei Lesekköpfe | je Zählernummer eine Zeile; der Endpunkt **addiert** sie |

### Die Einheit — und was sie noch offen lässt

Auf dem **klassischen** Weg rechnet `bin/sml_parser.php` Wh auf kWh um; die
Einheit ist belegt, und die Historie schreibt Wh.

Auf dem **vzLogger**-Weg reicht vzlogger den Wert des Zählers durch.
`einheit_vz` im Katalog ist leer, weil es ohne Zähler nicht zu messen war —
deshalb steht seit 2.5.0 auch in der Loxone-Vorlage keine Einheit. Die
Historie schreibt die Differenz trotzdem mit, aber sie schreibt die Einheit
**daneben**: eine Zeile mit `?` heißt gemessen ja, umgerechnet nein. Der
Lastgang-Endpunkt liefert solche Stunden **nicht** aus und zählt sie in
`offen`.

Eine Zahl mit geratener Einheit wäre in einer Kostenrechnung um den Faktor
1000 daneben, und niemand sähe es. Der Knopf *vzLogger-Rohwerte* im Reiter
*Test* (seit 2.5.0) zeigt, was wirklich ankommt.

### Was ausdrücklich NICHT gemessen ist

* **Kein Zähler, kein vzlogger.** Die Zählerstände des Prüfstands sind
  gelegt; ob ein echter Zähler sie so liefert, ist offen.
* **Der Rückschnitt nach 24 Monaten.** Er bräuchte Zeilen, die zwei Jahre
  alt sind, und eine gestellte Uhr — nicht eine gelegte Datei.
* **Kein cron und kein Apache.** Die Cron-Zeile ist auf fünf Zeitfelder und
  den Benutzer geprüft, nicht ausgeführt.
* **Das Zusammenspiel mit dem Spotpreis-Plugin.** Das Format ist an
  `plan_pv_lesen()` aus aWATTar 1.2.20 abgelesen und der Endpunkt liefert
  es; ob dessen Tarifvergleich damit rechnet, zeigt erst die Anlage.
* **Die Kostenrechnung selbst gibt es noch nicht.** Sie folgt, sobald
  `einheit_vz` belegt ist — ohne Einheit ist eine Kostenzahl keine.

## Fassung 2.7.0 — tut die Hardware, was der Fahrplan sagt?

Der Planer der Spotpreis-Plugins schaltet Regeln (Wallbox, Speicher,
Wärmepumpe) in die günstigen Stunden. Ob das Gerät dann wirklich zieht,
weiß er nicht: er sendet einen Befehl und sieht keinen Zähler. Dieses
Plugin sieht den Zähler.

### Warum es kein „Delta“ ist

Die naheliegende Rechnung wäre „geplanter minus realer Netzbezug“. Sie geht
nicht — **einen geplanten Netzbezug gibt es nicht.** Gemessen am Planer von
Spotpreis-aWATTar 1.2.20: er sendet je Regel `aktiv`, `in`, `rest`, `ct`,
`ein`, `rang`, `fehlt`, `spart` und trägt eine konfigurierte `leistung` in
kW. Das einzige Vorkommen des Wortes „Netzbezug“ im ganzen Plugin ist der
vom Anwender **eingetragene** Jahresverbrauch für den Tarifvergleich.

Der Planer plant also Schaltfenster einzelner Regeln; gemessen wird der
Bezug des **ganzen Hauses**. Die Differenz wäre Grundlast plus Herd plus
alles andere minus PV — der Rest des Hauses, nicht die Regelabweichung.

### Was statt dessen gerechnet wird: eine untere Schranke

Läuft eine Regel mit 11 kW 47 Minuten, muss sie 8,6 kWh gezogen haben. Der
Netzbezug in dieser Zeit kann nur **größer** sein — der Rest des Hauses
kommt dazu, zieht nie ab. Sind es 2,1 kWh, hat die Regel **nachweislich**
nicht gezogen.

Die Richtung ist entscheidend: „zu wenig Bezug“ ist ein Befund, „zu viel“
ist keiner. Wer daraus eine Abweichung in beide Richtungen macht, misst den
Haushalt und nennt es Regelabweichung.

**Die eine Ausnahme wird genannt.** Eigene Einspeisung drückt den Netzbezug,
ohne dass die Regel weniger zieht. Lief in der Zeit eine Einspeisung, ist die
Schranke keine mehr — die Aussage heißt dann `unsicher` und ist kein Befund.
Ein Alarm, der bei jeder Wolke anschlägt, wird abgeschaltet.

### Woher der Fahrplan kommt

Über HTTP von `spot.php?json=1` des örtlichen Spotpreis-Plugins — derselbe
Weg wie für den Preis. **Kein MQTT-Abo:** das bräuchte einen Dauerprozess,
eine neue Abhängigkeit (paho oder Net::MQTT) und einen Neustart-Wächter. Im
ganzen Bestand empfängt nur Midea2Lox, und dessen erste Fassung schaltete
dem Anwender ein Gerät aus, das er gerade eingeschaltet hatte. Solange der
Zähler ohnehin nur minutenweise gelesen wird, brächte ein Abo nichts.

Ab Werk ist der Abgleich **aus** — er fragt eine fremde Adresse ab, und was
nach außen greift, wird nicht ungefragt eingeschaltet. Der Cron läuft alle
fünf Minuten, nicht jede: der Fahrplan ändert sich stündlich, und ein
Minutentakt belästigte den Nachbarn sechzigmal so oft ohne Gewinn.

### Was es NICHT ist

**Kein schneller Weg.** Wer sekundenschnell nachregeln will, tut das im
Miniserver: dort liegen der Netzbezug (aus diesem Plugin) und
`regel/<n>/aktiv` (aus dem Planer) ohnehin beide an, und ein Baustein
rechnet sie in einem Zyklus. Der Weg über dieses Skript ist zwei
MQTT-Sprünge und einen Cron-Lauf **langsamer**. Was es dafür kann und der
Miniserver nicht: über eine Laufzeit hinweg Energie summieren.

### Die Themen

    <praefix>/abgleich/quelle_ok        1 = Fahrplan erreichbar
    <praefix>/abgleich/<n>/aktiv        die Regel soll laufen
    <praefix>/abgleich/<n>/soll         kWh, Leistung mal Laufzeit
    <praefix>/abgleich/<n>/ist          kWh, gemessener Netzbezug
    <praefix>/abgleich/<n>/fehlt        kWh Fehlbetrag
    <praefix>/abgleich/<n>/dauer        Sekunden
    <praefix>/abgleich/<n>/sicher       1 = keine Einspeisung, Einheit belegt
    <praefix>/abgleich/<n>/ok           1 zieht · 0 zieht nicht · -1 kein Urteil

`ok` ist bewusst dreiwertig. Ein Miniserver kann Wörter nicht vergleichen,
und „unbekannt“ als 0 zu senden wäre eine Aussage, wo keine steht.

### Was ausdrücklich NICHT gemessen ist

* **Kein Spotpreis-Plugin.** Der Fahrplan kommt im Prüfstand aus einer
  festen Datei; die Form ist an `spot_lib.php` und `planer.php` abgelesen
  und nicht im Zusammenspiel erprobt.
* **Kein Zähler, keine Wallbox.** Die Zählerstände werden gelegt. Ob die
  Toleranz von 80 % und die Mindestlaufzeit von zehn Minuten an einer
  echten Anlage taugen, zeigt erst der Betrieb.
* **Kein MQTT-Gateway.** Im Prüfstand ist das Senden abgeschaltet; gemessen
  wird die Rechnung, nicht der Sendeweg.
* **Auf dem vzLogger-Weg ruht der Abgleich**, solange `einheit_vz` nicht
  belegt ist — ohne Einheit gibt es keine kWh, und eine geratene wäre um
  den Faktor 1000 daneben.

## Lizenz

Apache License 2.0 — wie das Original. Siehe [`LICENSE`](LICENSE) und
[`NOTICE`](NOTICE).

Dank an **Michael Schlenstedt** für das ursprüngliche Plugin und für
Smartmeter-NG, aus dem das Installationsverfahren für vzlogger übernommen ist,
sowie an das **Volkszähler-Projekt** für vzlogger.
