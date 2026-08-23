#!/bin/bash
# Laeuft als ROOT, nach der Installation.
#
# Richtet signal-cli ein. Zwei Huerden sind dabei zu nehmen, und beide werden
# GEPRUEFT statt angenommen - ein Plugin, das hier stillschweigend scheitert,
# laesst den Anwender mit einem Bot zurueck, der nie antwortet:
#
#   1. signal-cli verlangt laut Projekt eine Java-Laufzeit ab Fassung 25.
#      Debian 12 (Bookworm) liefert 17, Debian 13 (Trixie) liefert 25.
#   2. Die native Bibliothek libsignal-client ist im Archiv NUR fuer x86_64
#      enthalten. Auf ARM - also auf jedem Raspberry Pi - fehlt sie und muss
#      getrennt beschafft werden.
#
# Geht eines davon nicht, wird das deutlich gesagt. Das Plugin bleibt
# benutzbar: es kann sich auch mit einem signal-cli verbinden, das anderswo
# laeuft (etwa im Docker-Abbild mit ARM-Unterstuetzung).
ARGV3=$3
ARGV5=$5
ZIEL=/opt

BOGEN=$(dpkg --print-architecture 2>/dev/null)
echo "<INFO> Architektur: $BOGEN"

# ---- Ablage fuer eine nachgereichte native Bibliothek ----
#
# Auf ARM fehlt libsignal-client im Archiv. Nachtragen laesst sie sich ohne
# Eingriff ins JAR: libsignal faellt auf System.loadLibrary("signal_jni")
# zurueck und durchsucht den java.library.path, und den erweitert
# LD_LIBRARY_PATH. Am Geraet gemessen (23.08.2026, Pi 4, arm64):
#
#     Failed to call libsignal-client: no signal_jni in java.library.path: ...
#     LD_LIBRARY_PATH=$HOME/nativ signal-cli ... listAccounts -> Rueckgabewert 0
#
# Der Ordner gehoert dem Benutzer loxberry. Das ist der Kern der Sache: die
# Unit schreibt nur der Installer, und der laeuft als root - alles danach,
# also das Ablegen der Datei und das Starten des Dienstes, geht ohne root.
# NEBEN dem Datenordner: purge_installation loescht bei jedem Update
# data/plugins/<ordner>/ vollstaendig, die Bibliothek waere sonst jedes Mal weg.
NATIVDIR="$ARGV5/data/plugins/$ARGV3.nativ"
mkdir -p "$NATIVDIR"
chown loxberry:loxberry "$NATIVDIR" 2>/dev/null
chmod 0775 "$NATIVDIR"

# ---- php-mbstring fuer die PHP-Fassung, die hier wirklich laeuft ----
#
# In dpkg/apt stand bis 0.9.11 "php-mbstring". Das ist ein Metapaket und zeigt
# auf die VORGABEFASSUNG der Paketquelle - auf Debian 13 mit der sury-Quelle
# also auf PHP 8.x. LoxBerry faehrt aber 7.4 (Protokoll: libapache2-mod-php7.4,
# php7.4-cli, php7.4-cgi). Die Erweiterung waere dann fuer einen Interpreter
# installiert worden, den weder Apache noch die Kommandozeile benutzen.
#
# Deshalb wird die Fassung GEMESSEN, nicht geraten. Die Bibliothek kommt auch
# ohne mbstring aus (PCRE mit /u), das hier ist die Kuer, kein Muss.
PHPV=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)
if echo "$PHPV" | grep -qE '^[0-9]+\.[0-9]+$'; then
    if php -r 'exit(function_exists("mb_strtolower") ? 0 : 1);' 2>/dev/null; then
        echo "<OK> mbstring ist fuer PHP $PHPV vorhanden."
    else
        echo "<INFO> Installiere php$PHPV-mbstring"
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "php$PHPV-mbstring" >/dev/null 2>&1 \
            && echo "<OK> php$PHPV-mbstring eingerichtet." \
            || echo "<WARNING> php$PHPV-mbstring liess sich nicht einrichten. Das Plugin laeuft trotzdem - Laenge und Kleinschreibung kommen dann ohne die Erweiterung aus."
    fi
