#!/bin/bash
ARGV3=$3
ARGV5=$5
# Der Sicherungsordner liegt unter data/, NICHT unter /tmp.
#
# /tmp ist auf dem LoxBerry eine Ramdisk: bricht die Installation ab oder
# startet der Rechner dazwischen neu, ist die Sicherung weg. Schwerer wiegt,
# dass /tmp fuer jeden lesbar ist - in signalbot.json stehen das Zugriffstoken
# und die Rufnummern. Geaendert am 10.08.2026.
SICHER="$ARGV5/data/plugins/$ARGV3/upgrade_sicherung"
rm -rf "$SICHER" 2>/dev/null
mkdir -p "$SICHER"
chmod 0700 "$SICHER" 2>/dev/null
if [ -d "$ARGV5/config/plugins/$ARGV3" ]; then
    cp -a "$ARGV5/config/plugins/$ARGV3/." "$SICHER/" 2>/dev/null
    echo "<OK> Konfiguration gesichert nach $SICHER (Rechte 0700)."
fi
exit 0
