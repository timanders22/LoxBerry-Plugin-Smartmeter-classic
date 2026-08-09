#!/bin/bash

# Installiert und aktualisiert vzlogger aus der Cloudsmith-Paketquelle von
# volkszaehler.org.
#
#   vzlogger_pkg.sh current     installierte Fassung (leer, wenn nicht da)
#   vzlogger_pkg.sh available   neueste Fassung der Paketquelle
#   vzlogger_pkg.sh repo        Schluessel und apt-Quelle schreiben (root)
#   vzlogger_pkg.sh install     vzlogger installieren (root)
#
# Herkunft: das Verfahren ist dem Plugin Smartmeter-NG von Michael Schlenstedt
# entnommen (bin/vzlogger_pkg.sh, Fassung V2.0) und fuer dieses Plugin
# angepasst. Der Grund fuer die Uebernahme steht in NOTICE und in README.md.
#
# Das Paket bringt einen eigenen systemd-Dienst mit und startet ihn im
# postinst. Dieses Plugin startet vzlogger selbst, deshalb wird der Dienst
# waehrend der Installation am Starten gehindert und danach abgeschaltet und
# maskiert. Das muss nach jeder Paketaktualisierung wiederholt werden, weil
# das postinst die Maskierung wieder aufhebt.

set -u

ACTION="${1:-}"

# Pfade aus dem eigenen Ort ableiten - unter sudo fehlt die LoxBerry-Umgebung.
SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
PLUGINNAME="$(basename "$SCRIPT_DIR")"
LBHOMEDIR="${SCRIPT_DIR%/bin/plugins/*}"

KEYRING="/usr/share/keyrings/volkszaehler-volkszaehler-org-project-archive-keyring.gpg"
SOURCE_LIST="/etc/apt/sources.list.d/volkszaehler-volkszaehler-org-project.list"
REPO_BASE="https://dl.cloudsmith.io/public/volkszaehler/volkszaehler-org-project"
KEY_URL="$REPO_BASE/gpg.21DBDAC56DF44DA1.key"
POLICY_FILE="/usr/sbin/policy-rc.d"
POLICY_BACKUP="/usr/sbin/policy-rc.d.smartmeter"
MARKER="$LBHOMEDIR/config/plugins/$PLUGINNAME/vzlogger.installed-by-plugin"

installed_version()
{
	dpkg-query -W -f='${Version}' vzlogger 2>/dev/null | grep -v '^$' || true
}

available_version()
{
	apt-cache policy vzlogger 2>/dev/null | awk '/Candidate:/ {print $2}' | grep -v '(none)' || true
}

require_root()
{
	if [ "$(id -u)" != "0" ]; then
		echo "<ERROR> Diese Aktion muss als root laufen."
		exit 1
	fi
}

# Schluessel und apt-Quelle schreiben. Laeuft bei jeder Installation neu,
# damit ein gewechselter Schluessel das Plugin nicht aussperrt.
configure_repository()
{
	require_root

	if ! command -v curl >/dev/null 2>&1 || ! command -v gpg >/dev/null 2>&1; then
		echo "<INFO> Installiere Hilfspakete (ca-certificates, curl, gnupg)"
		apt-get update
		apt-get install -y --no-install-recommends ca-certificates curl gnupg
	fi

	ID="debian"; CODENAME=""
	if [ -r /etc/os-release ]; then
		. /etc/os-release
		CODENAME="${VERSION_CODENAME:-}"
	fi
	[ -z "$CODENAME" ] && CODENAME="$(lsb_release -cs 2>/dev/null || echo bookworm)"
	REPO_OS="debian"
	[ "${ID:-debian}" = "raspbian" ] && REPO_OS="raspbian"

	tmpkey="$(mktemp)"
	trap 'rm -f "$tmpkey"' EXIT
	echo "<INFO> Hole den Schluessel der Paketquelle"
	if ! curl -fsSL "$KEY_URL" -o "$tmpkey"; then
		echo "<ERROR> Schluessel nicht abrufbar: $KEY_URL"
		exit 3
	fi
	if ! gpg --dearmor <"$tmpkey" >"$KEYRING.new"; then
		echo "<ERROR> Schluessel liess sich nicht umwandeln"
		rm -f "$KEYRING.new"
		exit 3
	fi
	mv "$KEYRING.new" "$KEYRING"
	chmod 0644 "$KEYRING"

	# Kein deb-src: dieses Plugin baut nichts aus Quellen.
	echo "<INFO> Trage die apt-Quelle ein ($REPO_OS $CODENAME)"
	printf 'deb [signed-by=%s] %s/deb/%s %s main\n' "$KEYRING" "$REPO_BASE" "$REPO_OS" "$CODENAME" >"$SOURCE_LIST"
	chmod 0644 "$SOURCE_LIST"
}

