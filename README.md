# LoxBerry-Plugin Signal Bot

Ein **vollwertiger, lokaler Messenger-Bot** fuer LoxBerry. Nutzer schicken per
Signal einen Befehl im Klartext — `alarmanlage unscharf` — und der Miniserver
fuehrt ihn aus. Umgekehrt meldet Loxone in denselben Chat zurueck.

Alles laeuft auf dem eigenen Geraet: signal-cli haengt sich als Zweitgeraet an
ein bestehendes Signal-Konto, die Nachrichten sind Ende-zu-Ende verschluesselt,
ein Cloud-Dienst ist nicht beteiligt.

## Neu in 0.9.13

**Die Oberflaeche liess sich gar nicht oeffnen — HTTP 500, von Anfang an.**

Der LoxBerry-Kern wird per `require_once` eingebunden, und eine eingebundene
Datei laeuft im Variablenraum des Aufrufers. `libs/phplib/loxberry_system.php`
setzt in seinem Dateikoerper 31 Variablen, darunter in Zeile 18:

```php
$p = explode("/", substr($scriptPath, strlen(LBHOMEDIR)));
```

Das Plugin hielt in `$p` seine eigenen Pfade aus `sg_paths()`. Nach der ersten
der beiden `require_once`-Zeilen war `$p` ein Feld von Pfadstuecken, `$p['home']`
leer — und die zweite Zeile suchte `/libs/phplib/loxberry_web.php` im
Wurzelverzeichnis:

    Failed opening required '/libs/phplib/loxberry_web.php'
    in .../plugins/signalbot/index.php on line 16

Behoben: der Wert wird **vor** dem Einbinden in eine eigene Variable gerettet,
jede der beiden Kerndateien wird **einzeln** geprueft, geladen wird mit
`include_once` ueber den `include_path`, wenn die Wurzel nichts taugt — und
`$p` wird nach dem Einbinden neu geholt. Der Kern ist fuer dieses Plugin
ohnehin nicht zwingend: alle Aufrufe stehen hinter `class_exists('LBWeb')`.
Ein optionaler Bestandteil darf die Seite nicht toeten.

Dazu abgesichert: `simplexml_load_string()` im Reiter *Test* laeuft jetzt nur
noch, wenn es die Erweiterung gibt. Fehlt sie, meldet die Selbstpruefung
"nicht pruefbar", statt die ganze Seite mitzunehmen — dieselbe Klasse, nur
eine Erweiterung weiter.

**Warum das keine Pruefung gefunden hat:** Die Attrappe des Kerns belegte
diese Variablennamen nicht. Sie tut es seit dem 17.08.2026; seither meldet
`rendern.py` fuer 0.9.12 `FATAL` und `AUSGABE ZU KURZ (0 Zeichen)`.

## Neu in 0.9.12

Diese Fassung behebt fuenf Fehler, von denen zwei das Plugin an einer echten
Anlage unbrauchbar gemacht haben, raeumt die Abweichungen vom
Oberflaechen-Hausstandard aus und bringt zwoelf neue Funktionen. Jeder Punkt
wurde nachgemessen, nicht abgeleitet.

### Behoben

**Der Endpunkt fuer Loxone war installiert vollstaendig tot.**
`webfrontend/html/index.php` suchte `sg_lib.php` ueber `dirname(__DIR__)`. Im
entpackten Archiv geht das auf, auf dem installierten LoxBerry liegen `html/`
und `htmlauth/` aber in getrennten Baeumen - jeder Aufruf endete in HTTP 500
mit leerem Rumpf, weil zwei Zeilen darueber `display_errors` abgeschaltet wird.
Damit war die ganze Richtung Loxone &rarr; Chat tot: Meldungen, Zustaende und
Statusabfrage gleichermassen. In Loxone sieht das aus wie "kein Wert", nicht
wie ein Defekt. Die Bibliothek wird jetzt ueber eine Kandidatenliste gesucht;
findet keiner sie, antwortet der Endpunkt mit `SIGNAL;OK=0;GRUND=BIBLIOTHEK_FEHLT`
statt zu schweigen.

**Das MQTT-Gateway galt immer als "nicht auf Autostart".** Gesucht wurde der
Schluessel `Autostart`; er heisst `Gatewayautostart`. Auf jeder einwandfrei
eingerichteten Anlage stand deshalb dauerhaft eine Warnung im Reiter MQTT und
ein rotes Kreuz im Reiter Test.

