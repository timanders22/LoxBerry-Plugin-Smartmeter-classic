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

# ===========================================================================
# Die Verbrauchshistorie und der Abgleich zurueck an ihren Platz. Siehe
# preupgrade.sh.
#
# BERICHTIGT MIT 2.8.2: dieser Block stand HINTER der Pruefung auf die
# gesicherte Konfiguration, und die endet in beiden Zweigen mit exit 0.
# Fehlte die Konfigurations-Sicherung, kam die Historie nicht zurueck: sie
# blieb neben dem Datenordner liegen, und im Datenordner stand, was der
# Minutentakt zwischen Cron-Kopie und postinstall neu angelegt hatte.
# Gemessen am 17.09.2026 (Pruefung-Smartmeter-classic-2.8.2,
# messe_upgrade_sicherung.sh, Faelle S2 und S4). Die Historie haengt nicht an
# der Konfiguration - sie kommt deshalb zuerst, ohne Inhaltspruefung: die
# Sicherung ist die von eben.
# ===========================================================================
HIST_SICHER="$ARGV5/data/plugins/$ARGV3.upgrade_sicherung"
HIST_ZIEL="$ARGV5/data/plugins/$ARGV3"
if [ -d "$HIST_SICHER" ]; then
	mkdir -p "$HIST_ZIEL" 2>/dev/null
	HIST_N=0
	HIST_FEHL=0
	for HIST_F in historie.csv historie_merker.json abgleich.json; do
		if [ -f "$HIST_SICHER/$HIST_F" ]; then
			if cp -p "$HIST_SICHER/$HIST_F" "$HIST_ZIEL/$HIST_F" 2>/dev/null; then
				HIST_N=$((HIST_N + 1))
			else
				HIST_FEHL=$((HIST_FEHL + 1))
				echo "<WARNING> $HIST_F liess sich nicht zurueckspielen."
				echo "<WARNING> Die Sicherung bleibt unter $HIST_SICHER liegen."
			fi
		fi
	done
	if [ "$HIST_N" -gt 0 ]; then
		echo "<OK> Verbrauchshistorie und Abgleich wiederhergestellt ($HIST_N Datei(en))."
	fi

	# -----------------------------------------------------------------------
	# Die Verknuepfung des klassischen Lesers (ERGAENZT MIT 2.8.2).
	#
	# preupgrade.sh hat festgehalten, welche vor dem Upgrade lag; der
	# Installateur hat sie inzwischen geloescht, wenn sie noch wie das Plugin
	# hiess. Angelegt wird sie unter dem Namen, den auch der Reiter Legacy
	# vergibt: <SCRIPTNAME>-legacy. SCRIPTNAME setzt postinstall.sh aus $2,
	# deshalb steht hier $ARGV2. Was vorher nicht lag, wird nicht angelegt -
	# auch dann nicht, wenn die Einstellung "an" sagt.
	# -----------------------------------------------------------------------
	SM_VERWEISE="$HIST_SICHER/leser_verweise"
	if [ -f "$SM_VERWEISE" ] && [ -n "$ARGV2" ]; then
		while read -r SM_ORDNER SM_ZIELNAME; do
			case "$SM_ORDNER" in
				cron.reboot|cron.01min|cron.03min|cron.05min|cron.10min|cron.15min|cron.30min|cron.hourly) ;;
				*) continue ;;
			esac
			case "$SM_ZIELNAME" in
				fetch.php|reboot_cron_runner.sh) ;;
				*) continue ;;
			esac
			SM_QUELLE="$ARGV5/bin/plugins/$ARGV3/$SM_ZIELNAME"
			SM_LINK="$ARGV5/system/cron/$SM_ORDNER/$ARGV2-legacy"
			SM_IST=""
			[ -L "$SM_LINK" ] && SM_IST=$(readlink "$SM_LINK" 2>/dev/null)
			case "$SM_IST" in
				*/bin/plugins/"$ARGV3"/"$SM_ZIELNAME")
					echo "<OK> Verknuepfung des klassischen Lesers liegt: $SM_LINK"
					continue
					;;
			esac
			if [ -e "$SM_LINK" ] || [ -L "$SM_LINK" ]; then
				HIST_FEHL=$((HIST_FEHL + 1))
				echo "<WARNING> $SM_LINK ist belegt - die Verknuepfung des klassischen"
				echo "<WARNING> Lesers wurde NICHT angelegt. Bitte im Reiter Legacy einmal speichern."
			elif ln -s "$SM_QUELLE" "$SM_LINK" 2>/dev/null && [ -L "$SM_LINK" ]; then
				echo "<OK> Verknuepfung des klassischen Lesers wiederhergestellt: $SM_LINK"
			else
				HIST_FEHL=$((HIST_FEHL + 1))
				echo "<WARNING> Die Verknuepfung $SM_LINK liess sich nicht anlegen."
				echo "<WARNING> Der klassische Leser fragt nichts ab. Bitte im Reiter Legacy einmal speichern."
			fi
		done < "$SM_VERWEISE"
	fi

	# Nur raeumen, wenn wirklich alles zurueckgespielt wurde - eine
	# geloeschte Sicherung nach einem halben Lauf waere endgueltig.
	if [ "$HIST_FEHL" -eq 0 ]; then
		rm -rf "$HIST_SICHER" 2>/dev/null
	fi