else
    echo "<WARNING> Die PHP-Fassung liess sich nicht ermitteln - mbstring wurde nicht geprueft."
fi

# Ist signal-cli schon da, wird es NICHT neu beschafft - aber Dienst,
# Systembenutzer und sudo-Regel werden trotzdem eingerichtet.
#
# Bis 0.9.11 stand hier ein "exit 0", und hinter ihm lagen alle uebrigen
# Schritte. Wer das Plugin deinstallierte und neu installierte, bekam
# deshalb KEINEN Dienst: das uninstall-Skript entfernt Unit und sudo-Regel,
# laesst /usr/local/bin/signal-cli aber bewusst stehen - beim zweiten Anlauf
# griff der exit, und die Installation meldete trotzdem Erfolg. Dasselbe traf
# jeden, der signal-cli von Hand oder als Paket hatte. Und eine geaenderte
# Unit-Datei erreichte bestehende Anlagen nie.
#
# Alle Schritte unten sind wiederholbar geschrieben (useradd nur bei Bedarf,
# Unit und sudo-Regel werden ueberschrieben), ein zweiter Lauf schadet also
# nicht.
echo "<INFO> Pruefe, ob signal-cli bereits vorhanden ist"
if command -v signal-cli >/dev/null 2>&1; then
    echo "<OK> signal-cli ist bereits vorhanden - es wird nicht neu geladen."
    HOLEN=0
else
    HOLEN=1
fi

# Kann der Dienst ueberhaupt arbeiten? Auf ARM entscheidet sich das an der
# nativen Bibliothek, siehe unten.
STARTBAR=1

if [ "$HOLEN" = "1" ]; then
# ---------------- Anfang: signal-cli beschaffen ----------------

# ---- Java ----
echo "<INFO> Suche eine passende Java-Laufzeit"
JAVA_OK=0
for PAKET in openjdk-25-jre-headless openjdk-24-jre-headless openjdk-21-jre-headless default-jre-headless; do
    if apt-cache show "$PAKET" >/dev/null 2>&1; then
        echo "<INFO> Installiere $PAKET"
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$PAKET" && { JAVA_OK=1; break; }
    fi
done
if [ "$JAVA_OK" != "1" ]; then
    echo "<FAIL> Keine Java-Laufzeit installierbar."
    echo "<INFO> Das Plugin bleibt nutzbar: im Reiter Einstellungen die Adresse"
    echo "<INFO> eines signal-cli eintragen, das anderswo laeuft."
    exit 0
fi

JVER=$(java -version 2>&1 | head -1 | sed -E 's/.*"([0-9]+).*/\1/')
echo "<INFO> Java-Fassung: $JVER"
if [ -n "$JVER" ] && [ "$JVER" -lt 25 ] 2>/dev/null; then
    echo "<WARNING> signal-cli verlangt Java 25, gefunden wurde $JVER."
    echo "<WARNING> Es wird trotzdem eingerichtet - laeuft es nicht, steht der"
    echo "<WARNING> Grund im Reiter Test. Abhilfe: Java 25 aus den Backports,"
    echo "<WARNING> oder ein signal-cli auf einem anderen Rechner benutzen."
fi

# ---- signal-cli ----
#
# GEBUNDENE FASSUNG, nicht 'latest'.
#
# Bis 0.9.11 wurde bei jeder Installation die jeweils neueste Fassung geholt.
# Damit bekam jede Anlage eine andere, und eine kuenftige Fassung, die die
# JSON-RPC-Schnittstelle aendert oder --http umbenennt, haette neue
# Installationen lahmgelegt, ohne dass sich am Plugin etwas geaendert haette.
#
# 0.14.7 ist die Fassung, gegen die dieses Plugin nachgemessen wurde
# (16.08.2026: Endpunkte /api/v1/rpc, /api/v1/events, /api/v1/check, die
# Methoden send, listAccounts, startLink, finishLink). Wer eine andere will,
# setzt sie beim Aufruf davor:  SIGNALCLI_FASSUNG=0.15.0 ./postroot.sh
VERSION="${SIGNALCLI_FASSUNG:-0.14.7}"
if ! echo "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "<FAIL> Unbrauchbare Fassungsangabe ('$VERSION')."
    exit 0
