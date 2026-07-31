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

Im MQTT-Gateway des LoxBerry muss das Abo `<Praefix>/#` eingetragen sein —
sonst kommt am Miniserver nichts an. Der Reiter *MQTT* zeigt das benötigte Abo,
der Reiter *Einbindung in Loxone* die vollständigen Themen und die Namen der
virtuellen Eingänge.

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

- LoxBerry ab 1.4.3
- optischer IR-Lesekopf am USB — die udev-Regel legt das Plugin an
- für die vzLogger-Betriebsart ein installierbares vzlogger-Paket

## Lizenz

Apache License 2.0 — wie das Original. Siehe [`LICENSE`](LICENSE) und
[`NOTICE`](NOTICE).

Dank an **Michael Schlenstedt** für das ursprüngliche Plugin und für
Smartmeter-NG, aus dem das Installationsverfahren für vzlogger übernommen ist,
sowie an das **Volkszähler-Projekt** für vzlogger.
