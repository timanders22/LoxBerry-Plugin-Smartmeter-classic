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

# BERICHTIGT AM 26.08.2026: hier stand ein Merker .upgrade_pfad, den
# preupgrade.sh IN den Konfigurationsordner geschrieben hat. Er konnte dort
# nie ankommen - purge_installation entfernt genau dieses Verzeichnis im
# Upgrade-Zweig, bevor postupgrade laeuft (plugininstall.pl :886 -> :1631,
# gemessen an Commit 666baf1de87a). Gegriffen hat immer der Rueckfall.
#
# Beide Skripte rechnen den Pfad jetzt aus DEMSELBEN Argument aus. Das ist
# die eine Stelle, an der sie nicht auseinanderlaufen koennen - ein Merker,
# der nie da ist, ist eine falsche Faehrte fuer den naechsten Umbau.
if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
	SICHERUNG="$ARGV6/smartmeter_upgrade"
else
	SICHERUNG="/tmp/${ARGV1}_upgrade"
fi

if [ ! -d "$SICHERUNG/config" ]; then
	# Vor dem Warnen nachsehen, wie es wirklich steht: postinstall.sh hat
	# die Zweitschrift moeglicherweise schon zurueckgespielt. Eine Warnung
	# bei heiler Konfiguration entwertet die echte beim naechsten Mal.
	if [ -s "$ARGV5/config/plugins/$ARGV3/smartmeter.cfg" ] \
	   && ! grep -q "REPLACEBYSUBFOLDER" "$ARGV5/config/plugins/$ARGV3/smartmeter.cfg" 2>/dev/null; then
		echo "<INFO> Keine gesicherte Konfiguration unter $SICHERUNG - die"
		echo "<INFO> vorhandene smartmeter.cfg traegt aber eigene Einstellungen."
		echo "<INFO> Es ist nichts zurueckzuspielen."
		exit 0
	fi
	echo "<WARNING> Keine gesicherte Konfiguration unter $SICHERUNG gefunden."
	echo "<WARNING> Die Einstellungen im Reiter Smartmeter (klassisch) bitte"
	echo "<WARNING> einmal nachsehen."
	exit 0
fi

echo "<INFO> Spiele gesicherte Konfiguration zurueck aus $SICHERUNG"
# -a erhaelt Rechte und Zeitstempel; der Punkt am Ende kopiert auch
# Dateien, deren Name mit einem Punkt beginnt.
cp -a "$SICHERUNG/config/." "$ARGV5/config/plugins/$ARGV3/" && \
	echo "<OK> Konfiguration wiederhergestellt."

# Der Arbeitsordner des Installers wird von LoxBerry selbst aufgeraeumt.
# Nur der Rueckfallweg unter /tmp gehoert uns.
case "$SICHERUNG" in
	/tmp/*) rm -rf "$SICHERUNG" ;;
esac

exit 0
