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
# BERICHTIGT AM 26.08.2026. Hier stand: "LoxBerry loescht den Konfigordner
# beim Upgrade NICHT - es kopiert aber die mitgelieferten Dateien darueber."
# Der zweite Halbsatz stimmt, der erste nicht.
#
# Nachgemessen an sbin/plugininstall.pl, Zweig master, Commit 666baf1de87a
# vom 21.08.2026 (die Fundstellen stehen in REGELN_2 unter "Was der
# Installateur beim Upgrade wirklich tut"):
#
#     :858    if ($isupgrade) {
#     :886        &purge_installation;      <- IM Upgrade-Zweig
#     :1629/:1631 rm -rf auf config/plugins/<ordner>/ UND data/plugins/<ordner>/,
#                 im Rumpf, ohne Pruefung auf $option eq "all"
#     :916/:920   ERST DANACH wird config/plugins/<ordner>/ neu angelegt und
#                 der Archivinhalt hineinkopiert
#
# Beim Upgrade ueberlebt in config/ und data/ also NICHTS. Fuer diese
# Sicherung aendert das nichts - sie liegt ohnehin ausserhalb -, wohl aber
# fuer alles, was jemand kuenftig IN den Ordner legen wollte.
#
# Es gibt genau ein Rettungsfenster und genau ein Rueckgabefenster:
# preupgrade.sh laeuft vor :886 und ist die letzte Gelegenheit, etwas
# herauszutragen; postinstall.sh laeuft nach dem Kopieren und ist die erste,
# es zurueckzulegen. postupgrade.sh ist dafuer eine Stufe spaeter.
#
# Dieses Plugin liefert config/smartmeter.cfg mit. Ohne diese Sicherung
# waeren nach jedem Upgrade Zaehlerprofile, Takt, Zugriffstoken und
# Lesekopf-Bezeichnungen auf Werkseinstellung zurueck. Die Reihenfolge im
# Installateur: preupgrade -> abraeumen -> Konfig kopieren -> postinstall
# -> postupgrade.
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
# Hier stand bis 2.3.14 ein Merker .upgrade_pfad IM Konfigurationsordner,
# den postupgrade.sh lesen sollte. Er kann dort nie ankommen:
# purge_installation entfernt genau dieses Verzeichnis, bevor postupgrade
# laeuft (siehe oben). Der Merker war also eine falsche Faehrte - beide
# Skripte rechnen den Pfad ohnehin aus DEMSELBEN Argument aus, und das ist
# die eine Stelle, an der sie nicht auseinanderlaufen koennen.

echo "<INFO> Sicherungsordner: $SICHERUNG"
mkdir -p "$SICHERUNG/config"

# ===========================================================================
# DIE VERBRAUCHSHISTORIE
#
# data/plugins/<ordner>/ wird bei JEDEM Upgrade abgeraeumt - purge_installation
# in plugininstall.pl macht das, und zwar bevor postupgrade.sh laeuft. Eine
# Historie ueber zwei Jahre waere danach weg, und niemand koennte sie neu
# bilden: sie entsteht nur, indem der Zaehler laeuft.
#
# Gesichert wird deshalb NEBEN den Datenordner, nicht hinein. Dieselbe
# Bauform wie im Spotpreis-Plugin fuer dessen history.csv.
#
# abgleich.json (ERGAENZT MIT 2.8.2) traegt fuer jede gerade laufende Regel
# den Startpunkt - Zeitpunkt und Zaehlerstand beim Einschalten
# (bin/sm_abgleich.php) - und fuer beendete Regeln das letzte Urteil. Beides
# entsteht nicht aus Messwerten neu: ohne die Datei beginnt eine laufende
# Beobachtung von vorn, und die Themen abgleich/<n>/soll, ist, fehlt stehen
# auf 0 und ok auf -1. Gemessen am 17.09.2026: die Datei fehlte nach jedem
# Upgrade (Pruefung-Smartmeter-classic-2.8.2, messe_upgrade_sicherung.sh).
#
# kosten.json wird NICHT gesichert: bin/sm_kosten.php rechnet sie bei
# jedem Lauf aus historie.csv und den Preisen des Tages ganz neu. Aus der
# alten Datei nimmt es nur dann etwas, wenn die Preisquelle nicht antwortet -
# und dann waere ein Stand von vor dem Update keine bessere Aussage als
# "unbekannt".
# ===========================================================================
HIST_QUELLE="$ARGV5/data/plugins/$ARGV3"
HIST_SICHER="$ARGV5/data/plugins/$ARGV3.upgrade_sicherung"