**Der "Trockenlauf" im Reiter Test hat wirklich geschaltet.** Ein Befehl der
Stufe *sofort* wurde ausgefuehrt, und bei *Rueckfrage* legte der Probelauf eine
Wartedatei fuer die echte Rufnummer des ersten erlaubten Absenders an -
antwortete diese Person spaeter aus einem anderen Grund "ja", fuehrte der Bot
den Befehl aus, den der Verwalter ins Testfeld getippt hatte. Jetzt gibt es
einen echten Trockenlauf: nichts wird gesendet, nichts gemerkt, nichts gezaehlt.

**Eine leere Konfiguration hat Weissliste, PIN und Token ueberschrieben - samt
Zweitschrift.** Der Schutz hing an `is_array()`, und eine leere Datei ergibt ein
leeres Feld, was ein Feld *ist*. Jetzt gilt eine vorhandene, aber leere Datei
als unlesbar, und zurueckgeholt wird nur eine Sicherung, die auch Inhalt hat.

**Nach Deinstallation und Neuinstallation gab es keinen Dienst.**
`postroot.sh` stieg mit `exit 0` aus, sobald signal-cli im Pfad lag - dahinter
lagen Systembenutzer, Unit-Datei und sudo-Regel. Jetzt umschliesst die Pruefung
nur noch das Beschaffen.

Dazu: `?selftest=1` am Endpunkt (Hausstandard), UDP ohne `php-sockets`,
`sg_daemon_lebt()` wertet die HTTP-Statuszeile aus statt jede Antwort als
"lebt" zu zaehlen, die Bremse faellt geschlossen aus statt offen, die
Rueckfrage haengt am Befehlswort statt an der Zeilennummer, ein ungueltiges
`&an=` wird abgewiesen statt zum Rundruf an alle zu werden, `preinstall.sh`
und die beiden Upgrade-Skripte sind entfallen (sie sicherten in einen Ordner,
den der Installer eine Sekunde spaeter loescht), `ARCHITECTURE=false`,
`php-mbstring` wird zur wirklich laufenden PHP-Fassung passend nachinstalliert,
signal-cli ist auf eine gemessene Fassung gebunden statt "latest", und der alte
Dauerlaeufer wird beim Update beendet, statt bis zum naechsten Neustart
weiterzulaufen.

### Neu

| Funktion | Kurz |
|---|---|
| Kill-Schalter | Sperrt den Bot - aus der Oberflaeche oder aus Loxone. Fuer den Fall, dass ein Handy abhandenkommt. |
| Werte je Befehl | `heizung 21` statt nur fester Nutzlast, mit Bereichsgrenzen je Zeile. |
| Zustaendigkeit je Befehl | Die Weissliste sagt, wer reden darf; die neue Spalte, wer *diesen* Befehl ausloesen darf. |
| Vier-Augen | Eine zweite Rufnummer muss freigeben, bevor geschaltet wird. |
| PIN-Sperre | Nach N Fehlversuchen ist die PIN fuer diesen Absender gesperrt. Die PIN steht jetzt als Hash in der Konfiguration. |
| Ereignisprotokoll | Wer hat wann was ausgeloest - getrennt vom Betriebsprotokoll, im Reiter Logdateien. |
| Herzschlag | Zeitstempel jede Minute auf `<praefix>/online`, damit Loxone einen Ausfall ueberhaupt erkennen kann. |
| Selbstheilung | Nach mehreren vergeblichen Anlaeufen startet der Bot den signal-cli-Dienst neu. |
| Nachtruhe | Gewoehnliche Meldungen warten bis zum Morgen, dringende gehen sofort. |
| Dringend mit Quittung | Wird wiederholt, bis der Empfaenger `quittiert` schreibt. |
| Gruppen und Anhaenge | Meldung in eine Signal-Gruppe, wahlweise mit Bild - etwa dem Kamera-Schnappschuss zum Alarm. |
| Vorlage fuer virtuelle Ausgaenge | `VQ_signalbot.xml` mit Adresse, Token und URL-Kodierung fertig. |
| `status alarm` | Ein einzelner Zustand statt aller, dazu ein Vorschlag bei Tippfehlern. |

### Was sich fuer bestehende Anlagen aendert

Die Adressen im Miniserver bleiben gueltig; die Statuszeile bekommt drei
Felder **hinzu** (`GESPERRT`, `OFFEN`, `LETZTER`), vorhandene
Befehlserkennungen greifen weiter. Eine im Klartext gespeicherte PIN wird beim
ersten Speichern in der Oberflaeche in einen Hash umgeschrieben; bis dahin
wird sie weiter angenommen. Neue Funktionen sind ab Werk aus: Nachtruhe ist
leer, die Gruppen-Kennung ist leer, kein Befehl hat eine Zustaendigkeit oder
eine zweite Freigabe.