fi
echo "<INFO> Vorgesehene signal-cli-Fassung: $VERSION"
echo "<INFO> Lade signal-cli $VERSION (Java-Fassung, rund 120 MB)"
if ! curl -Lf --retry 2 -o "/tmp/signal-cli-$VERSION.tar.gz" \
     "https://github.com/AsamK/signal-cli/releases/download/v$VERSION/signal-cli-$VERSION.tar.gz"; then
    echo "<FAIL> Der Download ist fehlgeschlagen."
    exit 0
fi
tar xf "/tmp/signal-cli-$VERSION.tar.gz" -C "$ZIEL"
rm -f "/tmp/signal-cli-$VERSION.tar.gz"
ln -sf "$ZIEL/signal-cli-$VERSION/bin/signal-cli" /usr/local/bin/signal-cli
echo "<OK> signal-cli $VERSION nach $ZIEL/signal-cli-$VERSION entpackt"

# ---------------- Ende: signal-cli beschaffen ----------------
fi

# ---- Die native Bibliothek: der eigentliche Stolperstein auf ARM ----
#
# Nicht die Architektur entscheidet, ob der Dienst starten darf, sondern die
# Bibliothek - deshalb wird im JAR NACHGESEHEN statt geraten. Nachgemessen am
# 16.08.2026 im Archiv signal-cli-0.14.7.tar.gz: das mitgelieferte
# libsignal-client-0.99.1.jar enthaelt libsignal_jni_amd64.so (Linux x86_64),
# die beiden macOS-Dateien und die Windows-DLL - eine libsignal_jni_aarch64.so
# fuer Linux/ARM ist NICHT dabei. Wer sie nachtraegt, soll den Dienst
# bekommen, ohne dass jemand dieses Skript aendert.
#
# Die Pruefung laeuft AUSSERHALB des Beschaffungsteils: sie muss auch dann
# greifen, wenn signal-cli schon vorhanden war.
if [ "$BOGEN" != "amd64" ]; then
    STARTBAR=0
    NATIV=""
    # Nur der Fall, der nachgemessen ist. Fuer jede andere Architektur bleibt
    # es beim Nichtstarten - lieber kein Dienst als ein Dienst in Endlosschleife.
    [ "$BOGEN" = "arm64" ] && NATIV="libsignal_jni_aarch64.so"
    JAR=$(ls "$ZIEL"/signal-cli-*/lib/libsignal-client-*.jar 2>/dev/null | head -1)
    if [ -n "$NATIV" ] && [ -n "$JAR" ] && command -v unzip >/dev/null 2>&1        && unzip -l "$JAR" 2>/dev/null | grep -q "$NATIV"; then
        STARTBAR=1
        echo "<OK> $NATIV liegt im JAR - der Dienst kann auf $BOGEN arbeiten."
    fi
    # Oder sie liegt nachgereicht im Ordner nativ. Der Name ist der, den
    # System.loadLibrary("signal_jni") sucht: lib + signal_jni + .so
    if [ "$STARTBAR" = "0" ] && [ -f "$NATIVDIR/libsignal_jni.so" ]; then
        STARTBAR=1
        echo "<OK> libsignal_jni.so liegt in $NATIVDIR - der Dienst kann arbeiten."
    fi
fi


# ---- Dienst ----
# Eigener Systembenutzer: der Bot haelt Signal-Schluessel, die haben in
# keinem Heimatverzeichnis eines Menschen etwas zu suchen.
if ! id signalcli >/dev/null 2>&1; then
    useradd --system --home-dir /var/lib/signal-cli --create-home --shell /usr/sbin/nologin signalcli
fi
mkdir -p /var/lib/signal-cli
chown -R signalcli:signalcli /var/lib/signal-cli
chmod 0700 /var/lib/signal-cli

