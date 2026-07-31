# Smartmeter Classic 2.1.1 — vier Mängel an der Oberfläche

Stand 31.07.2026. Grundlage: 2.1.0. **Nur Oberfläche und Sprachdateien.**

Alle vier von Ihnen gemeldet. Drei davon sind meine Fehler aus 2.1.0 und 1.10,
einer ist eine Formulierung.

## 1. Die Zählernummer stand ohne Beschriftung da

Ich hatte die Beschriftung als `<label for="vz_serial">` gesetzt und den
Hilfetext mit `<br><small>` **neben** das Feld gehängt. Beides ist jetzt so
gebaut wie die Zeile darüber: Beschriftung als Klartext in der linken Spalte,
Feld rechts auf derselben Höhe, Hilfetext als `sm-hilfe` darunter.

Die Zeile *MQTT* darüber macht es genauso und war immer sichtbar — ich hätte
mich beim Einfügen an der Nachbarzeile orientieren müssen, statt eine eigene
Bauart zu wählen.

## 2. „Daten per MQTT senden" ließ sich nicht mehr aufklappen

Der Auswahlknopf war da, der Pfeil war da, ein Klick bewirkte nichts.

Ursache ist eine eigene Formatvorlage aus 1.9:

```css
.sm-feld select, .sm-feld input[type=text], … { width:100%; padding:8px; border:…; background:#fff; }
```

jQuery Mobile baut aus einem Auswahlfeld **zwei** Dinge: einen sichtbaren Knopf
und das ursprüngliche `<select>`, das unsichtbar genau darüber liegt und die
Klicks abfängt. Wer dieses `<select>` selbst gestaltet — Breite, Innenabstand,
Rahmen, Hintergrund —, verschiebt es unter dem Knopf weg. Dann trifft der Klick
ins Leere.

Dasselbe erklärt rückblickend den Mangel aus 1.10: der weiße Kasten ohne Pfeil
bei *Zeitstempel* war nicht das fehlende jQuery-Mobile-Aussehen, sondern **mein
eigenes** `<select>`, das über dessen Knopf lag. Damals habe ich das Symptom
behandelt.

Jetzt gestaltet die Vorlage nur noch den Behälter:

```css
.sm-feld .ui-select { max-width: 520px; }
```

**Merksatz:** was jQuery Mobile umbaut, gestaltet man nicht selbst — man
gestaltet den Behälter, den es erzeugt.

## 3. Auf der Legacy-Seite fehlten Legende und Gruppenüberschriften

Die Farberklärung der Knöpfe (*Ansehen*, *Technische Auskunft*, *Löst etwas
aus*) fehlte dort — und mit ihr die drei Zwischenüberschriften, was nicht
auffällt, wenn man nicht weiß, dass sie da sein sollten.

Die Vorlage `templates/multi/main.html` verlangt sechs Schlüssel:

    T::LEGENDE.LESEN / .TECHNIK / .AKTION
    T::GRUPPE.LESEN  / .TECHNIK / .AKTION

Ich hatte sie in `templates/multi/de/language.txt` abgelegt. **Gelesen wird aber
`templates/de/language.txt`** — `index_legacy.cgi` setzt den Sprachpfad ohne das
Unterverzeichnis `multi`. Also fehlten alle sechs, und HTML::Template setzt für
fehlende Schlüssel wortlos Leerstring ein.

Die sechs Schlüssel stehen jetzt in `templates/de/language.txt` und
`templates/en/language.txt`.

### Das ist der dritte Fehler derselben Art

1.10 waren es `SEND_MQTT` und `MQTT_TOPIC`, dort hatte ich den Merksatz
aufgeschrieben: *wer eine `TMPL_VAR NAME=T::…` einfügt, muss den Schlüssel in
jede Sprachdatei eintragen.* Der Merksatz war richtig und hat nicht geholfen,
weil ich die Datei nicht von Hand suche, sondern annehme, sie liege neben der
Vorlage.

**Deshalb jetzt eine Prüfung statt eines Merksatzes.** Beide Vorlagen werden
gegen beide Sprachdateien abgeglichen — alle `T::`-Verweise gegen alle
vorhandenen Schlüssel:

| Vorlage | gegen `de` | gegen `en` |
|---|---|---|
| `multi/main.html` (56 Verweise) | 0 fehlend | 0 fehlend |
| `settings.html` | 0 fehlend | 0 fehlend |

## 4. „Hausstandard" gestrichen

Der Begriff sagt Ihnen etwas und sonst niemandem. Entfernt aus der Auswahl
(*Ja — Hausstandard* → *Ja*), aus der Überschrift *MQTT — der Hausstandard* und
aus dem Hinweis zu UDP. Der Legacy-Hinweis endet nicht mehr mit dem Satz über
das Entfernen in künftigen Fassungen — in dieser Abspaltung bleibt der
klassische Leser, das ist ihr Zweck. Deutsch und Englisch, beide Vorlagen.

## Geprüft

| Prüfung | Ergebnis |
|---|---|
| Alle `T::`-Verweise beider Vorlagen gegen `de` und `en` | 0 fehlend |
| `TMPL_IF` in `settings.html` | 19/19 |
| `<div>` in `settings.html` | 25/25 |
| `<tr>` | 14/15 — Altdifferenz unverändert |
| Kein eigenes `select` in der Formatvorlage | ja |
| „Hausstandard" im sichtbaren Text | nicht mehr vorhanden |
| Satz zur eingestellten Implementierung | in allen vier Sprachdateien entfernt |

**Nicht geprüft:** das Aussehen im Browser. Punkt 2 ist aus dem Zusammenspiel
von Formatvorlage und jQuery Mobile hergeleitet, nicht am Gerät gesehen. Bitte
nach dem Einspielen einmal prüfen, ob sich *Daten per MQTT senden* und
*Zeitstempel* aufklappen lassen.