block_service_start()
{
	[ -e "$POLICY_FILE" ] && mv "$POLICY_FILE" "$POLICY_BACKUP"
	printf '#!/bin/sh\nexit 101\n' >"$POLICY_FILE"
	chmod 0755 "$POLICY_FILE"
}

unblock_service_start()
{
	rm -f "$POLICY_FILE"
	[ -e "$POLICY_BACKUP" ] && mv "$POLICY_BACKUP" "$POLICY_FILE"
	return 0
}

# Der mitgelieferte Dienst darf nicht laufen - sonst streiten sich zwei
# vzlogger um dieselbe serielle Schnittstelle.
disable_packaged_service()
{
	if command -v systemctl >/dev/null 2>&1; then
		systemctl stop vzlogger.service >/dev/null 2>&1 || true
		systemctl disable vzlogger.service >/dev/null 2>&1 || true
		systemctl mask vzlogger.service >/dev/null 2>&1 || true
		systemctl reset-failed vzlogger.service >/dev/null 2>&1 || true
	fi
	if command -v update-rc.d >/dev/null 2>&1 && [ -e /etc/init.d/vzlogger ]; then
		update-rc.d vzlogger disable >/dev/null 2>&1 || true
	fi
	echo "<INFO> Der Dienst des Pakets ist abgeschaltet - das Plugin startet vzlogger selbst."
}

install_package()
{
	require_root

	# Merker: war vzlogger schon vorher da, wird es beim Deinstallieren
	# des Plugins nicht angefasst.
	mkdir -p "$(dirname "$MARKER")"
	if dpkg-query -W -f='${Status}' vzlogger 2>/dev/null | grep -q "install ok installed"; then
		echo "<INFO> vzlogger war bereits installiert - es bleibt beim Deinstallieren erhalten."
	else
		touch "$MARKER"
	fi

	configure_repository
	echo "<INFO> Aktualisiere die Paketlisten"
	apt-get update

	oldversion="$(installed_version)"
	block_service_start
	echo "<INFO> Installiere vzlogger"
	if ! DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends vzlogger; then
		unblock_service_start
		echo "<ERROR> vzlogger liess sich nicht installieren"
		exit 4
	fi
	unblock_service_start
	disable_packaged_service

	newversion="$(installed_version)"
	if [ -z "$newversion" ]; then
		echo "<ERROR> vzlogger wurde installiert, ist aber nicht auffindbar"
		exit 4
	fi
	if [ -n "$oldversion" ] && [ "$oldversion" = "$newversion" ]; then
		echo "<OK> vzlogger $newversion ist bereits aktuell"
	else
		echo "<OK> vzlogger $newversion installiert"
	fi
}

case "$ACTION" in
	current)   installed_version ;;
	available) available_version ;;
	repo)      configure_repository; echo "<OK> Paketquelle eingerichtet" ;;
	install)   install_package ;;
	*)         echo "Aufruf: $0 current|available|repo|install"; exit 2 ;;
esac
exit 0
