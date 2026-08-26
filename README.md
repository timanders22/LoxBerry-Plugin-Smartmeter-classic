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

Nicht übersetzt sind die 41 Zählerprofile: das sind Gerätebezeichnungen
(*Iskra MT681*, *Landis & Gyr E220*) und in jeder Sprache dieselben. Von den
45 Einträgen der Liste sind es die vier allgemeinen, die übersetzt werden.

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

Jetzt liest alles `bin/sm_felder.json`: 65 Felder und 7 OBIS-Kennzahlen. Erzeugt
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
Tabelle: 440 Schlüssel und 68 Hilfetexte, jedes Paar an einer Stelle. Wer eine
Sprachdatei von Hand ändert, zieht den Erzeuger mit; die Köpfe der erzeugten
Dateien sagen das auch dort.

## Lizenz

Apache License 2.0 — wie das Original. Siehe [`LICENSE`](LICENSE) und
[`NOTICE`](NOTICE).

Dank an **Michael Schlenstedt** für das ursprüngliche Plugin und für
Smartmeter-NG, aus dem das Installationsverfahren für vzlogger übernommen ist,
sowie an das **Volkszähler-Projekt** für vzlogger.
