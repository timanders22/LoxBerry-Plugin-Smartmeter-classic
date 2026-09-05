#!/bin/sh

# Bashscript which is executed by bash *AFTER* complete installation is done
# (but *BEFORE* postupdate). Use with caution and remember, that all systems
# may be different! Better to do this in your own Pluginscript if possible.
#
# Exit code must be 0 if executed successfull.
#
# Will be executed as user "loxberry".
#
# We add 5 arguments when executing the script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

ARGV2=$2 # Second argument is Plugin-Name for scipts etc.
ARGV3=$3 # Third argument is Plugin installation folder
ARGV5=$5 # Fifth argument is Base folder of LoxBerry

NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-smartmeter-classic}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"

# ===========================================================================
# ZURUECKSPIELEN AUS DER ZWEITSCHRIFT - UND ZWAR VOR DEM sed
#
# BERICHTIGT AM 26.08.2026, und der Fehler war stumm.
#
# Bis dahin stand dieser Block am ENDE der Datei und erkannte "die Datei des
# Nutzers ist verloren" unter anderem daran, dass sie zeichengenau die
# mitgelieferte Vorgabe ist - mit einer fest eingetragenen Pruefsumme.
# Gemessen:
#
#     sha256 der ausgelieferten config/smartmeter.cfg   e480800fe480043a...
#     hier hinterlegt war genau diese Zahl              gleich
#     sha256 NACH den beiden sed-Zeilen weiter unten    1a63821b850fa95c...
#
# Die beiden sed liefen VORHER und ersetzten REPLACEBYSUBFOLDER und
# REPLACEBYNAME. Wenn der Vergleich an die Reihe kam, stand die Datei laengst
# auf einer anderen Pruefsumme - er konnte nie zutreffen. Uebrig blieben
# "fehlt" und "ist leer", und beides ist die Datei nach einem Update gerade
# NICHT: der Installateur hat die mitgelieferte darueber kopiert.
#
# Der Rueckspielweg war damit tot, und zwar fuer die einzige Datei, die den
# Zugriffstoken des Endpunkts traegt.
#
# Zwei Aenderungen:
#   1. Der Block steht jetzt VOR den sed-Zeilen.
#   2. Er entscheidet nach INHALT, nicht nach Form: die mitgelieferte
#      Vorgabe traegt den Platzhalter REPLACEBYSUBFOLDER. Eine Datei mit
#      diesem Platzhalter ist die Werkseinstellung, ganz gleich welche
#      Pruefsumme sie hat. Damit gibt es keine Zahl mehr, die beim naechsten
#      Zeichen in der Vorgabedatei still falsch wird.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
# ===========================================================================
netz_zurueck_cfg() {
    ziel="$NETZ_CFG/smartmeter.cfg"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.smartmeter.cfg"
    if [ ! -f "$zweit" ]; then
        return 0
    fi
    verloren=0
    grund=""
    if [ ! -f "$ziel" ]; then
        verloren=1; grund="die Datei fehlt"
    elif [ ! -s "$ziel" ]; then
        verloren=1; grund="die Datei ist leer"
    elif grep -q "REPLACEBYSUBFOLDER" "$ziel" 2>/dev/null; then
        verloren=1; grund="die Datei ist die mitgelieferte Werkseinstellung"
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            chmod 0640 "$ziel" 2>/dev/null
            echo "<OK> smartmeter.cfg aus der Zweitschrift wiederhergestellt ($grund)."
        else
            echo "<WARNING> smartmeter.cfg liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    else
        echo "<INFO> Die vorhandene smartmeter.cfg bleibt unangetastet."
    fi
}