fi

# ===========================================================================
# TRAEGT DIE VORHANDENE KONFIGURATION EIGENE WERTE?
#
# BERICHTIGT MIT 2.8.2, zweite Runde. Hier stand bis dahin die Frage, ob in
# der Datei noch der Platzhalter REPLACEBYSUBFOLDER steht. Sie kann nie mit
# JA beantwortet werden: postinstall.sh laeuft VOR diesem Skript und ersetzt
# genau diesen Platzhalter (postinstall.sh, Abschnitt "Platzhalter
# ersetzen"). Der WARNING-Zweig darunter war damit praktisch unerreichbar,
# und bei fehlender Sicherung meldete das Skript "traegt aber eigene
# Einstellungen" - auch dann, wenn die Datei zeichengleich die
# Werkseinstellung war und alles verloren war. Gemessen am 17.09.2026
# (Pruefung-Smartmeter-classic-2.8.2, messe_nebenbefunde.sh, Fall P1a:
# "postupgrade sagt: eigen / gemessen ist: werk -> STIMMT NICHT").
#
# Gefragt wird jetzt nach dem INHALT. Die mitgelieferte Vorgabe liegt im
# Arbeitsordner des Installers noch unveraendert daneben; auf sie werden
# dieselben zwei Ersetzungen angewandt, die postinstall.sh vornimmt. Ist die
# Datei danach zeichengleich, traegt sie nichts Eigenes. KEINE Pruefsumme im
# Quelltext - die wird beim naechsten Zeichen in der Vorgabedatei still
# falsch (die Lehre aus postinstall.sh, 26.08.2026).
#
# Vier Ausgaenge, nicht zwei. "Konnte nicht geprueft werden" ist etwas
# anderes als "in Ordnung" (CLAUDE.md, Abschnitt 6):
#   0 = Werkseinstellung, nichts Eigenes
#   1 = eigene Werte
#   2 = nicht messbar (kein Arbeitsordner, keine Vorgabe, kein cmp)
#   3 = die Datei fehlt oder ist leer
# ===========================================================================
sm_traegt_eigenes() {
	SM_IST="$ARGV5/config/plugins/$ARGV3/smartmeter.cfg"
	SM_SOLL="$ARGV6/config/smartmeter.cfg"
	[ -f "$SM_IST" ] && [ -s "$SM_IST" ] || return 3
	[ -n "$ARGV6" ] && [ -f "$SM_SOLL" ] || return 2
	command -v cmp >/dev/null 2>&1 || return 2
	# Der Rueckgabewert einer Pipe ist der ihres LETZTEN Gliedes - hier ist
	# genau der gemeint (Regeln/01, "Rueckgabewert hinter Pipe").
	if sed -e "s#REPLACEBYSUBFOLDER#$ARGV3#" -e "s#REPLACEBYNAME#$ARGV2#" "$SM_SOLL" \
	   | cmp -s - "$SM_IST"; then
		return 0
	fi
	return 1
}

# Nicht nur das Verzeichnis: preupgrade.sh legt es mit mkdir -p an,
# BEVOR kopiert wird. Ein leeres Verzeichnis ist keine Sicherung.
#
# Hier steht seit 2.8.2 (zweite Runde) KEIN exit mehr. Die beiden exit 0 in
# diesem Block uebersprangen das Aufraeumen des /tmp-Rueckfallweges ganz
# unten - gemessen am 17.09.2026 (messe_nebenbefunde.sh, Fall P2:
# "/tmp/<kennung>_upgrade nach postupgrade: LIEGT NOCH (2 Eintraege)").
# Es gibt jetzt genau einen Weg durch den Rest der Datei.
if [ ! -s "$SICHERUNG/config/smartmeter.cfg" ]; then
	sm_traegt_eigenes
	SM_LAGE=$?
	case "$SM_LAGE" in
		1)
			echo "<INFO> Keine gesicherte Konfiguration unter $SICHERUNG - die"
			echo "<INFO> vorhandene smartmeter.cfg traegt aber eigene Werte."
			echo "<INFO> Es ist nichts zurueckzuspielen."
			;;
		0)
			echo "<WARNING> Keine gesicherte Konfiguration unter $SICHERUNG gefunden,"
			echo "<WARNING> und die vorhandene smartmeter.cfg ist Zeichen fuer Zeichen"
			echo "<WARNING> die mitgelieferte Werkseinstellung: Zaehlerprofile, Takt,"
			echo "<WARNING> Zugriffstoken und Lesekopf-Bezeichnungen sind verloren."
			echo "<WARNING> Bitte im Reiter Smartmeter (klassisch) neu eintragen."
			;;
		3)
			echo "<ERROR> Keine gesicherte Konfiguration unter $SICHERUNG gefunden,"
			echo "<ERROR> und $ARGV5/config/plugins/$ARGV3/smartmeter.cfg fehlt oder"
			echo "<ERROR> ist leer. Das Plugin hat keine Einstellungen."
			;;
		*)
			echo "<WARNING> Keine gesicherte Konfiguration unter $SICHERUNG gefunden."
			echo "<WARNING> Ob die vorhandene smartmeter.cfg eigene Werte traegt, liess"
			echo "<WARNING> sich NICHT pruefen - die mitgelieferte Vorgabe steht nicht"
			echo "<WARNING> zum Vergleich. Bitte die Einstellungen im Reiter Smartmeter"
			echo "<WARNING> (klassisch) einmal nachsehen."
			;;
	esac