## Neu in 0.9.10

**Der Bot-Dienst konnte nie starten.** `bin/sg_bot.php` suchte seine Programmbibliothek
ueber `dirname(__DIR__) . '/webfrontend/htmlauth/…'`. Im entpackten Archiv
liegen `bin/` und `webfrontend/` nebeneinander, auf dem installierten
LoxBerry in getrennten Baeumen — der Aufruf endete dort bei jedem Cron-Lauf
mit `Failed opening required`. Weil die Cron-Zeile nach `/dev/null` schreibt,
stand das nirgends. Damit hat der Bot seit der Einfuehrung nie gelauscht.

Die Bibliothek wird jetzt ueber eine Kandidatenliste gesucht; findet keiner
sie, schreibt der Dienst auf die Fehlerausgabe, **welche Datei er wo gesucht
hat**, und endet mit Rueckgabewert 1 statt stillschweigend.

Nach dem Update einmal von Hand pruefen:

```bash
php /opt/loxberry/bin/plugins/<ordner>/sg_bot.php; echo "Rueckgabewert: $?"
```

## Zwei Richtungen

| Richtung | Weg | Beispiel |
|---|---|---|
| Chat &rarr; Loxone | MQTT-Impuls auf ein Thema | `licht aus` &rarr; `signalbot/befehl/licht_aus` = 1 |
| Loxone &rarr; Chat | Token-Endpunkt, den ein virtueller Ausgang aufruft | Alarm ausgeloest &rarr; Nachricht an alle Erlaubten |

## Die drei Sicherungsschichten

Ein Bot, der die Alarmanlage entschaerfen darf, ist ein Schluessel — und er
liegt in einem Chat auf einem Handy, das verloren gehen kann. Drei Schichten
greifen unabhaengig voneinander:

1. **Weissliste.** Nur eingetragene Rufnummern werden ueberhaupt gehoert. Wer
   nicht daraufsteht, bekommt wahlweise gar keine Antwort — eine Antwort wuerde
   Fremden bestaetigen, dass hier ein Bot lauscht.
2. **Stufe je Befehl.** `sofort` fuer Licht und Rollaeden, `rueckfrage` fuer
   alles, was nicht versehentlich passieren soll, `pin` fuer Alarmanlage und
   Haustuer. Eine offene Rueckfrage verfaellt nach 90 Sekunden.
3. **Bremse.** Mehr als N Befehle je Minute und Absender werden abgewiesen.
   Das begrenzt vor allem das Durchprobieren einer PIN.

Der HTTP-Endpunkt fuer Loxone kann **bewusst keine Chat-Befehle ausloesen**. Er
darf melden und senden, nicht schalten — sonst waere die ganze Absicherung
ueber einen einzigen HTTP-Aufruf zu umgehen.

## Vor dem Einbau lesen: zwei harte Voraussetzungen

Diese beiden Punkte entscheiden, ob das Plugin auf dem jeweiligen Geraet
ueberhaupt laufen kann. Beide werden bei der Installation und im Reiter *Test*
geprueft und benannt — sie scheitern nicht stillschweigend.

* **signal-cli verlangt Java 25.** Aeltere Laufzeiten starten den Dienst und
  lassen ihn sofort wieder fallen. `postroot.sh` installiert die beste
  verfuegbare Laufzeit und warnt, wenn sie zu alt ist.
* **Die native Bibliothek `libsignal-client` liegt dem Archiv nur fuer x86_64
  bei.** Auf jedem Raspberry Pi fehlt sie, und signal-cli bricht beim ersten
  Zugriff mit einem `UnsatisfiedLinkError` ab. Zwei Wege: die Bibliothek fuer
  die eigene Architektur beschaffen (signal-cli-Wiki, *Provide native lib for
  libsignal*), oder signal-cli in einem ARM-faehigen Docker-Abbild betreiben
  und im Plugin nur dessen Adresse eintragen (`rpc_url`).

Der zweite Weg ist auf dem Pi der bequemere und wird vom Plugin vollstaendig
unterstuetzt: dann ist LoxBerry Bedienoberflaeche und Bruecke, signal-cli
laeuft daneben.

## Einrichten

1. *Einstellungen* &rarr; **Verknuepfung starten**, QR-Code am Handy scannen
   (Signal &rarr; Einstellungen &rarr; Gekoppelte Geraete), dann **Verknuepfung
   abschliessen**.
