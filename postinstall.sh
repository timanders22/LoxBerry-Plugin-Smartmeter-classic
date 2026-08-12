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

/bin/sed -i "s#REPLACEBYSUBFOLDER#$ARGV3#" $ARGV5/config/plugins/$ARGV3/smartmeter.cfg
/bin/sed -i "s#REPLACEBYNAME#$ARGV2#" $ARGV5/config/plugins/$ARGV3/smartmeter.cfg
/bin/sed -i "s#REPLACEBYSUBFOLDER#$ARGV3#" $ARGV5/system/daemons/plugins/$ARGV2
/bin/sed -i "s#REPLACEBYBASEFOLDER#$ARGV5#" $ARGV5/system/daemons/plugins/$ARGV2

echo "<INFO> Make scripts executable"
# Die Cron-Verknuepfungen zeigen auf diese beiden Dateien - ohne
# Ausfuehrungsrecht laeuft der Legacy-Leser nicht an.
chmod +x $ARGV5/bin/plugins/$ARGV3/fetch.php 2>/dev/null
chmod +x $ARGV5/bin/plugins/$ARGV3/reboot_cron_runner.sh 2>/dev/null
chmod +x $ARGV5/bin/plugins/$ARGV3/sm_logger.pl 2>/dev/null
chmod +x $ARGV5/bin/plugins/$ARGV3/fetch_vzlogger.pl 2>/dev/null
chmod +x $ARGV5/bin/plugins/$ARGV3/vz_check.sh 2>/dev/null
chmod +x $ARGV5/bin/plugins/$ARGV3/vzlogger_pkg.sh 2>/dev/null

echo "<INFO> Rename htaccess to .htaccess"
mv $ARGV5/webfrontend/htmlauth/plugins/$ARGV3/htaccess $ARGV5/webfrontend/htmlauth/plugins/$ARGV3/.htaccess

echo "<INFO> *******************************************************************"
echo "<INFO> * Das Plugin ist einsatzbereit - ein Neustart ist nicht noetig.   *"
echo "<INFO> * Die udev-Regel fuer die Lesekoepfe hat postroot.sh angelegt.    *"
echo "<INFO> *******************************************************************"

# Exit with Status 0

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-smartmeter-classic}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
netz_zurueck() {
    datei=$1; soll=$2
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
netz_zurueck "smartmeter.cfg" "e480800fe480043a1744f470d7d898505c6b135a6e44f3c4c459ae4e4810254c"


# Zurueckspielen fuer Dateien OHNE mitgelieferte Vorgabe: es gibt nichts,
# womit man vergleichen koennte, also ist das Kriterium "fehlt oder leer".
# Eine vorhandene Datei wird nie ueberschrieben.
netz_ohne_vorgabe() {
    ziel="$NETZ_CFG/$1"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$1"
    [ -f "$zweit" ] || return 0
    if [ ! -s "$ziel" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            chmod 0600 "$ziel" 2>/dev/null
            echo "<OK> $1 aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $1 liess sich nicht zurueckspielen ($zweit)."
        fi
    fi
}
netz_ohne_vorgabe "vzlogger.json"
netz_ohne_vorgabe "vzlogger.conf"

exit 0