else
	echo "<INFO> Spiele gesicherte Konfiguration zurueck aus $SICHERUNG"
	# -a erhaelt Rechte und Zeitstempel; der Punkt am Ende kopiert auch
	# Dateien, deren Name mit einem Punkt beginnt.
	if cp -a "$SICHERUNG/config/." "$ARGV5/config/plugins/$ARGV3/"; then
		echo "<OK> Konfiguration wiederhergestellt."
	else
		# Bis 2.4.2 hatte diese Stelle keinen else-Zweig: scheiterte das
		# Kopieren, erschien KEINE Zeile, und das Skript endete mit 0.
		echo "<WARNING> Die Konfiguration liess sich nicht zurueckspielen."
		echo "<WARNING> Die Sicherung liegt unter $SICHERUNG/config."
		echo "<WARNING> Bitte die Einstellungen im Reiter Smartmeter"
		echo "<WARNING> (klassisch) nachsehen."
	fi
fi

# ---------------------------------------------------------------------------
# DIE ALTE cron.d-DATEI WIRD HIER NICHT MEHR ENTFERNT - sie kann es nicht.
#
# In 2.7.1 stand der Versuch an dieser Stelle. Am 07.09.2026 an der laufenden
# Anlage nachgemessen, und er kann dort nicht gelingen:
#
#   plugininstall.pl ruft postupgrade mit "sudo -n -u loxberry" auf
#   /opt/loxberry/system/cron/cron.d ist drwxrwxr-x root root
#   loxberry ist NICHT in der Gruppe root (id loxberry)
#   sudo -u loxberry touch .../cron.d/.probe  ->  Permission denied, rc=1
#
# Wer im Verzeichnis nicht schreiben darf, kann darin auch nichts loeschen -
# das haengt am VERZEICHNIS, nicht an der Datei. Der Versuch waere also
# jedesmal in den WARNING-Zweig gelaufen, und die Auftraege blieben doppelt.
#
# Er steht jetzt in postroot.sh: das laeuft als root (plugininstall.pl ruft
# es OHNE sudo-Praefix auf) und danach - die Reihenfolge ist Cron-Kopieren,
# postinstall, postupgrade, postroot.
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# Liegengebliebene Sicherungen aus frueheren, nicht abgeschlossenen Upgrades
# (ERGAENZT MIT 2.8.2, zweite Runde). preupgrade.sh legt sie beiseite, statt
# sie zu ueberschreiben; hier stehen sie noch einmal am Ende des Protokolls,
# wo der Anwender nach einem Update hinsieht. Entfernt werden sie nicht - es
# ist seine Verbrauchshistorie.
# ---------------------------------------------------------------------------
SM_LIEGEN=0
for SM_L in "$ARGV5/data/plugins/$ARGV3".upgrade_sicherung.liegengeblieben-*; do
	[ -d "$SM_L" ] || continue
	SM_LIEGEN=$((SM_LIEGEN + 1))
	echo "<WARNING> Aus einem frueheren, nicht abgeschlossenen Upgrade liegt noch:"
	echo "<WARNING> $SM_L"
done
if [ "$SM_LIEGEN" -gt 0 ]; then
	echo "<WARNING> Darin steht eine aeltere Verbrauchshistorie. Bitte ansehen und"
	echo "<WARNING> den Ordner danach von Hand entfernen."
fi

# Der Arbeitsordner des Installers wird von LoxBerry selbst aufgeraeumt.
# Nur der Rueckfallweg unter /tmp gehoert uns. Diese Zeilen werden seit
# 2.8.2 (zweite Runde) in JEDEM Fall erreicht - siehe oben.
case "$SICHERUNG" in
	/tmp/*) rm -rf "$SICHERUNG" ;;
esac

exit 0