2. Erlaubte Absender eintragen, eine Rufnummer je Zeile, international.
3. PIN vergeben, wenn kritische Befehle geplant sind.
4. *Befehle* &rarr; Tabelle fuellen: Wort, MQTT-Thema, Stufe.
5. *MQTT* &rarr; das angezeigte Abo ins MQTT-Gateway eintragen
   (System &rarr; MQTT Gateway &rarr; Subscriptions). **Ohne diesen Eintrag
   kommt am Miniserver nichts an** — das ist die haeufigste Fehlerursache.
6. *Einbindung in Loxone* &rarr; virtuelle Eingaenge auf die Themen legen, die
   Melde-Adresse kopieren, wahlweise die XML-Vorlage herunterladen.
7. *Test* &rarr; Selbstpruefung ansehen, einen Befehl trocken durchspielen.

## Eingebaute Woerter

`hilfe` listet alle aktiven Befehle, `status` die gemeldeten Zustaende,
`ja` / `nein` beantworten eine Rueckfrage. Diese Woerter sind nicht
ueberschreibbar; die Selbstpruefung meldet es, wenn ein Befehl sie belegt.

## Datenschutz

Rufnummern erscheinen im Protokoll nur verkuerzt. Ein Log, das monatelang
vollstaendige Rufnummern aufhebt — auch die von Fremden, die sich verwaehlt
haben — waere ein Datenbestand, den niemand anlegen wollte.

## Verzeichnis

```
bin/sg_bot.php                     Dauerlaeufer am Ereignisstrom
cron/cron.01min                    Wiederbeleber (kein Taktgeber)
webfrontend/htmlauth/index.php     Bedienoberflaeche, sechs Reiter
webfrontend/htmlauth/sg_lib.php    Konfiguration, Befehlslogik, RPC, Vorlage
webfrontend/htmlauth/sg_test.php   Selbstpruefung und Test-Aktionen
webfrontend/html/index.php         Token-Endpunkt fuer den Miniserver
postroot.sh                        Java, signal-cli, systemd-Dienst, sudoers
```

## Was in 0.9.2 nachgemessen und geaendert wurde

> Diese Fassung enthaelt auch die nie einzeln veroeffentlichte 0.9.1.

0.9.1 hat die Bremse und das Protokoll gegen gleichzeitige Zugriffe
abgesichert. Zwei Dateien, an denen dieselbe Ueberlegung haengt, waren dabei
uebersehen worden — und ausgerechnet die wichtigste war darunter.

**Die Konfiguration wurde nicht unteilbar geschrieben.** An ihr haengt ein
Dauerlaeufer: `sg_bot.php` ruft `sg_config()` bei jeder eingehenden Nachricht
auf, und `sg_erlaubt()`, `sg_bremse_frei()` und `sg_senden()` rufen es jeweils
erneut. Speichert die Oberflaeche in genau diesem Augenblick, laesst
`file_put_contents` die Datei zuerst auf null Byte schrumpfen. Nachgemessen,
Konfiguration von 2400 Byte, 3000 Speichervorgaenge bei laufendem Leser:

| Verfahren | Lesevorgaenge | davon leer gelesen |
|---|---|---|
| bisher (`file_put_contents`) | 34 187 | **19 375** |
| Nebendatei + `rename()` | 38 431 | **0** |

Ein leeres Lesen ist **nicht gefaehrlich**: der Bot faellt auf die Vorgaben
zurueck, und die haben eine leere Weissliste und keine PIN — er weist also ab
statt durchzulassen. Aber es ist laestig, weil ein berechtigter Befehl dann
nicht ausgefuehrt wird, und es kann Einstellungen kosten: `sg_config()` holt
bei leerem Lesen die Sicherung zurueck — mitten in das Speichern hinein.

Dazu zwei kleinere Sicherungen an derselben Stelle: Die Rechte `0600` werden
jetzt **vor** dem Umbenennen gesetzt, sonst stuende die Datei mit PIN und
Token einen Augenblick lang mit den Rechten aus der umask da. Und wenn die
Konfiguration einmal nicht lesbar ist, wird **nichts mehr geschrieben** —
bisher waeren aus einem misslungenen Lesen Vorgaben geworden, und die haetten
sich ueber die echte Konfiguration *und* ueber die Sicherung gelegt.

**`zustaende.json` verlor Eintraege.** Jeder Aufruf von
`index.php?aktion=zustand` laeuft in einem eigenen PHP-Prozess; Loxone schickt
Zustaende gern mehrere auf einmal. Ohne Sperre lesen zwei Prozesse denselben
Stand, aendern je einen Namen und schreiben beide zurueck. Zwei Prozesse mit je
400 Namen, erwartet 800:

| Verfahren | angekommen |
|---|---|
| bisher (lesen, aendern, schreiben) | **32** |
| mit Sperre + `rename()` | **800** |

