#!/bin/bash
sleep 15
# Gruppe 35 (2.8.13, T4-8): Fehlerausgabe ins Systemprotokoll statt ins Nichts (wie cron.01min).
( php REPLACELBHOMEDIR/bin/plugins/REPLACELBPPLUGINDIR/fetch.php 2>&1 >/dev/null | logger -t smartmeter-classic ) &
