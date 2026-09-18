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
BOT_R=$(readlink -f "$BOT" 2>/dev/null)
[ -n "$BOT_R" ] || BOT_R="$BOT"
BOT_UID=$(id -u loxberry 2>/dev/null)
[ -n "$BOT_UID" ] || BOT_UID=$(id -u)
SPERRE="/tmp/$ARGV3/bot.lock"

# ---- Den alten Dauerlaeufer beenden ----
#
# Der Bot laeuft dauerhaft und haelt eine Sperrdatei. Ein Update tauscht die
# Dateien unter ihm aus - LoxBerry loescht bin/plugins/<ordner>/ sogar
# vollstaendig -, der alte Prozess laeuft aber weiter und haelt die Sperre.
# Der naechste Cron-Aufruf sieht "laeuft schon" und beendet sich. Ergebnis:
# neue Oberflaeche, alter Bot, bis jemand den Rechner neu startet.
#
# WIE EIN EIGENER PROZESS ERKANNT WIRD - GEAENDERT IN 0.9.23
#
# Bis 0.9.22 wurde die Befehlszeile aus /proc/<pid>/cmdline mit einem
# Zeichentausch zu EINER Zeichenkette gemacht und per Teilzeichenkette
# durchsucht. Damit galt jeder fremde Prozess als eigener, in dessen
# Befehlszeile der Dateiname irgendwo vorkam. In WSL Ubuntu gemessen am
# 18.09.2026 mit dem Koeder "tail -f <botpfad>":
#
#     <OK> Alter Bot-Lauf (PID 683316) beendet.
#
# Gemeint war der Bot, beendet wurde der Koeder. Dieselbe Messung traf auch
# den Bot eines ZWEITEN Plugin-Ordners und den Einmallauf "sg_bot.php
# einmal" (Faelle F3 und F4 in Pruefung-SignalBot-0.9.23).
# Quelle: Bestand-2026-09-18/klasse-F-nachmessung, Zeile 11.
#
# Jetzt argumentweise: die Befehlszeile wird am Nullbyte zerlegt, argv[0]
# muss ein PHP-Interpreter sein, argv[1] GENAU der eigene Botpfad, und ein
# drittes Argument schliesst aus - "sg_bot.php einmal" und "sg_bot.php test
# ..." sind Einmallaeufe und kein Dienst. Wird relativ gestartet (die Datei
# traegt "#!/usr/bin/env php", dann steht in argv[1] der Pfad so, wie er
# aufgerufen wurde), wird ueber /proc/<pid>/cwd aufgeloest.
#
# Dieselbe Funktion steht in uninstall/uninstall - wer sie hier aendert,
# aendert sie dort mit.
sg_ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    {
        IFS= read -r -d '' sg_a0 || return 1
        IFS= read -r -d '' sg_a1 || return 1
        case "${sg_a0##*/}" in
            php|php[0-9]|php[0-9].[0-9]|php[0-9].[0-9][0-9]) ;;
            *) return 1 ;;
        esac
        if [ "$sg_a1" != "$BOT" ]; then
            case "$sg_a1" in
                /*) sg_voll="$sg_a1" ;;
                *)  sg_voll="$(readlink -f "/proc/$1/cwd" 2>/dev/null)/$sg_a1" ;;
            esac
            [ "$(readlink -f "$sg_voll" 2>/dev/null)" = "$BOT_R" ] || return 1
        fi
        IFS= read -r -d '' sg_a2 && return 1
        return 0
    } < "/proc/$1/cmdline"
}

# Alle eigenen Dienste, aufsteigend und ohne Dubletten. Zwei Quellen, weil
# keine allein reicht:
#   - die Suche ueber /proc findet auch einen Bot OHNE brauchbare
#     Sperrdatei. In WSL gemessen (Fall F6): mit geloeschter Sperrdatei lief
#     der alte Bot durch die ganze Installation weiter, hinterher liefen
#     ZWEI. Und Fall F7: die Sperrdatei kennt nur EINE Nummer, der zweite
#     Lauf blieb stehen.
#   - die Sperrdatei findet auch einen Bot, der einem anderen Benutzer
#     gehoert (von Hand als root gestartet) und deshalb durch den
#     Benutzerfilter faellt.
sg_dienste() {
    {
        for sg_d in /proc/[0-9]*; do
            sg_pid="${sg_d#/proc/}"
            sg_ist_dienst "$sg_pid" || continue
            [ "$(stat -c %u "$sg_d" 2>/dev/null)" = "$BOT_UID" ] || continue
            echo "$sg_pid"
        done
        if [ -f "$SPERRE" ]; then
            sg_p=$(head -c 12 "$SPERRE" 2>/dev/null | tr -dc '0-9')
            case "$sg_p" in
                ''|*[!0-9]*) ;;
                *) sg_ist_dienst "$sg_p" && echo "$sg_p" ;;
            esac
        fi
    } | sort -un
}

SG_ZIEL=$(sg_dienste)
if [ -n "$SG_ZIEL" ]; then
    SG_LISTE=$(printf '%s' "$SG_ZIEL" | tr '\n' ' ')
    # Vor JEDEM Signal ist geprueft: sg_dienste liefert nur argumentweise
    # bestaetigte Nummern, und vor dem harten Signal wird neu gesucht.
    kill $SG_ZIEL 2>/dev/null
    sg_i=0
    while [ $sg_i -lt 10 ]; do
        [ -n "$(sg_dienste)" ] || break
        sleep 1
        sg_i=$((sg_i + 1))
    done
    SG_REST=$(sg_dienste)
    if [ -n "$SG_REST" ]; then
        kill -9 $SG_REST 2>/dev/null
        sleep 1
    fi
    # "beendet" ist eine Zusicherung, kein Rueckgabewert: es wird nachgesehen
    # (Regeln/01, "Wirkung pruefen, nicht Rueckgabewert").
    SG_UEBRIG=$(sg_dienste)
    if [ -z "$SG_UEBRIG" ]; then
        echo "<OK> Alter Bot-Lauf beendet (PID $SG_LISTE)."
    else
        echo "<WARNING> Ein alter Bot-Lauf laeuft weiter (PID $(printf '%s' "$SG_UEBRIG" | tr '\n' ' '))."
    fi
else
    echo "<INFO> Es lief kein Bot dieses Plugins - nichts zu beenden."
fi

# Die Sperrdatei bleibt eine Quelle, aber sie ist keine Erlaubnis: gehoert
# die Nummer darin einem fremden Prozess, wird das gesagt und nichts getan.
if [ -f "$SPERRE" ]; then
    SG_SPID=$(head -c 12 "$SPERRE" 2>/dev/null | tr -dc '0-9')
    if [ -n "$SG_SPID" ] && kill -0 "$SG_SPID" 2>/dev/null && ! sg_ist_dienst "$SG_SPID"; then
        echo "<INFO> PID $SG_SPID aus der Sperrdatei gehoert nicht zum Bot - sie wird nicht angefasst."
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
