# Smartmeter Classic 2.1.2 — deutsche Sprachfassung und zwei Feldbreiten

Stand 31.07.2026. Grundlage: 2.1.1. **Nur Oberfläche und Sprachdateien.**

## 1. Der vzLogger-Reiter war komplett englisch

Nicht einzelne Wörter — **die ganze Seite**. „Save", „vzLogger active",
„Serial device (IR reading head)", „Baudrate", „UDP port", „vzlogger is
running".

Die Ursache steht in einem Verzeichnis, in das ich nie gesehen habe:

    templates/lang/language_en.ini     vorhanden
    templates/lang/language_de.ini     fehlte

`LoxBerry::System::readlanguage` sucht die Datei zur eingestellten Sprache und
**fällt still auf Englisch zurück**, wenn es sie nicht gibt. Kein Fehler, keine
Warnung — genau wie bei einem fehlenden Schlüssel, nur eine Stufe höher: hier
fehlte nicht ein Eintrag, sondern die ganze Datei.

`templates/lang/language_de.ini` ist jetzt angelegt, mit allen 24 Einträgen der
englischen Fassung. Geprüft ist nicht nur, dass sie existiert, sondern dass sie
**dieselben Schlüssel** enthält und dass jeder in der Vorlage benutzte Verweis
darin steht.

Bei der Gelegenheit zwei veraltete Texte berichtigt, in beiden Sprachen:

- *„This Feature will be implemented soon. Please currently use the Legacy
  Configuration."* — die vzLogger-Betriebsart ist seit 2.0.0 der Standardweg.
- Der Hinweis bei fehlendem vzlogger sprach noch vom mitgelieferten
  ARM-32-Bit-Programm. Mitgeliefert wird seit 2.0.0 keines mehr; installiert
  wird aus der Paketquelle von volkszaehler.org.

## 2. Restliche englische Begriffe

| vorher | jetzt |
|---|---|
| `Off` / `On` (beide Schalter) | `Aus` / `Ein` |
| Logfiles zeigen | Protokolldateien ansehen |
| Logfile: / Logfiles | Protokolldatei: / Protokolldateien |
| Parity: | Parität: |
| Handshake: | Datenflusssteuerung: |
| Databits: / Stopbits: | Datenbits: / Stoppbits: |
| Timeout: | Zeitüberschreitung: |
| Cache löschen | Zwischenspeicher leeren |
| Smartmeter Konfiguration (Legacy) | Smartmeter einrichten (klassisch) |
| Generic D0-Protocol / Generic SML-Protocol | Allgemeines D0-Protokoll / Allgemeines SML-Protokoll |

**Achtung bei den letzten beiden:** das sind die Profile, die Sie für den E220
benutzen. Geändert ist nur die **Beschriftung**, nicht der gespeicherte Wert
(`genericd0`, `genericsml`) — Ihre Einstellung bleibt unverändert. Die Namen der
Zählerhersteller (Apator, Holley und die übrigen) sind Eigennamen und bleiben
stehen.

## 3. Das Feld für das MQTT-Thema war halb so breit wie sein Kasten

Dieselbe Ursache wie beim Auswahlfeld in 2.1.1, nur anders sichtbar.

jQuery Mobile legt um jedes Textfeld einen eigenen Behälter, der die Umrandung
und den weißen Hintergrund trägt. Meine Formatvorlage begrenzte das **Feld**
auf 520 Pixel, der Behälter blieb bei voller Breite. Ergebnis: ein schmales
Eingabefeld in einem breiten weißen Kasten.

Gestaltet wird jetzt ausschließlich der Behälter:

```css
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
```

Damit ist dieselbe Regel für Text- und Auswahlfelder zuständig, und die Größe
steht an genau einer Stelle.

**Das war derselbe Fehler zweimal hintereinander** — in 2.1.1 hatte ich ihn nur
für das Auswahlfeld erkannt und die Textfelder unverändert gelassen, obwohl der
Grund identisch war. Wer eine Ursache findet, muss prüfen, wo sie sonst noch
greift.

## Geprüft

| Prüfung | Ergebnis |
|---|---|
| `language_de.ini` deckt alle Schlüssel von `language_en.ini` | 24/24 |
| Alle `VZ.`/`MENU.`/`COMMON.`-Verweise in `settings.html` vorhanden | 16/16 |
| Alle `T::`-Verweise beider Vorlagen gegen `de` und `en` | 0 fehlend |
| Englische Wörter in den deutschen Sprachdateien | keine |
| Englische Wörter im sichtbaren Text von `settings.html` | keine |
| Keine eigene Gestaltung von Feldern und Auswahlfeldern mehr | ja |
| `TMPL_IF` und `<div>` ausgeglichen | ja |

**Nicht geprüft:** das Aussehen im Browser. Die Feldbreite ist wieder
hergeleitet, nicht gesehen.

## Merksatz

Eine fehlende Übersetzung meldet sich nicht — sie zeigt die Vorgabesprache.
Deshalb prüft die Ablage jetzt beide Richtungen: jeder Verweis der Vorlage muss
in der Sprachdatei stehen, und jede Sprachdatei muss dieselben Schlüssel haben
wie die englische.