# Fuer Dateien OHNE mitgelieferte Vorgabe gibt es nichts, womit man
# vergleichen koennte - dort ist das Kriterium "fehlt oder leer". Eine
# vorhandene Datei wird nie ueberschrieben.
netz_ohne_vorgabe() {
    ziel="$NETZ_CFG/$1"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$1"
    [ -f "$zweit" ] || return 0
    if [ ! -s "$ziel" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            chmod 0640 "$ziel" 2>/dev/null
            echo "<OK> $1 aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $1 liess sich nicht zurueckspielen ($zweit)."
        fi
    fi
}

netz_zurueck_cfg
netz_ohne_vorgabe "vzlogger.json"
netz_ohne_vorgabe "vzlogger.conf"

# ===========================================================================
# Platzhalter ersetzen
#
# REPLACEBYSUBFOLDER, REPLACEBYNAME und REPLACEBYBASEFOLDER sind KEINE
# Platzhalter des Installateurs - der kennt nur REPLACELBHOMEDIR,
# REPLACELBPPLUGINDIR und die uebrigen REPLACELBP*. Diese drei ersetzt das
# Plugin deshalb selbst, und genau deshalb muss der Block darueber vorher
# laufen.
# ===========================================================================
# Die Pfade quotiert. Ein Leerzeichen im Installationsverzeichnis
# zerlegte die Zeilen sonst, und sed schriebe in eine Datei, die niemand
# gemeint hat.
/bin/sed -i "s#REPLACEBYSUBFOLDER#$ARGV3#" "$ARGV5/config/plugins/$ARGV3/smartmeter.cfg"
/bin/sed -i "s#REPLACEBYNAME#$ARGV2#" "$ARGV5/config/plugins/$ARGV3/smartmeter.cfg"
/bin/sed -i "s#REPLACEBYSUBFOLDER#$ARGV3#" "$ARGV5/system/daemons/plugins/$ARGV2"
/bin/sed -i "s#REPLACEBYBASEFOLDER#$ARGV5#" "$ARGV5/system/daemons/plugins/$ARGV2"

# Die Konfiguration traegt das Zugriffstoken des Endpunkts.
chmod 0640 "$NETZ_CFG/smartmeter.cfg" 2>/dev/null

echo "<INFO> Make scripts executable"
# Die Cron-Verknuepfungen zeigen auf diese beiden Dateien - ohne
# Ausfuehrungsrecht laeuft der Legacy-Leser nicht an.
chmod +x $ARGV5/bin/plugins/$ARGV3/fetch.php 2>/dev/null
chmod +x $ARGV5/bin/plugins/$ARGV3/reboot_cron_runner.sh 2>/dev/null
chmod +x $ARGV5/bin/plugins/$ARGV3/sm_logger.pl 2>/dev/null
chmod +x $ARGV5/bin/plugins/$ARGV3/fetch_vzlogger.pl 2>/dev/null
chmod +x $ARGV5/bin/plugins/$ARGV3/vz_check.sh 2>/dev/null
chmod +x $ARGV5/bin/plugins/$ARGV3/vzlogger_pkg.sh 2>/dev/null
chmod +x $ARGV5/bin/plugins/$ARGV3/healthcheck 2>/dev/null

# ===========================================================================
# Die Zeilenenden der Skripte, die der Cron DIREKT ausfuehrt
#
# Ein CRLF hinter der Shebang-Zeile macht aus "#!/usr/bin/env php" ein
# "#!/usr/bin/env php\r": der Kernel sucht dann einen Interpreter namens
# "php\r" und findet ihn nicht. bin/fetch.php war bis 2.3.14 als einzige
# PHP-Datei dieses Plugins durchgehend CRLF - der Cron-Eintrag des
# klassischen Lesers konnte nie laufen. Aufgefallen ist es nicht, weil
# "Jetzt einmal abfragen" php ausdruecklich davorsetzt und dieser Weg
# deshalb weiter ging.
#
# Die Dateien sind seither LF. Diese Schleife ist der Guertel dazu: sie
# kostet nichts und faengt den Fall ab, dass ein Werkzeug auf dem Weg zum
# Anwender die Zeilenenden verstellt hat.
# ===========================================================================
for SM_SKRIPT in fetch.php reboot_cron_runner.sh sm_logger.pl fetch_vzlogger.pl healthcheck; do
    SM_PFAD="$ARGV5/bin/plugins/$ARGV3/$SM_SKRIPT"
    [ -f "$SM_PFAD" ] || continue
    if head -c 200 "$SM_PFAD" 2>/dev/null | grep -q "$(printf '\r')"; then
        if command -v dos2unix >/dev/null 2>&1; then
            dos2unix -q "$SM_PFAD" 2>/dev/null
        else
            /bin/sed -i "s/$(printf '\r')$//" "$SM_PFAD" 2>/dev/null
        fi
        echo "<WARNING> $SM_SKRIPT trug Windows-Zeilenenden und wurde umgestellt."
    fi
done

# Die Historie laeuft aus dem Cron und muss ausfuehrbar sein.
chmod 0755 "$ARGV5/bin/plugins/$ARGV3/sm_historie.php" 2>/dev/null
chmod 0755 "$ARGV5/bin/plugins/$ARGV3/sm_abgleich.php" 2>/dev/null

echo "<INFO> Rename htaccess to .htaccess"
# Quotiert UND beurteilt. Scheitert das mv, bleibt die .htaccess aus -
# und die Installation meldete bis 2.4.2 trotzdem Erfolg.
if mv "$ARGV5/webfrontend/htmlauth/plugins/$ARGV3/htaccess" \
      "$ARGV5/webfrontend/htmlauth/plugins/$ARGV3/.htaccess"; then
	echo "<OK> .htaccess angelegt."
else
	echo "<WARNING> Die .htaccess liess sich nicht anlegen."
	echo "<WARNING> Der angemeldete Bereich des Plugins ist dann ungeschuetzt."
fi

echo "<INFO> *******************************************************************"
echo "<INFO> * Das Plugin ist einsatzbereit - ein Neustart ist nicht noetig.   *"
echo "<INFO> * Die udev-Regel fuer die Lesekoepfe hat postroot.sh angelegt.    *"
echo "<INFO> *******************************************************************"

exit 0
