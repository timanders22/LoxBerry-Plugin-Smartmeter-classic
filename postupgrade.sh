#!/bin/sh

# Smartmeter classic - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Spielt die in preupgrade.sh gesicherte Konfiguration zurueck. Der Installer
# hat zwischenzeitlich die MITGELIEFERTE config/smartmeter.cfg darueber
# kopiert - ohne diesen Schritt stuenden Zaehlerprofile, Takt und die
# Bezeichnungen der Lesekoepfe wieder auf Werkseinstellung.
#
# Der Ablauf im Installer ist genau diese Reihenfolge:
#   preupgrade  ->  Konfigdateien kopieren  ->  postinstall  ->  postupgrade
#
# Zum Sicherungsort siehe die ausfuehrliche Begruendung in preupgrade.sh.

ARGV0=$0
ARGV1=$1 # Zufallskennung des Installers (KEIN Pfad)
ARGV2=$2
ARGV3=$3 # Installationsordner des Plugins
ARGV4=$4
ARGV5=$5 # Basisordner von LoxBerry
ARGV6=$6 # Arbeitsordner des Installers (absolut) - erst ab neueren Fassungen

MERKER="$ARGV5/config/plugins/$ARGV3/.upgrade_pfad"

# preupgrade.sh hat den tatsaechlich benutzten Ordner hinterlegt. Ihn hier
# erneut zu erraten waere die eine Stelle, an der beide Skripte
# auseinanderlaufen koennten.
if [ -r "$MERKER" ]; then
	SICHERUNG=$(cat "$MERKER")
elif [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
	SICHERUNG="$ARGV6/smartmeter_upgrade"
else
	SICHERUNG="/tmp/${ARGV1}_upgrade"
fi

if [ ! -d "$SICHERUNG/config" ]; then
	echo "<WARNING> Keine gesicherte Konfiguration unter $SICHERUNG gefunden."
	echo "<WARNING> Die Einstellungen im Reiter Legacy bitte einmal nachsehen."
	rm -f "$MERKER" 2>/dev/null
	exit 0
fi

echo "<INFO> Spiele gesicherte Konfiguration zurueck aus $SICHERUNG"
# -a erhaelt Rechte und Zeitstempel; der Punkt am Ende kopiert auch
# Dateien, deren Name mit einem Punkt beginnt.
cp -a "$SICHERUNG/config/." "$ARGV5/config/plugins/$ARGV3/" && \
	echo "<OK> Konfiguration wiederhergestellt."

# Den eigenen Merker nicht als Altlast liegen lassen.
rm -f "$ARGV5/config/plugins/$ARGV3/.upgrade_pfad" 2>/dev/null

# Der Arbeitsordner des Installers wird von LoxBerry selbst aufgeraeumt.
# Nur der Rueckfallweg unter /tmp gehoert uns.
case "$SICHERUNG" in
	/tmp/*) rm -rf "$SICHERUNG" ;;
esac

exit 0