# Heredoc OHNE Anfuehrungszeichen: $NATIVDIR muss ersetzt werden. Andere
# Dollarzeichen kommen in der Unit nicht vor.
cat > /etc/systemd/system/signal-cli-loxberry.service <<UNIT
[Unit]
Description=signal-cli JSON-RPC daemon for the LoxBerry Signal Bot
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=signalcli
Group=signalcli
Environment=XDG_DATA_HOME=/var/lib/signal-cli
# Hier sucht die Java-Laufzeit zusaetzlich nach nativen Bibliotheken. Der
# Ordner gehoert loxberry: wer libsignal_jni.so nachreicht, braucht dafuer
# kein root. Ohne die Datei ist die Zeile wirkungslos.
Environment=LD_LIBRARY_PATH=$NATIVDIR
# Mehrkontenbetrieb (ohne -a): nur so gibt es startLink und finishLink,
# und nur damit laesst sich das Konto aus der Oberflaeche verknuepfen.
ExecStart=/usr/local/bin/signal-cli --config /var/lib/signal-cli daemon --http=127.0.0.1:8095
Restart=always
RestartSec=10
# Der Dienst braucht nichts vom uebrigen System.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
# read-only statt true: auf vielen LoxBerry-Anlagen ist /opt/loxberry ein
# Verweis nach /home/loxberry. Mit ProtectHome=true waere der Ordner nativ
# fuer den Dienst unerreichbar - er liegt dann naemlich unter /home. Gelesen
# werden muss er, geschrieben nicht.
ProtectHome=read-only
ReadWritePaths=/var/lib/signal-cli

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
# Nur einschalten, wenn der Dienst auch arbeiten kann. Ein Dienst, der ab
# Werk in einer Startschleife haengt, ist schlimmer als keiner: er fuellt das
# Journal und sieht in jeder Statusanzeige nach einem Defekt des Plugins aus.
if [ "$STARTBAR" = "1" ]; then
    systemctl enable signal-cli-loxberry >/dev/null 2>&1
    systemctl start signal-cli-loxberry >/dev/null 2>&1
fi

# Der Benutzer loxberry muss den Dienst aus der Oberflaeche steuern koennen -
# aber nur diesen einen und nur diese Unterbefehle.
#
# enable und disable sind seit 0.9.12 dabei. Grund: der Benutzer loxberry hat
# auf einem LoxBerry nicht zwangslaeufig allgemeine sudo-Rechte. Ohne diese
# beiden Eintraege liesse sich der Autostart nur als root einschalten - und
# genau das brauchte man auf einem Raspberry Pi, wo das Plugin den Dienst
# bewusst nicht startet, bis die native Bibliothek da ist. Der Zugewinn an
# Rechten ist gering: es geht weiterhin nur um diese eine Unit, und wer den
# Dienst ohnehin starten darf, darf ihn dann auch beim Hochfahren starten.
cat > /etc/sudoers.d/loxberry-signalbot <<'SUDO'
loxberry ALL=(root) NOPASSWD: /bin/systemctl start signal-cli-loxberry, /bin/systemctl stop signal-cli-loxberry, /bin/systemctl restart signal-cli-loxberry, /bin/systemctl enable signal-cli-loxberry, /bin/systemctl disable signal-cli-loxberry
loxberry ALL=(root) NOPASSWD: /usr/bin/systemctl start signal-cli-loxberry, /usr/bin/systemctl stop signal-cli-loxberry, /usr/bin/systemctl restart signal-cli-loxberry, /usr/bin/systemctl enable signal-cli-loxberry, /usr/bin/systemctl disable signal-cli-loxberry
SUDO
chmod 0440 /etc/sudoers.d/loxberry-signalbot
if ! visudo -cf /etc/sudoers.d/loxberry-signalbot >/dev/null 2>&1; then
    echo "<WARNING> Die sudo-Regel war fehlerhaft und wurde wieder entfernt."
    rm -f /etc/sudoers.d/loxberry-signalbot
fi

if [ "$STARTBAR" = "1" ]; then
    echo "<OK> Dienst signal-cli-loxberry eingerichtet und gestartet, JSON-RPC auf 127.0.0.1:8095"
    echo "<INFO> Naechster Schritt: im Plugin den Reiter Einstellungen oeffnen und"
    echo "<INFO> das Signal-Konto als Zweitgeraet verknuepfen."
else
    echo "<OK> Dienst signal-cli-loxberry eingerichtet, aber noch nicht gestartet."
fi