# ---------------------------------------------------------------------------
# EINE LIEGENGEBLIEBENE SICHERUNG WIRD NICHT UEBERSCHRIEBEN (ERGAENZT MIT
# 2.8.2, zweite Runde)
#
# postupgrade.sh raeumt diesen Ordner nur weg, wenn wirklich alles
# zurueckgespielt wurde. Blieb er nach einem missglueckten Upgrade liegen,
# schrieb der naechste Lauf hier den DANN vorhandenen Stand darueber - und
# das ist nach einem missglueckten Upgrade gerade nicht der gute: im
# Datenordner steht dann, was der Takt in der Luecke neu angelegt hat.
# Gemessen am 17.09.2026 (Pruefung-Smartmeter-classic-2.8.2,
# messe_nebenbefunde.sh, Fall P4a: der Merkinhalt MERKALT war nach dem Lauf
# nirgends mehr zu finden).
#
# Die alte Sicherung wird deshalb mit Zeitstempel beiseite gelegt und
# gemeldet; entfernt wird sie nie - es ist die Verbrauchshistorie des
# Anwenders. Laesst sie sich nicht verschieben, wird in diesem Lauf gar
# nichts gesichert: die aeltere, vollstaendigere Sicherung hat Vorrang vor
# dem duennen Stand von jetzt, und postupgrade.sh spielt sie zurueck.
# ---------------------------------------------------------------------------
SM_SICH_OK=1
if [ -d "$HIST_SICHER" ] && [ -n "$(ls -A "$HIST_SICHER" 2>/dev/null)" ]; then
	SM_BEISEITE="$HIST_SICHER.liegengeblieben-$(date '+%Y%m%d-%H%M%S' 2>/dev/null)"
	if [ ! -e "$SM_BEISEITE" ] && mv "$HIST_SICHER" "$SM_BEISEITE" 2>/dev/null; then
		echo "<WARNING> Es lag noch eine Sicherung aus einem frueheren, nicht"
		echo "<WARNING> abgeschlossenen Upgrade. Sie wird NICHT ueberschrieben,"
		echo "<WARNING> sondern beiseite gelegt: $SM_BEISEITE"
		echo "<WARNING> Bitte hineinsehen und den Ordner danach von Hand entfernen."
	else
		SM_SICH_OK=0
		echo "<WARNING> Unter $HIST_SICHER liegt eine Sicherung aus einem frueheren,"
		echo "<WARNING> nicht abgeschlossenen Upgrade, und sie liess sich nicht"
		echo "<WARNING> beiseite legen. Sie wird nicht ueberschrieben; in diesem Lauf"
		echo "<WARNING> wird nichts dazu gesichert. postupgrade.sh spielt danach den"
		echo "<WARNING> AELTEREN Stand zurueck."
	fi
fi

if [ "$SM_SICH_OK" = "1" ]; then
	mkdir -p "$HIST_SICHER" 2>/dev/null
	chmod 0700 "$HIST_SICHER" 2>/dev/null
	HIST_N=0
	for HIST_F in historie.csv historie_merker.json abgleich.json; do
		if [ -f "$HIST_QUELLE/$HIST_F" ]; then
			if cp -p "$HIST_QUELLE/$HIST_F" "$HIST_SICHER/$HIST_F" 2>/dev/null; then
				HIST_N=$((HIST_N + 1))
			else
				echo "<WARNING> $HIST_F liess sich nicht sichern - die Historie geht beim Update verloren."
			fi
		fi
	done
	if [ "$HIST_N" -gt 0 ]; then
		echo "<INFO> Verbrauchshistorie und Abgleich gesichert ($HIST_N Datei(en)) nach $HIST_SICHER"
	fi
