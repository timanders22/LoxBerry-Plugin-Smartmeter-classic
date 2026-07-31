#!/bin/bash
#
# vz_check.sh - Kann das mitgelieferte vzlogger auf diesem System laufen?
#
# Aufruf:  vz_check.sh <pfad-zum-binary>
# Ausgabe: Klartextbericht auf stdout
# Rueckgabe: 0 = laeuft, 1 = laeuft nicht
#
# Wird von postinstall.sh und von der Weboberflaeche benutzt, damit beide
# dieselbe Antwort geben.

BIN="$1"
[ -z "$BIN" ] && { echo "Kein Pfad angegeben."; exit 1; }

ARCH="$(uname -m 2>/dev/null)"

if [ ! -e "$BIN" ]; then
	echo "Datei fehlt: $BIN"
	exit 1
fi
if [ ! -x "$BIN" ]; then
	echo "Nicht ausfuehrbar (Dateirechte): $BIN"
	exit 1
fi

# Startversuch - die verlaesslichste Antwort
OUT="$("$BIN" --version 2>&1)"
RC=$?
case "$OUT" in
	*"not found"*|*"No such file"*|*"cannot execute"*|*"Exec format error"*) RC=127 ;;
esac
if [ $RC -eq 0 ]; then
	echo "laeuft: $(echo "$OUT" | head -1)"
	exit 0
fi

# Es laeuft nicht. Jetzt die Ursache benennen.
echo "System-Architektur: $ARCH"
FILEINFO="$(file -L "$BIN" 2>/dev/null | sed 's/^[^:]*: //')"
[ -n "$FILEINFO" ] && echo "Programm: $FILEINFO"

# Interpreter (Lader) pruefen - das ist der haeufigste Grund
INTERP="$(readelf -l "$BIN" 2>/dev/null | sed -n 's/.*interpreter: \(.*\)\]/\1/p' | head -1)"
if [ -n "$INTERP" ]; then
	if [ -e "$INTERP" ]; then
		echo "Lader $INTERP: vorhanden"
	else
		echo "Lader $INTERP: FEHLT - deshalb meldet der Kernel irrefuehrend 'No such file or directory'."
	fi
fi

# Benoetigte Bibliotheken einzeln pruefen
NEEDED="$(readelf -d "$BIN" 2>/dev/null | sed -n 's/.*Shared library: \[\(.*\)\]/\1/p')"
if [ -n "$NEEDED" ]; then
	MISS=""
	HAVE=""
	# Nur im passenden Architekturpfad suchen. Ein 64-Bit-libssl auf dem
	# System hilft einem 32-Bit-ARM-Programm nicht - genau das wuerde
	# "ldconfig -p" aber faelschlich als Treffer melden.
	case "$FILEINFO" in
		*"32-bit"*ARM*) LIBDIRS="/lib/arm-linux-gnueabihf /usr/lib/arm-linux-gnueabihf"; TAG="hard-float" ;;
		*"64-bit"*ARM*) LIBDIRS="/lib/aarch64-linux-gnu /usr/lib/aarch64-linux-gnu"; TAG="AArch64" ;;
		*)              LIBDIRS="/lib /usr/lib";                                       TAG="" ;;
	esac
	for L in $NEEDED; do
		FOUND=""
		for D in $LIBDIRS; do
			[ -e "$D/$L" ] && FOUND="ja"
		done
		if [ -z "$FOUND" ] && [ -n "$TAG" ]; then
			ldconfig -p 2>/dev/null | grep -F "$L" | grep -q "$TAG" && FOUND="ja"
		fi
		if [ -n "$FOUND" ]; then
			HAVE="$HAVE $L"
		else
			MISS="$MISS $L"
		fi
	done
	[ -n "$HAVE" ] && echo "Vorhanden:$HAVE"
	if [ -n "$MISS" ]; then
		echo "FEHLT:$MISS"
		case "$MISS" in
			*libssl.so.1.1*|*libcrypto.so.1.1*)
				echo "Hinweis: libssl/libcrypto 1.1 stammen aus Debian 11. Ab Debian 12 gibt es nur noch OpenSSL 3."
				echo "Das mitgelieferte Programm laesst sich auf diesem System daher nicht nachruesten."
				;;
		esac
	fi
fi

echo "Rueckgabe des Startversuchs: rc=$RC"
[ -n "$OUT" ] && echo "Meldung: $(echo "$OUT" | head -1)"
echo ""
echo "Was jetzt:"
echo " - Die Legacy-Betriebsart braucht kein vzlogger und liest diesen Zaehler weiter."
echo ""
echo " - Wer vzlogger will, installiert ein Paket fuer $ARCH. volkszaehler.org bietet"
echo "   vorkompilierte Pakete fuer Debian/Raspbian ueber Cloudsmith an; die"
echo "   Abhaengigkeiten loest der Paketmanager passend zur eigenen Debian-Fassung auf:"
echo ""
echo "     curl -1sLf https://dl.cloudsmith.io/public/volkszaehler/volkszaehler-org-project/setup.deb.sh | sudo -E bash"
echo "     sudo apt-get update && sudo apt-get install vzlogger"
echo ""
echo "   Hinweis des Wikis: die Zeile mit 'curl | sudo bash' fuehrt ein fremdes Skript"
echo "   mit Root-Rechten aus. Wer das nicht moechte, richtet die Paketquelle nach der"
echo "   Anleitung von Cloudsmith von Hand ein."
echo ""
echo " - WICHTIG danach: das Paket richtet einen eigenen systemd-Dienst ein, der ein"
echo "   zweites vzlogger mit /etc/vzlogger.conf startet. Das wuerde sich mit diesem"
echo "   Plugin um dieselbe serielle Schnittstelle streiten. Deshalb abschalten:"
echo ""
echo "     sudo systemctl disable --now vzlogger"
echo ""
echo "   Das Plugin startet sein vzlogger selbst und findet das Programm dann"
echo "   automatisch - 'command -v vzlogger' wird zuerst geprueft."
exit 1
