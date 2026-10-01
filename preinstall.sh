#!/bin/sh

# Bash script which is executed by bash *BEFORE* installation is started (but
# *AFTER* preupdate). Use with caution and remember, that all systems may be
# different! Better to do this in your own Pluginscript if possible.
#
# Exit code must be 0 if executed successfull.
#
# Will be executed as user "loxberry".
#
# We add 4 arguments when executing the script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

ARGV1=$1 # First argument is temp folder during install
ARGV2=$2 # Second argument is Plugin-Name for scipts etc.
ARGV3=$3 # Third argument is Plugin installation folder
ARGV4=$4 # Forth argument is Plugin version
ARGV5=$5 # Fifth argument is Base folder of LoxBerry

# ===========================================================================
# NEUINSTALLATION: LIEGENGEBLIEBENES BEISEITELEGEN (Durchgang 01.10.2026, I1;
# Entscheidung 1 des Hausherrn vom 29.09.2026)
#
# Bis 2.8.5 tat diese Datei nichts, und postinstall.sh spielte die
# Zweitschriften neben dem Konfigurationsordner bei JEDER Installation
# zurueck - auch bei einer Neuinstallation: Token, Formularmerkmal,
# Leser-Einstellungen und ein eingeschalteter vzLogger-Weg einer frueheren
# Installation kamen still zurueck (gemessen, Installerbericht I1, F1).
# Liegen bleiben sie nach einer Deinstallation mit 2.4.1 oder aelter, nach
# einer abgebrochenen Deinstallation oder nach Loeschen von Hand.
#
# Ohne Upgrade-Marke (data/plugins/<ordner>.upgrade_laeuft, angelegt von
# preupgrade.sh; KEIN Altersvergleich) ist es eine Neuinstallation: die drei
# Zweitschriften und die Upgrade-Sicherung gehen nach <name>.alt, EINE
# <WARNING> nennt die Pfade. Eingespielt wird daraus nie; uninstall raeumt
# die .alt mit ab. Bauform: Abfahrtsassistent 1.6.19.
# ===========================================================================
PFOLDER="${ARGV3:-smartmeter-classic}"
BASE="${ARGV5:-$LBHOMEDIR}"

if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

# Aktualisierung: preupgrade.sh hat die Marke gelegt - dann gehoert alles
# Liegende zu DIESEM Vorgang und wird spaeter zurueckgespielt.
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.smartmeter.cfg" \
            "$BASE/config/plugins/$PFOLDER.backup.vzlogger.json" \
            "$BASE/config/plugins/$PFOLDER.backup.vzlogger.conf" \
            "$BASE/data/plugins/$PFOLDER.upgrade_sicherung"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi

# Exit with Status 0
exit 0