fi

# ===========================================================================
# DIE VERKNUEPFUNG DES KLASSISCHEN LESERS (ERGAENZT MIT 2.8.2)
#
# Der Reiter Legacy legt fuer den eingestellten Takt eine Verknuepfung in
# system/cron/<cron.X>/ an, die auf bin/fetch.php oder
# bin/reboot_cron_runner.sh zeigt. Bis 2.8.1 hiess sie wie das Plugin - und
# purge_installation loescht beim Upgrade in allen Takt-Ordnern genau diesen
# Namen (plugininstall.pl :1554). Gemessen am 17.09.2026: nach jedem Upgrade
# war der klassische Leser still, die Einstellung sagte weiter "an"
# (Pruefung-Smartmeter-classic-2.8.2, messe_cron_kollision.sh, U1/U5).
#
# Hier wird nur FESTGEHALTEN, welche Verknuepfung jetzt liegt - erkannt am
# Ziel, nicht am Namen, damit auch die unter dem alten Namen zaehlt.
# postupgrade.sh legt genau diese unter dem neuen Namen <NAME>-legacy wieder
# an. Was vorher nicht lag, wird danach nicht eingeschaltet.
# ===========================================================================
#
# Geschrieben wird nur in eine Sicherung, die zu diesem Lauf gehoert. Liegt
# eine aeltere fest (siehe oben, SM_SICH_OK=0), bleibt sie unangetastet -
# auch mit dieser Zeile.
SM_VERWEISE="$HIST_SICHER/leser_verweise"
SM_VN=0
if [ "$SM_SICH_OK" = "1" ] && [ -n "$ARGV3" ] && [ -n "$ARGV5" ] && [ -d "$HIST_SICHER" ]; then
	rm -f "$SM_VERWEISE" 2>/dev/null
	for SM_ORDNER in cron.reboot cron.01min cron.03min cron.05min cron.10min cron.15min cron.30min cron.hourly; do
		for SM_E in "$ARGV5/system/cron/$SM_ORDNER"/*; do
			[ -L "$SM_E" ] || continue
			SM_ZIEL=$(readlink "$SM_E" 2>/dev/null)
			case "$SM_ZIEL" in
				*/bin/plugins/"$ARGV3"/fetch.php|*/bin/plugins/"$ARGV3"/reboot_cron_runner.sh)
					echo "$SM_ORDNER ${SM_ZIEL##*/}" >> "$SM_VERWEISE"
					SM_VN=$((SM_VN + 1))
					;;
			esac
		done
	done
fi
if [ "$SM_VN" -gt 0 ]; then
	echo "<INFO> Verknuepfung des klassischen Lesers festgehalten ($SM_VN): $(tr '\n' ' ' < "$SM_VERWEISE")"
fi

