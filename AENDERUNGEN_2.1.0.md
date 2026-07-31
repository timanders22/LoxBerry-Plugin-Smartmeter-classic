# Smartmeter Classic 2.1.0 — die vzLogger-Betriebsart sendet jetzt brauchbare Werte

Stand 31.07.2026. Grundlage: 2.0.0.

**Gefunden beim Umstellen der Loxone-Projektdatei auf MQTT — nicht durch einen
Testlauf, sondern weil die Themen nicht zu den Zählerwerten passen wollten.**

## Der Befund

In der Betriebsart *vzLogger* kam am Miniserver **überhaupt nichts** an. Weder
über UDP noch über MQTT. Der Zähler wurde einwandfrei gelesen, das Protokoll
zeigte `Adding reading to queue` — und trotzdem blieb im Miniserver alles stehen.

Drei Fehler, alle in `bin/fetch_vzlogger.pl`, alle aus meiner eigenen Feder:

### 1. Der MQTT-Zerleger passte nicht zum eigenen Datenformat

Die Werte wurden als `1-0:1.8.0=10856202.90` gebildet — Trennzeichen
**Gleichheitszeichen**. Zerlegt wurden sie mit

    /^([^:]+):(.*)$/

also am **Doppelpunkt**. Das trifft zu, aber an der falschen Stelle: Schlüssel
wurde `1-0`, Wert wurde `1.8.0=10856202.90`. Alle drei Kanäle landeten damit
unter demselben Thema `smartmeter/vzlogger/1-0` und überschrieben sich
gegenseitig; der Wert war keine Zahl.

Ein Zerleger, der *irgendetwas* trifft, ist gefährlicher als einer, der gar
nichts trifft — er meldet keinen Fehler.

### 2. Anderes Präfix als die klassische Betriebsart

    klassisch:  smartmeter/<Seriennummer>/<Kennzahl>
    vzLogger:   smartmeter/vzlogger/<Kennzahl>

Wer die Betriebsart wechselt, hätte im Miniserver **jeden virtuellen Eingang neu
anlegen** müssen. Genau das soll ein Plugin abnehmen.

### 3. Andere Schlüssel und ein unverträglicher UDP-Satz

Der klassische Leser schreibt `Consumption_Total_OBIS_1.8.0`, vzLogger schrieb
`1-0:1.8.0`. Der UDP-Satz lautete `vzlogger: 1-0:1.8.0=…;` statt
`1234:Consumption_Total_OBIS_1.8.0:…;`. Vorhandene virtuelle UDP-Eingänge
konnten das nicht lesen.

## Die Korrektur

**Beide Betriebsarten senden ab sofort dasselbe Schema.**

| | vorher (vzLogger) | jetzt |
|---|---|---|
| Schlüssel | `1-0:1.8.0` | `Consumption_Total_OBIS_1.8.0` |
| MQTT-Thema | `smartmeter/vzlogger/1-0` | `smartmeter/<Zählernummer>/<Kennzahl>` |
| UDP-Satz | `vzlogger: 1-0:1.8.0=…;` | `<Nr>:<Kennzahl>:<Wert>; …` |
| Datei | `vzlogger.data` | `<Zählernummer>.data` |

Umgesetzt über eine Übersetzungstabelle von der OBIS-Kennung auf den Namen des
klassischen Lesers; Medienkennung `1-0:` und Vorschrift `*255` werden vorher
abgeschnitten. Unbekannte Kennungen laufen als `OBIS_<Kennung>` durch, statt
verworfen zu werden.

### Neu: Feld *Zählernummer*

Steht im Reiter *vzLogger* unter dem UDP-Port. Wer von der klassischen
Betriebsart kommt, trägt **dieselbe Nummer** ein — dann ändert sich im
Miniserver nichts. Vorgabe ist `vzlogger`.

### Neu: Zeitstempel und die beiden kalkulierten Leistungen

vzlogger liefert sie nicht, der klassische Leser schon, und im Miniserver hängen
Auswertungen daran. Ergänzt werden deshalb:

- `Last_Update` (lesbar) und `Last_UpdateLoxEpoche` (Bezugspunkt 01.01.2009,
  Ortszeit) — für die Überwachung auf veraltete Werte
- `Consumption_CalculatedPower_OBIS_1.99.0` und
  `Delivery_CalculatedPower_OBIS_2.99.0`, **abgeleitet** aus dem Vorzeichen von
  `16.7.0`: positiv ist Bezug, negativ ist Einspeisung

**Der Unterschied ist zu kennen:** der klassische Leser rechnet diese beiden aus
dem Zählerfortschritt, hier stammen sie aus der Momentanleistung. Für Zähler mit
`16.7.0` ist das der genauere Weg — aber es ist nicht dieselbe Rechnung, und der
Name verrät das nicht. Deshalb steht es hier und in der `README`.

### MQTT wird jetzt auch hier zentral gelesen

`fetch_vzlogger.pl` las `sendmqtt` und `mqtttopic` aus `vzlogger.json` — dort
stehen sie seit 1.11 gar nicht mehr. Ein geändertes Thema wurde in der
vzLogger-Betriebsart also ignoriert. Gelesen wird jetzt aus `smartmeter.cfg`,
wie im klassischen Zweig.

## Geprüft

| Prüfung | Ergebnis |
|---|---|
| Perl-Syntax `fetch_vzlogger.pl`, `index.cgi` | in Ordnung |
| `TMPL_IF` in `settings.html` | 19/19 |
| `<tr>` in `settings.html` | +1/+1 gegenüber 2.0.0, Altdifferenz unverändert |
| Kein `@parts`, kein `vzlogger.data`, kein `smartmeter/vzlogger` mehr | ja |
| Umbau der Themen im Gateway | aus der Quelle belegt, siehe unten |

**Nicht geprüft:** ein Lauf am Gerät. Die Fehler stammen aus dem Lesen des
eigenen Quelltexts, die Korrektur ist auf demselben Weg entstanden.

## Wie die Namen der virtuellen Eingänge entstehen

Nicht geraten, sondern in `sbin/mqttgateway.pl` des LoxBerry nachgelesen:

```perl
$newtopic =~ s/[\/%]/_/g;   # Not allowed in Loxone: /%
```

Ersetzt werden **nur** Schrägstrich und Prozentzeichen. **Punkte bleiben
stehen.** Aus `smartmeter/1234/Consumption_Total_OBIS_1.8.0` wird deshalb

    smartmeter_1234_Consumption_Total_OBIS_1.8.0

## Merksatz

Zwei Betriebsarten desselben Plugins, die dieselbe Aufgabe erfüllen, müssen
**dasselbe nach außen** liefern. Sonst ist der Wechsel kein Schalter, sondern
ein Umbau — und niemand merkt es, bis die Werte fehlen.
