#!/bin/sh

# Laeuft als root, nachdem LoxBerry die Abhaengigkeiten installiert und
# postinstall ausgefuehrt hat. Richtet vzlogger ein.
#
# Herkunft: der Ablauf ist dem Plugin Smartmeter-NG entnommen und angepasst.
# Vorher lieferte dieses Plugin ein vorkompiliertes armhf-Binary mit - das
# konnte auf 64-Bit-Systemen nie laufen. Siehe AENDERUNGEN_1.6.md.

ARGV3=$3   # Installationsordner des Plugins
ARGV5=$5   # Basisordner von LoxBerry

PKG="$ARGV5/bin/plugins/$ARGV3/vzlogger_pkg.sh"
CHECK="$ARGV5/bin/plugins/$ARGV3/vz_check.sh"

if [ "$(id -u)" != "0" ]; then
	echo "<ERROR> postroot.sh muss als root laufen."
	exit 2
fi

chmod +x "$PKG" "$CHECK" 2>/dev/null

if [ ! -x "$PKG" ]; then
	echo "<ERROR> Paket-Helfer fehlt: $PKG"
	exit 0
fi

echo "<INFO> Richte vzlogger ein (Paketquelle von volkszaehler.org)"
if "$PKG" install; then
	echo "<OK> vzlogger steht zur Verfuegung."
else
	echo "<WARNING> ***************************************************************"
	echo "<WARNING> vzlogger konnte nicht installiert werden. Das Plugin bleibt"
	echo "<WARNING> voll funktionsfaehig: die Legacy-Betriebsart braucht kein"
	echo "<WARNING> vzlogger und liest den Zaehler."
	echo "<WARNING> Auf der Plugin-Seite steht unter Diagnose, woran es lag;"
	echo "<WARNING> dort laesst sich die Installation auch wiederholen."
	echo "<WARNING> ***************************************************************"
	VZJSON="$ARGV5/config/plugins/$ARGV3/vzlogger.json"
	if [ -f "$VZJSON" ]; then
		/bin/sed -i 's/"enabled"[[:space:]]*:[[:space:]]*true/"enabled": false/' "$VZJSON"
		/bin/sed -i 's/"enabled"[[:space:]]*:[[:space:]]*1/"enabled": 0/' "$VZJSON"
	fi
fi

exit 0