# ===========================================================================
# DER MERKER "vzlogger hat dieses Plugin installiert" (ERGAENZT MIT 2.8.2,
# zweite Runde)
#
# bin/vzlogger_pkg.sh legte ihn bis 2.8.1 unter
# config/plugins/<ordner>/vzlogger.installed-by-plugin ab - also IN dem
# Verzeichnis, das purge_installation bei jedem Upgrade abraeumt
# (plugininstall.pl :1629/:1631, Regeln/06). Zurueck kam er nur mit der
# Konfigurations-Sicherung; fehlte die, war er weg, und uninstall liess
# vzlogger und die fremde Paketquelle stehen, obwohl das Plugin sie
# installiert hatte. Gemessen am 17.09.2026
# (Pruefung-Smartmeter-classic-2.8.2, messe_nebenbefunde.sh, Fall P3a:
# "Merker alte Stelle: weg ... vzlogger-Paket nach uninstall: LIEGT NOCH").
#
# Seit 2.8.2 liegt er als Nachbar mit Punkt NEBEN dem Datenordner. Diese
# Zeilen holen ihn einmalig von der alten Stelle herueber - es ist das
# einzige Fenster dafuer, denn gleich danach ist das Verzeichnis fort.
# uninstall liest beide Stellen.
# ===========================================================================
SM_MERK_ALT="$ARGV5/config/plugins/$ARGV3/vzlogger.installed-by-plugin"
SM_MERK_NEU="$ARGV5/data/plugins/$ARGV3.vzlogger-installiert"
if [ -e "$SM_MERK_ALT" ] && [ ! -e "$SM_MERK_NEU" ]; then
	if touch "$SM_MERK_NEU" 2>/dev/null && [ -e "$SM_MERK_NEU" ]; then
		echo "<INFO> Merker uebernommen: vzlogger hat dieses Plugin installiert"
		echo "<INFO> ($SM_MERK_NEU). Beim Deinstallieren wird es wieder entfernt."
	else
		echo "<WARNING> Der Merker, dass dieses Plugin vzlogger installiert hat,"
		echo "<WARNING> liess sich nicht nach $SM_MERK_NEU uebernehmen."
		echo "<WARNING> Beim Deinstallieren bliebe vzlogger dann stehen."
	fi
fi

echo "<INFO> Backing up existing config files"
# Den Rueckgabewert ansehen. Bis 2.4.2 stand hier "|| true", und
# postupgrade.sh prueft danach nur, ob $SICHERUNG/config als VERZEICHNIS
# existiert - das tut es nach dem mkdir immer. Eine gescheiterte
# Sicherung wurde damit als geglueckte Rueckspielung gemeldet.
#
# ERGAENZT MIT 2.8.2, zweite Runde: der Rueckgabewert allein reicht nicht.
# Ist der Konfigurationsordner da, aber leer, glueckt "cp -a" und meldete
# bis dahin "<OK> Konfiguration gesichert" - gesichert war nichts. Gemessen
# am 17.09.2026 (messe_nebenbefunde.sh, Fall P1c). Gemeldet wird jetzt, was
# danach wirklich in der Sicherung liegt.
if cp -a "$ARGV5/config/plugins/$ARGV3/." "$SICHERUNG/config/"; then
	if [ -s "$SICHERUNG/config/smartmeter.cfg" ]; then
		echo "<OK> Konfiguration gesichert nach $SICHERUNG/config"
	else
		echo "<WARNING> Das Kopieren glueckte, aber in $SICHERUNG/config liegt"
		echo "<WARNING> keine smartmeter.cfg mit Inhalt - gesichert ist nichts."
		echo "<WARNING> Nach dem Update bitte die Einstellungen im Reiter"
		echo "<WARNING> Smartmeter (klassisch) nachsehen."
	fi
else
	echo "<WARNING> Die Konfiguration liess sich NICHT sichern."
	echo "<WARNING> Nach dem Update bitte die Einstellungen im Reiter"
	echo "<WARNING> Smartmeter (klassisch) nachsehen."
fi

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


# NICHT MITGELIEFERTE Dateien - und gerade deshalb die wichtigen.
# Das Archiv liefert sie nie, also standen sie bis jetzt auf keiner Liste;
# geloescht werden sie vom Installer trotzdem, samt Token und Zugangsdaten.
if [ -s "$NETZ_CFG/vzlogger.json" ]; then
    cp -p "$NETZ_CFG/vzlogger.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.vzlogger.json" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.vzlogger.json" 2>/dev/null
fi
if [ -s "$NETZ_CFG/vzlogger.conf" ]; then
    cp -p "$NETZ_CFG/vzlogger.conf" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.vzlogger.conf" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.vzlogger.conf" 2>/dev/null
fi

exit 0
