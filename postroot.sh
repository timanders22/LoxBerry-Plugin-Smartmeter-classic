#!/bin/sh

# Laeuft als root, nachdem LoxBerry die Abhaengigkeiten installiert und
# postinstall ausgefuehrt hat. Richtet vzlogger ein.
#
# Herkunft: der Ablauf ist dem Plugin Smartmeter-NG entnommen und angepasst.
# Vorher lieferte dieses Plugin ein vorkompiliertes armhf-Binary mit - das
# konnte auf 64-Bit-Systemen nie laufen. Seit 2.3.2 ist auch der letzte Rest
# davon fort: der daemon suchte noch nach bin/plugins/<ordner>/vzlogger/,
# einem Ordner, den kein Skript mehr anlegt. Siehe README.md.

ARGV3=$3   # Installationsordner des Plugins
ARGV5=$5   # Basisordner von LoxBerry

PKG="$ARGV5/bin/plugins/$ARGV3/vzlogger_pkg.sh"
CHECK="$ARGV5/bin/plugins/$ARGV3/vz_check.sh"

if [ "$(id -u)" != "0" ]; then
	echo "<ERROR> postroot.sh muss als root laufen."
	exit 2
fi

# ---------------------------------------------------------------------------
# udev-Regel fuer die Lesekoepfe
#
# Sie vergibt die stabilen Namen /dev/serial/smartmeter/<Seriennummer>. Ohne
# sie heisst ein Lesekopf ttyUSB0 oder ttyUSB1 - je nachdem, in welcher
# Reihenfolge er beim Start erkannt wurde. Bei zwei Koepfen liest das Plugin
# dann irgendwann den falschen Zaehler aus.
#
# Bis 2.3.2 legte NUR daemon/daemon diese Regel an, und der laeuft erst beim
# Systemstart. Genau deshalb stand in der plugin.cfg REBOOT=true: ohne
# Neustart gab es keine Regel und damit keinen Lesekopf. Ein erzwungener
# Neustart des ganzen LoxBerry ist dafuer ein hoher Preis - der Miniserver
# verliert waehrenddessen alle Dienste, nicht nur dieses Plugin.
#
# Hier laeuft dasselbe schon als root waehrend der Installation. daemon/daemon
# macht es beim Start weiterhin - schadet nicht und faengt den Fall ab, dass
# jemand die Regeldatei entfernt. REBOOT steht jetzt auf false.
# ---------------------------------------------------------------------------
REGEL=/etc/udev/rules.d/99-smartmeter.rules
echo "<INFO> Lege die udev-Regel fuer die Lesekoepfe an: $REGEL"
{
	echo "# LoxBerry Smartmeter classic - DO NOT EDIT BY HAND!"
	echo "KERNEL==\"ttyUSB[0-9]*\",GROUP=\"loxberry\",MODE=\"0666\",SYMLINK+=\"serial/smartmeter/\$env{ID_SERIAL_SHORT}\""
} > "$REGEL"
if command -v udevadm >/dev/null 2>&1; then
	udevadm control --reload-rules >/dev/null 2>&1
	# trigger, damit ein bereits angesteckter Lesekopf sofort seinen
	# stabilen Namen bekommt - sonst erst beim naechsten Anstecken.
	udevadm trigger --subsystem-match=tty >/dev/null 2>&1
	echo "<OK> udev-Regel aktiv. Ein Neustart ist dafuer nicht noetig."
else
	echo "<WARNING> udevadm nicht gefunden - die Regel greift erst nach einem Neustart."
fi

chmod +x "$PKG" "$CHECK" 2>/dev/null

if [ ! -x "$PKG" ]; then
	# <WARNING>, nicht <ERROR>: der Block unmittelbar darunter behandelt
	# denselben Fall - vzlogger ist nicht einzurichten - bewusst als
	# Warnung und macht weiter, weil die Legacy-Betriebsart kein
	# vzlogger braucht. Ein <ERROR> mit exit 0 daneben war ein
	# Widerspruch in derselben Datei.
	echo "<WARNING> Paket-Helfer fehlt: $PKG"
	echo "<WARNING> vzlogger wird nicht eingerichtet. Die Betriebsart"
	echo "<WARNING> Legacy (Zaehlerprofile) laeuft davon unberuehrt."
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
