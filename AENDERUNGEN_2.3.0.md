# Smartmeter classic 2.3.0 — komplette Oberfläche auf PHP

Beide Lesewege stehen jetzt als Reiter in **einer** Seite. Perl bleibt nur noch
dort, wo es hingehört: beim Treiber.

| bisher | jetzt |
|---|---|
| `webfrontend/htmlauth/index.cgi` + `templates/settings.html` | `webfrontend/htmlauth/index.php` |
| `webfrontend/htmlauth/index_legacy.cgi` + `templates/multi/` | Reiter *Smartmeter (Legacy)* in derselben Seite |
| `webfrontend/htmlauth/fetch.cgi` | Knopf *Jetzt einmal abfragen* |
| `webfrontend/htmlauth/logfiles.cgi` | Reiter *Logdateien* |
| `bin/fetch.pl` | `bin/fetch.php` |
| — | `sm_lib.php`, `sm_test.php`, `sm_legacy.php` |

## Was bewusst Perl bleibt

`bin/sm_logger.pl` — der eigentliche Leser mit seinen **41 Zählerprofilen**.
Er spricht über `Device::SerialPort` mit dem Zähler und wechselt bei
D0-Zählern *mitten in der Sitzung* die Baudrate. Dafür gibt es im
PHP-Standardumfang von LoxBerry kein verlässliches Gegenstück, und 41 Profile
ließen sich ohne 41 Zähler auch nicht nachprüfen.

Das ist keine Notlösung, sondern die bereits im Plugin etablierte Aufteilung —
nur andersherum: die SML-Auswertung liegt seit jeher als PHP vor
(`php_sml_parser.class.php`) und wird vom Perl-Skript aufgerufen.

## Behoben: doppelte Abfrage beim Wechsel des Takts

`index_legacy.cgi` löschte beim Setzen des Abfragetakts die übrigen
Cron-Verknüpfungen einzeln auf. Im Zweig für **10 Minuten** standen dort
`cron.1min`, `cron.3min` und `cron.5min` — **ohne führende Null**. Diese Ordner
gibt es nicht; sie heißen `cron.01min`, `cron.03min`, `cron.05min`.

Wer von 1, 3 oder 5 Minuten auf 10 Minuten wechselte, behielt die alte
Verknüpfung. Der Zähler wurde danach **in beiden Takten** abgefragt — zwei
Leser auf derselben seriellen Schnittstelle, mit entsprechend sprunghaften
Werten.

`sm_cron_setzen()` räumt jetzt erst **alle** Verknüpfungen weg und legt danach
genau eine an. Damit ist die Fehlerklasse als solche erledigt, nicht nur der
eine Tippfehler.

## Weiteres

- Die Oberfläche weist es jetzt **ab**, wenn man den Legacy-Leser einschaltet,
  während vzLogger läuft — bisher ließ sich beides gleichzeitig aktivieren, und
  erst die Diagnose meldete den Konflikt hinterher.
- Eingaben werden geprüft statt zurechtgebogen: Baudrate 300–921600,
  Zeichenrahmung nur 8n1/7n1/7e1/8e1, Ports 1–65535, OBIS-Kennzahlen einzeln.
- Ein gespeicherter, gerade nicht angesteckter Lesekopf verschwindet nicht mehr
  stillschweigend aus der Auswahlliste.
- `postinstall.sh` macht `fetch.php`, `reboot_cron_runner.sh` und
  `sm_logger.pl` ausführbar — die Cron-Verknüpfungen zeigen darauf.
- **Zwei `[SYSTEM]`-Abschnitte in `plugin.cfg`** zusammengeführt. Perls
  `Config::Simple` verzieh das stillschweigend, ein strenger INI-Leser bricht
  ab („section 'SYSTEM' already exists").
- `prerelease.cfg` stand auf Fassung 1.4 bei Plugin 2.2.0.
- Entfallen: `templates/de/`, `templates/en/` und `templates/multi/` — sie
  gehörten zum alten Legacy-CGI.

## Was nicht geprüft werden konnte

**Kein Lauf an einem echten Zähler.** Geprüft wurden die Syntax aller
PHP-Dateien mit einem Parser, die Vollständigkeit aller aufgerufenen
Funktionen, und die neue Cron-Logik gegen den früheren Fehlerfall. Ob
`sm_logger.pl` unter `fetch.php` genauso anspringt wie unter `fetch.pl`,
muss der erste Lauf auf dem Gerät zeigen — die Aufrufparameter sind
unverändert übernommen.
