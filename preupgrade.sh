#!/bin/bash
ARGV3=$3
ARGV5=$5
mkdir -p "/tmp/${ARGV3}_upgrade"
if [ -d "$ARGV5/config/plugins/$ARGV3" ]; then
    cp -a "$ARGV5/config/plugins/$ARGV3/." "/tmp/${ARGV3}_upgrade/" 2>/dev/null
    echo "<OK> Konfiguration gesichert."
fi
exit 0
