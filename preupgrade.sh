#!/bin/sh

# Bash script which is executed in case of an update (if this plugin is already
# installed on the system). This script is executed as very first step (*BEFORE*
# preinstall.sh) and can be used e.g. to save existing configfiles to /tmp 
# during installation. Use with caution and remember, that all systems may be
# different!
#
# Exit code must be 0 if executed successfully.
#
# Will be executed as user "loxberry".
#
# We add 5 arguments when executing the script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

# To use important variables from command line use the following code:
ARGV0=$0 # Zero argument is shell command
#echo "<INFO> Command is: $ARGV0"

ARGV1=$1 # First argument is temp folder during install
#echo "<INFO> Temporary folder is: $ARGV1"

ARGV2=$2 # Second argument is Plugin-Name for scipts etc.
#echo "<INFO> (Short) Name is: $ARGV2"

ARGV3=$3 # Third argument is Plugin installation folder
#echo "<INFO> Installation folder is: $ARGV3"

ARGV4=$4 # Forth argument is Plugin version
#echo "<INFO> Installation folder is: $ARGV4"

ARGV5=$5 # Fifth argument is Base folder of LoxBerry
#echo "<INFO> Installation folder is: $ARGV5"

ARGV6=$6 # Sechstes Argument ist der Arbeitsordner des Installers (absolut)

# ---------------------------------------------------------------------------
# WARUM ES DIESE SICHERUNG UEBERHAUPT BRAUCHT
#
# LoxBerry loescht den Konfigordner beim Upgrade NICHT - es kopiert aber die
# mitgelieferten Dateien darueber:
#     cp -r -v $tempfolder/config/* $lbhomedir/config/plugins/$pfolder
# (sbin/plugininstall.pl). Dieses Plugin liefert config/smartmeter.cfg mit.
# Ohne diese Sicherung waeren nach jedem Upgrade Zaehlerprofile, Takt und
# Lesekopf-Bezeichnungen auf Werkseinstellung zurueck. Die Reihenfolge im
# Installer passt genau dazu: preupgrade -> Konfig kopieren -> postupgrade.
#
# WOHIN GESICHERT WIRD - UND WARUM NICHT NACH /tmp
#
# Bis 2.3.2 stand hier /tmp/$ARGV1_upgrade. Zwei Anmerkungen dazu:
#
#   Die verbreitete Annahme, $1 sei bereits ein absoluter Pfad und es
#   entstuende der Unsinn /tmp//tmp/uploads/xyz_upgrade, trifft NICHT zu.
#   Der Installer ruft dieses Skript so auf:
#       "$script" "$tempfile" "$pname" "$pfolder" "$pversion" "$lbhomedir" "$tempfolder"
#   $1 ist $tempfile - eine Zufallskennung aus zehn Zeichen (&generate(10)).
#   Der absolute Arbeitsordner kommt als SECHSTES Argument. Ein "cp ...
#   $ARGV1/config" waere deshalb kein Fix, sondern ein Fehler: es gibt
#   keinen Ordner mit diesem Namen, das Kopieren schluege fehl und die
#   Konfiguration waere beim naechsten Upgrade weg.
#
#   Berechtigt ist der zweite Teil des Einwands: /tmp ist auf dem LoxBerry
#   fluechtig. Faellt waehrend des Upgrades der Strom aus, ist die Sicherung
#   fort. Der Arbeitsordner des Installers liegt unter data/system/tmp und
#   wird vom Installer selbst wieder aufgeraeumt - erst NACH postupgrade
#   (plugininstall.pl: "Cleaning" steht hinter dem postupgrade-Aufruf).
#   Genau dorthin wird jetzt gesichert, mit Rueckfall auf den alten Weg,
#   falls eine aeltere LoxBerry-Fassung das sechste Argument nicht liefert.
#
# NICHT MEHR GESICHERT WERDEN DIE PROTOKOLLE. log/plugins liegt auf dem
# LoxBerry fest auf der Ramdisk (sbin/createtmpfsfoldersinit.sh bindet den
# Ordner dorthin) und ist nach jedem Neustart ohnehin leer. Eine Sicherung
# haette nur fluechtige Daten von der Ramdisk in die Ramdisk kopiert.
# ---------------------------------------------------------------------------

if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
	SICHERUNG="$ARGV6/smartmeter_upgrade"
else
	echo "<INFO> Kein Arbeitsordner uebergeben - Rueckfall auf /tmp"
	SICHERUNG="/tmp/${ARGV1}_upgrade"
fi
echo "$SICHERUNG" > "$ARGV5/config/plugins/$ARGV3/.upgrade_pfad" 2>/dev/null

echo "<INFO> Sicherungsordner: $SICHERUNG"
mkdir -p "$SICHERUNG/config"

echo "<INFO> Backing up existing config files"
cp -a "$ARGV5/config/plugins/$ARGV3/." "$SICHERUNG/config/" || true

# Exit with Status 0

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-smartmeter-classic}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
if [ -s "$NETZ_CFG/smartmeter.cfg" ]; then
    cp -p "$NETZ_CFG/smartmeter.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.smartmeter.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.smartmeter.cfg" 2>/dev/null
fi
echo "<INFO> Zweitschrift der Einstellungen angelegt."

exit 0