Dieselbe Lehre wie bei der Bremse in 0.9.1, nur eine Datei weiter: Unteilbares
Umbenennen allein genuegt nicht, die Sperre muss ueber Lesen *und* Schreiben
liegen. Sie liegt auf einer eigenen Datei — `rename()` haengt eine neue Datei
in den Namen, eine Sperre auf der alten bewachte danach nichts mehr.

**An den Loxone-Adressen und am Verhalten des Bots aendert sich nichts.**

## Was in 0.9.1 nachgemessen und geaendert wurde

Drei Beanstandungen aus einer Durchsicht wurden nachgestellt, bevor etwas
geaendert wurde. Zwei trafen zu, eine nicht.

**Ereignisstrom: keine rasende Schleife.** Behauptet war, `continue` nach einer
Zeitgrenze in `sg_lauschen()` ergebe eine Schleife mit voller Prozessorlast.
Gemessen gegen ein Gegenstueck, das die Kopfzeilen schickt und dann schweigt,
bei einer Zeitgrenze von einer Sekunde: **6,0 s Laufzeit, 6 Schleifenrunden,
also 1,0 Runden je Sekunde** — in PHP 7.4 wie in 8.1. `fgets` blockiert nach
einer Zeitgrenze erneut. Die Stelle wurde trotzdem geaendert, aber aus einem
anderen Grund: Ein Strom kann tot sein, ohne dass das Betriebssystem es merkt
(signal-cli neu gestartet, NAT-Eintrag abgelaufen). Dann wartet der Bot stumm
weiter, und genau dann gehen Nachrichten verloren. Nach fuenf stillen Minuten
wird jetzt neu verbunden, mit einer Meldung, die hoechstens einmal je Stunde
im Protokoll landet.

**Bremse: atomares Umbenennen reicht nicht.** Vier Prozesse haben gleichzeitig
je 400 Eintraege in die Bremsdatei geschrieben, erwartet waren 1600:

| Verfahren | angekommen |
|---|---|
| bisher (lesen, aendern, schreiben) | 34 |
| nur atomar umbenannt | 437 |
| mit `flock` ueber Lesen *und* Schreiben | **1600** |

Das mittlere Ergebnis ist der Kern: Ein atomarer Austausch der Datei
verhindert eine halb geschriebene Datei, aber nicht den verlorenen Schreibzugriff
— wer auf einem veralteten Stand aufsetzt, ueberschreibt fremde Eintraege
vollstaendig. Ohne Sperre haette ein Angreifer die PIN-Bremse durch
gleichzeitige Versuche einfach leerlaufen lassen. Gegenprobe mit Grenze 5:
5 durchgelassen, 15 abgewiesen.

**Adresse des RPC-Dienstes.** `rpc_url` wird jetzt auf Schema, Rechner und Port
zurueckgeschnitten, bevor sie gespeichert wird; ein angehaengter Pfad wird
verworfen, `file://` und alles andere ausser `http`/`https` abgelehnt (9 Faelle
geprueft). Der Einwand, das Plugin haenge ohnehin `/api/v1/rpc` an und ein
fremder Pfad sei deshalb harmlos, geht daneben: Rechner und Port sind frei
waehlbar, und ein Aufruf, den der Server nicht versteht, sagt einem Angreifer
ueber die Antwortzeit trotzdem, ob dort etwas horcht.

**Nebenbefund: php-mbstring war nicht angemeldet.** Fuenf Stellen riefen
`mb_strtolower`/`mb_strlen`/`mb_substr` direkt auf. Mit einem PHP ohne diese
Erweiterung nachgestellt: weisse Seite in der Oberflaeche *und* ein toter
Endpunkt — Loxone haette auf jede Meldung eine leere Antwort bekommen.
`php-mbstring` steht jetzt in `dpkg/apt`; Laenge und Ausschnitt kommen
zusaetzlich ohne die Erweiterung aus (PCRE mit `/u`), damit eine misslungene
Nachinstallation den Bot nicht stumm schaltet.

**Protokoll leeren.** Kuerzen und Leeren laufen jetzt beide ueber
`sg_log_setzen()` mit `flock` und `ftruncate`. Gegenprobe: rund 4100
Protokollzeilen aus drei Prozessen, waehrenddessen 148 Leerungen —
**0 zerrissene Zeilen** in PHP 7.4 und 8.1.

## Lizenz

Dieses Plugin steht unter der **GPL-3.0**. signal-cli ist ein eigenstaendiges
Projekt (ebenfalls GPL-3.0) und wird bei der Installation heruntergeladen, nicht
mitgeliefert.
