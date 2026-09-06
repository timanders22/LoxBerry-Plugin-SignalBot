#!/bin/bash
# Laeuft als Benutzer loxberry, nach der Installation.
ARGV3=$3
ARGV5=$5
mkdir -p "$ARGV5/data/plugins/$ARGV3" "$ARGV5/log/plugins/$ARGV3" "$ARGV5/config/plugins/$ARGV3"
chmod 0775 "$ARGV5/data/plugins/$ARGV3" "$ARGV5/log/plugins/$ARGV3"
# Die Konfiguration traegt Token, PIN-Hash und die Liste erlaubter Rufnummern.
[ -f "$ARGV5/config/plugins/$ARGV3/signalbot.json" ] && chmod 0600 "$ARGV5/config/plugins/$ARGV3/signalbot.json"
[ -f "$ARGV5/config/plugins/$ARGV3.backup.signalbot.json" ] && chmod 0600 "$ARGV5/config/plugins/$ARGV3.backup.signalbot.json"

BOT="$ARGV5/bin/plugins/$ARGV3/sg_bot.php"

# ---- Den alten Dauerlaeufer beenden ----
#
# Der Bot laeuft dauerhaft und haelt eine Sperrdatei. Ein Update tauscht die
# Dateien unter ihm aus - LoxBerry loescht bin/plugins/<ordner>/ sogar
# vollstaendig -, der alte Prozess laeuft aber weiter und haelt die Sperre.
# Der naechste Cron-Aufruf sieht "laeuft schon" und beendet sich. Ergebnis:
# neue Oberflaeche, alter Bot, bis jemand den Rechner neu startet.
SPERRE="/tmp/$ARGV3/bot.lock"
if [ -f "$SPERRE" ]; then
    PID=$(head -c 12 "$SPERRE" 2>/dev/null | tr -dc '0-9')
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        # Nur beenden, was wirklich der Bot ist - die PID kann inzwischen
        # einem fremden Prozess gehoeren.
        if tr '\0' ' ' < "/proc/$PID/cmdline" 2>/dev/null | grep -q 'sg_bot.php'; then
            kill "$PID" 2>/dev/null
            sleep 2
            kill -0 "$PID" 2>/dev/null && kill -9 "$PID" 2>/dev/null
            echo "<OK> Alter Bot-Lauf (PID $PID) beendet."
        else
            echo "<INFO> PID $PID aus der Sperrdatei gehoert nicht zum Bot - nichts unternommen."
        fi
    fi
fi

# ---- Den Dienst EINMAL von Hand aufrufen ----
#
# Hausregel seit dem 16.08.2026: jeden Cron-Dienst nach der Installation
# einmal von Hand starten und den Rueckgabewert ansehen. Der Cron schreibt
# sonst nach /dev/null, und ein Dienst, der bei jedem Lauf sofort abbricht,
# faellt jahrelang niemandem auf. Der Trockenlauf schaltet nichts: die
# Rufnummer steht auf keiner Weissliste, und 'test' laeuft ohnehin trocken.
if [ -f "$BOT" ]; then
    if AUSGABE=$(/usr/bin/php "$BOT" test "+490000000000" "hilfe" 2>&1); then
        echo "<OK> Der Hintergrunddienst laesst sich starten und findet seine Bibliothek."
    else
        echo "<FAIL> Der Hintergrunddienst bricht beim Start ab:"
        echo "$AUSGABE" | head -5 | sed 's/^/<FAIL>   /'
    fi
    # und jetzt wirklich starten, damit nicht bis zum naechsten Minutenwechsel
    # gewartet wird
    /usr/bin/php "$BOT" >/dev/null 2>&1 &
    echo "<OK> Bot gestartet. Der Cron weckt ihn kuenftig, falls er faellt."
else
    echo "<FAIL> $BOT fehlt - der Bot kann nicht starten."
fi

echo "<OK> Fertig. Die Oberflaeche legt beim ersten Aufruf ein Zugriffstoken an."
exit 0