# Der Hinweis steht ABSICHTLICH hier unten, als Letztes.
#
# Er stand bis 0.9.13 mitten im Ablauf und war als <WARNING> ausgezeichnet.
# Beides war unguenstig: die Installation ist gelungen, es fehlt nur ein
# Schritt an einer fremden Voraussetzung - ein <WARNING> mitten im Protokoll
# liest sich wie ein Defekt des Plugins. Und was in der Mitte steht, hat man
# am Ende der Installation vergessen. Deshalb: <INFO>, und zwar zuletzt.
if [ "$BOGEN" != "amd64" ] && [ "$STARTBAR" = "0" ]; then
    echo "<INFO> ------------------------------------------------------------"
    echo "<INFO> NOCH EIN SCHRITT, DANN IST DER BOT BEREIT"
    echo "<INFO>"
    echo "<INFO> Das Plugin ist vollstaendig installiert. Was noch fehlt,"
    echo "<INFO> gehoert nicht zum Plugin, sondern zu signal-cli: dessen"
    echo "<INFO> native Bibliothek libsignal-client liegt dem Archiv NUR fuer"
    echo "<INFO> x86_64 bei. Auf '$BOGEN' fehlt sie."
    echo "<INFO>"
    echo "<INFO> Der Dienst ist deshalb eingerichtet, aber noch nicht"
    echo "<INFO> gestartet - er wuerde sonst alle zehn Sekunden neu anlaufen"
    echo "<INFO> und wieder abstuerzen. Das ist Absicht, kein Fehler."
    echo "<INFO>"
    echo "<INFO> Der bequemste Weg fuehrt ueber die Oberflaeche: Reiter Test,"
    echo "<INFO> Knopf 'Bibliothek libsignal holen'. Er laedt die passende"
    echo "<INFO> Datei, legt sie richtig ab und sagt danach, was zu tun ist."
    echo "<INFO>"
    echo "<INFO> Von Hand geht es auch, auf drei Wegen:"
    echo "<INFO>  a) Fertiges Paket fuer $BOGEN. Das signal-cli-Wiki verlinkt"
    echo "<INFO>     unter 'Binary distributions' eine Paketquelle, die auch"
    echo "<INFO>     arm64 fuehrt; 'signal-cli-native' braucht kein Java."
    echo "<INFO>     Es ist ein Fremdpaket - bitte selbst einrichten:"
    echo "<INFO>       https://github.com/AsamK/signal-cli/wiki/Binary-distributions"
    echo "<INFO>  b) libsignal_jni.so fuer $BOGEN in diesen Ordner legen:"
    echo "<INFO>       $NATIVDIR"
    echo "<INFO>     Der Ordner gehoert loxberry - dafuer wird KEIN root"
    echo "<INFO>     gebraucht, auch nicht zum anschliessenden Starten."
    echo "<INFO>     Fertige Baue verlinkt das signal-cli-Wiki unter"
    echo "<INFO>     'Provide native lib for libsignal'; die Fassung muss zu"
    echo "<INFO>     der im JAR passen (signal-cli 0.14.7 -> libsignal 0.99.1)."
    echo "<INFO>     Der Reiter Test sagt, ob die Datei erkannt wurde."
    echo "<INFO>  c) signal-cli auf einem anderen Rechner betreiben und im"
    echo "<INFO>     Reiter Einstellungen dessen Adresse eintragen."
    echo "<INFO>"
    echo "<INFO> Laeuft signal-cli danach - oder laeuft es hier bereits,"
    echo "<INFO> etwa als Paket signal-cli-native -, genuegt im Plugin der"
    echo "<INFO> Reiter Test: dort 'Dienst starten' und 'Autostart"
    echo "<INFO> einschalten'. Beides braucht KEINE root-Rechte, dafuer"
    echo "<INFO> gibt es die sudo-Regel dieses Plugins."
    echo "<INFO> Ueber SSH als Benutzer loxberry dasselbe:"
    echo "<INFO>   sudo -n /bin/systemctl enable signal-cli-loxberry"
    echo "<INFO>   sudo -n /bin/systemctl start signal-cli-loxberry"
    echo "<INFO>"
    echo "<INFO> Der Reiter Test sagt jederzeit, woran es gerade haengt -"
    echo "<INFO> mit Anleitung Schritt fuer Schritt."
    echo "<INFO> ------------------------------------------------------------"
fi

exit 0
