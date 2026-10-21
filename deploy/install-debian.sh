#!/usr/bin/env bash
#
# PowerManagement auf einem blanken Debian 13 (Trixie) einrichten – gedacht
# für einen LXC-Container unter Proxmox. Danach laufen im Container:
#
#   nginx + PHP-FPM   Oberfläche unter http://<IP>/, Node-RED-Editor unter /node-red/
#   Mosquitto         MQTT-Broker für die Shellys und den Sammler (Port 1883)
#   Node-RED          übersetzt Shelly-Themen, schreibt alles in die InfluxDB
#   InfluxDB 2        Datenbank „strom" (Bucket), abgefragt per InfluxQL
#   Sammler           Modbus TCP (Victron GX, ComAp) und Cloud-Abfragen
#
# Ein Tunnel oder Reverse-Proxy nach außen (z. B. Pangolin) und WireGuard-
# Tunnel zu entfernten Standorten richtet dieses Skript nicht ein – siehe
# docs/installation-debian-lxc.md.
#
# Beispiele:
#   ./deploy/install-debian.sh --name="Stromversorgung Messe Nord"
#   ./install-debian.sh --repo=https://github.com/agrotevent/powermanagement.git
#   ./deploy/install-debian.sh --ohne-influx --influx-url=http://10.0.0.5:8086 --influx-token=…
#
# Das Skript ist wiederholbar: Erledigtes wird übersprungen, erzeugte
# Kennwörter stehen in /etc/powermanagement/zugaenge.txt und werden beim
# nächsten Lauf wiederverwendet. Eigene Änderungen an den Node-RED-Flows,
# an config/config.php und an der Datenbank bleiben unangetastet – auch
# Titel und Zugänge, die in der Oberfläche geändert wurden: In eine
# vorhandene Datenbank trägt das Skript nur ein, was dort noch fehlt. Nur den
# Zugang der Anwendung zum Broker im Container schreibt es jedes Mal neu; er
# muss zur Kennwortdatei passen, die jeder Lauf schreibt.

set -euo pipefail

# Auch ohne Login-Shell lauffähig (systemd-run, Dienst): ohne HOME brach git
# ab (Befund 24). Die umask des Aufrufers gilt nie – Code, Einheiten und
# Vorlagen, die hier entstehen, müssen für andere lesbar sein.
: "${HOME:=/root}"
export HOME
umask 022
# git fragt nie nach (niemand tippt), und ein hängender Abruf bricht ab.
export GIT_TERMINAL_PROMPT=0 GIT_HTTP_LOW_SPEED_LIMIT=1000 GIT_HTTP_LOW_SPEED_TIME=60

# Die Optionen, wie sie kamen – für den Neustart mit einer neueren Fassung
# dieses Skripts (siehe „Anwendung nach … bringen“).
PM_ARGS=("$@")

ZIEL="/opt/powermanagement"
APP_USER="www-data"
TITEL=""
REPO=""
ZWEIG="main"
ZWEIG_GESETZT="nein"
PORT="80"
LISTEN="0.0.0.0"
SERVER_NAME="powermanagement.lan"
ADMIN_USER="admin"
MIT_INFLUX="ja"
MIT_NODERED="ja"
MIT_MOSQUITTO="ja"
INFLUX_URL_EXTERN=""
INFLUX_TOKEN_EXTERN=""
INFLUX_DB="strom"
INFLUX_ORG="powermanagement"
AUFBEWAHRUNG="0"
# Fingerabdruck des Paketschlüssels von InfluxData (Hauptschlüssel, seit
# 2023 unverändert; bei einer Verlängerung ändert sich nur das Ablaufdatum).
# Veröffentlicht unter https://docs.influxdata.com – „Install InfluxDB".
INFLUX_SCHLUESSEL="24C975CBA61A024EE1B631787C3D57159FC2F927"
MQTT_HOST_EXTERN=""
MQTT_PORT="1883"
MQTT_BENUTZER_EXTERN=""
MQTT_KENNWORT_EXTERN=""
FLOWS_ERSETZEN="nein"
GIT_CRED_FILE=""

ETC="/etc/powermanagement"
ZUGAENGE="$ETC/zugaenge.txt"
INSTALLATION_CONF="$ETC/installation.conf"
NODERED_APP="/opt/node-red"
NODERED_DIR="/var/lib/powermanagement/node-red"
NODERED_ENV="$ETC/node-red.env"
VORLAGEN="/var/lib/powermanagement/vorlagen"
INFLUX_LOKAL="http://127.0.0.1:8086"

hilfe() {
    cat <<HILFE
Aufruf: $0 [Optionen]

  --name=<titel>          Titel der Oberfläche (bei vorhandener Datenbank nur,
                          solange keiner eingetragen ist)
  --repo=<git-url>        Git-Repository, aus dem installiert wird. Ohne Angabe:
                          der Checkout, in dem dieses Skript liegt, oder GIT_REPO
                          aus /etc/powermanagement/git.conf
  --zweig=<name>          Zweig                          (Vorgabe: $ZWEIG)
  --ziel=<pfad>           Installationsverzeichnis       (Vorgabe: $ZIEL)
  --port=<port>           Port der Weboberfläche         (Vorgabe: $PORT)
  --listen=<adresse>      Adresse, auf der nginx lauscht (Vorgabe: $LISTEN = alle)
  --server=<name>         interner Servername für nginx  (Vorgabe: $SERVER_NAME)
  --admin=<name>          erster Administrator           (Vorgabe: $ADMIN_USER)
  --git-zugang=<datei>    Token für ein privates Repository
                          (Vorgabe: /etc/powermanagement/git.conf, Inhalt GITHUB_TOKEN=…)

  --ohne-influx           keine InfluxDB im Container – eine vorhandene nutzen:
    --influx-url=<url>      z. B. http://10.0.0.5:8086 (Pflicht)
    --influx-token=<token>  API-Token (2.x/3.x) bzw. benutzer:kennwort (1.8)
  --influx-db=<name>      Datenbank bzw. Bucket          (Vorgabe: $INFLUX_DB)
  --influx-org=<name>     Organisation in InfluxDB 2     (Vorgabe: $INFLUX_ORG)
  --aufbewahrung=<dauer>  wie lange Messwerte bleiben: 0 = unbegrenzt, sonst
                          Tage (400) oder z. B. 52w, 8760h (Vorgabe: $AUFBEWAHRUNG)
  --influx-schluessel=<fingerabdruck>
                          anderer Fingerabdruck für den Paketschlüssel von
                          InfluxData (nur nötig, wenn InfluxData ihn wechselt)

  --ohne-mosquitto        keinen Broker im Container – einen vorhandenen nutzen:
    --mqtt-host=<adresse>   (Pflicht)
    --mqtt-port=<port>      (Vorgabe: $MQTT_PORT)
    --mqtt-benutzer=<name> --mqtt-kennwort=<kennwort>
  --ohne-nodered          kein Node-RED (Shelly-Übersetzung und Schreiben in die
                          InfluxDB übernimmt dann etwas anderes)
  --flows-ersetzen        vorhandene Node-RED-Flows durch die mitgelieferten
                          ersetzen (die alten werden gesichert)
  -h, --hilfe             diese Hilfe
HILFE
}

# Optionen als --name=wert oder --name wert
while [[ $# -gt 0 ]]; do
    opt="$1"
    shift
    wert=""
    hat_wert="nein"
    if [[ "$opt" == --*=* ]]; then
        wert="${opt#*=}"
        opt="${opt%%=*}"
        hat_wert="ja"
    fi
    case "$opt" in
        --name|--repo|--zweig|--ziel|--port|--listen|--server|--admin|--git-zugang|\
        --influx-url|--influx-token|--influx-db|--influx-org|--aufbewahrung|--influx-schluessel|\
        --mqtt-host|--mqtt-port|--mqtt-benutzer|--mqtt-kennwort)
            if [[ "$hat_wert" == "nein" ]]; then
                if [[ $# -eq 0 ]]; then
                    echo "Die Option $opt braucht einen Wert." >&2
                    exit 1
                fi
                wert="$1"
                shift
            fi ;;
        *)
            if [[ "$hat_wert" == "ja" ]]; then
                echo "Die Option $opt nimmt keinen Wert." >&2
                exit 1
            fi ;;
    esac
    case "$opt" in
        --name)              TITEL="$wert" ;;
        --repo)              REPO="$wert" ;;
        --zweig)             ZWEIG="$wert"; ZWEIG_GESETZT="ja" ;;
        --ziel)              ZIEL="${wert%/}" ;;
        --port)              PORT="$wert" ;;
        --listen)            LISTEN="$wert" ;;
        --server)            SERVER_NAME="$wert" ;;
        --admin)             ADMIN_USER="$wert" ;;
        --git-zugang)        GIT_CRED_FILE="$wert" ;;
        --influx-url)        INFLUX_URL_EXTERN="${wert%/}" ;;
        --influx-token)      INFLUX_TOKEN_EXTERN="$wert" ;;
        --influx-db)         INFLUX_DB="$wert" ;;
        --influx-org)        INFLUX_ORG="$wert" ;;
        --aufbewahrung)      AUFBEWAHRUNG="$wert" ;;
        --influx-schluessel) INFLUX_SCHLUESSEL="$(printf '%s' "$wert" | tr -d ' ' | tr '[:lower:]' '[:upper:]')" ;;
        --mqtt-host)         MQTT_HOST_EXTERN="$wert" ;;
        --mqtt-port)         MQTT_PORT="$wert" ;;
        --mqtt-benutzer)     MQTT_BENUTZER_EXTERN="$wert" ;;
        --mqtt-kennwort)     MQTT_KENNWORT_EXTERN="$wert" ;;
        --ohne-influx)       MIT_INFLUX="nein" ;;
        --ohne-nodered)      MIT_NODERED="nein" ;;
        --ohne-mosquitto)    MIT_MOSQUITTO="nein" ;;
        --flows-ersetzen)    FLOWS_ERSETZEN="ja" ;;
        -h|--hilfe|--help)   hilfe; exit 0 ;;
        *) echo "Unbekannte Option: $opt" >&2; hilfe >&2; exit 1 ;;
    esac
done

# ------------------------------------------------------------- Prüfungen
if [[ $EUID -ne 0 ]]; then
    echo "Bitte als root ausführen (oder mit sudo)." >&2
    exit 1
fi
if [[ ! "$PORT" =~ ^[0-9]+$ || ! "$MQTT_PORT" =~ ^[0-9]+$ ]]; then
    echo "--port und --mqtt-port erwarten eine Zahl." >&2
    exit 1
fi
if [[ "$ZIEL" != /* ]]; then
    echo "--ziel muss ein absoluter Pfad sein." >&2
    exit 1
fi
if [[ ! "$AUFBEWAHRUNG" =~ ^[0-9]+[hdw]?$ ]]; then
    echo "--aufbewahrung erwartet 0 (unbegrenzt), Tage (400) oder z. B. 52w, 8760h." >&2
    exit 1
fi
case "$AUFBEWAHRUNG" in
    *w) AUFBEWAHRUNG_S=$(( ${AUFBEWAHRUNG%w} * 604800 )) ;;
    *h) AUFBEWAHRUNG_S=$(( ${AUFBEWAHRUNG%h} * 3600 )) ;;
    *d) AUFBEWAHRUNG_S=$(( ${AUFBEWAHRUNG%d} * 86400 )) ;;
    *)  AUFBEWAHRUNG_S=$(( AUFBEWAHRUNG * 86400 )) ;;
esac
if (( AUFBEWAHRUNG_S == 0 )); then
    AUFBEWAHRUNG_TEXT="unbegrenzt"
elif (( AUFBEWAHRUNG_S < 3600 )); then
    echo "--aufbewahrung: mindestens eine Stunde (InfluxDB lehnt kürzere ab)." >&2
    exit 1
else
    AUFBEWAHRUNG_TEXT="$AUFBEWAHRUNG"
fi
if [[ "$MIT_INFLUX" == "nein" && -z "$INFLUX_URL_EXTERN" ]]; then
    echo "Mit --ohne-influx bitte die vorhandene Datenbank angeben: --influx-url=… --influx-token=…" >&2
    exit 1
fi
if [[ "$MIT_MOSQUITTO" == "nein" && -z "$MQTT_HOST_EXTERN" ]]; then
    echo "Mit --ohne-mosquitto bitte den vorhandenen Broker angeben: --mqtt-host=… --mqtt-benutzer=… --mqtt-kennwort=…" >&2
    exit 1
fi
if [[ ! "$INFLUX_DB" =~ ^[A-Za-z0-9_.-]+$ ]]; then
    echo "--influx-db darf nur Buchstaben, Ziffern, _ . - enthalten." >&2
    exit 1
fi

# Farben nur am Terminal – in Protokolldateien und im Journal stünden sonst
# Steuerzeichen.
if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
    F_S=$'\033[1;34m'; F_H=$'\033[1;33m'; F_0=$'\033[0m'
else
    F_S=""; F_H=""; F_0=""
fi
schritt() { printf '\n%s==> %s%s\n' "$F_S" "$1" "$F_0"; }
hinweis() { printf '%s    %s%s\n' "$F_H" "$1" "$F_0" >&2; }

if [[ -r /etc/os-release ]]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    if [[ "${ID:-}" != "debian" || "${VERSION_CODENAME:-}" != "trixie" ]]; then
        hinweis "Gedacht für Debian 13 (trixie), gefunden: ${PRETTY_NAME:-unbekannt}. Es geht trotzdem weiter."
    fi
fi

# Fünf Dienste, die nach einem Neustart von selbst wiederkommen und sich
# gegenseitig abwarten müssen – das geht vernünftig nur mit systemd. Die
# Debian-Vorlagen für Proxmox-LXC bringen es mit.
if [[ ! -d /run/systemd/system ]] || ! command -v systemctl >/dev/null 2>&1; then
    echo "Hier läuft kein systemd. PowerManagement braucht es (Debian-13-Vorlage verwenden)." >&2
    exit 1
fi

# Nie gleichzeitig mit einem Update (deploy/update-debian.sh): beide
# schreiben dieselben Dateien und starten dieselben Dienste neu. Dieselbe
# Sperre wie dort – ein laufendes Update darf zu Ende kommen. Nach dem
# Neustart mit einer neueren Fassung (unten) ist sie geerbt: fd 9 bleibt
# über exec offen und gesperrt.
install -d -m 750 "$ETC"
if [[ "${PM_UPDATE_SPERRE:-}" == "geerbt" ]]; then
    if ! flock -n 9 2>/dev/null; then
        echo "PM_UPDATE_SPERRE=geerbt, aber die Sperre (fd 9) fehlt." >&2
        exit 1
    fi
else
    exec 9>>"$ETC/update.lock"
    chmod 600 "$ETC/update.lock"
    if ! flock -n 9; then
        echo "    Warte auf ein laufendes Update …"
        if ! flock -w 900 9; then
            echo "Ein Update läuft – später erneut." >&2
            exit 1
        fi
    fi
fi
# Ein unterbrochenes Update (Marke von deploy/update-debian.sh) rollt der
# Update-Dienst noch zurück – auf den Stand von damals, samt Datenbank. Was
# diese Installation jetzt einspielte, ginge dabei verloren: erst zurückrollen.
if [[ -e /var/lib/powermanagement/update/offen ]]; then
    echo "Ein früheres Update wurde unterbrochen und ist noch nicht zurückgerollt." >&2
    echo "Erst: $ZIEL/deploy/update-debian.sh --wiederaufnehmen – dann diesen Aufruf wiederholen." >&2
    exit 1
fi

# Sammler, Wartung, Node-RED, der MQTT-Helfer und der Update-Zeitplan laufen
# gehärtet (PrivateTmp, ProtectSystem) und brauchen dafür eigene
# Mount-Namensräume. Ein LXC ohne „nesting“ kann sie nicht einrichten – die
# Dienste scheitern dann mit 226/NAMESPACE, ohne dass ein Schritt hier
# fehlschlägt (Befund 11). Nur ein Hinweis, kein Abbruch: der Rest der
# Installation gelingt trotzdem, und ein zweiter Lauf soll nicht an einer
# Probe scheitern, die nichts kaputt macht.
NAMENSRAEUME="ja"
NAMENSRAEUME_TEXT="Der Container kann keine Namensräume einrichten (Proxmox: pct set <CTID> --features nesting=1, Container neu starten) – Sammler, Wartung, Node-RED, MQTT-Helfer und Update-Zeitplan brauchen sie."
if ! timeout 60 systemd-run --quiet --wait --collect -p PrivateTmp=yes -p ProtectSystem=strict /bin/true >/dev/null 2>&1; then
    NAMENSRAEUME="nein"
    hinweis "$NAMENSRAEUME_TEXT"
fi

# Befehl als Anwendungsbenutzer im Anwendungsverzeichnis ausführen.
# runuser gibt es auf Debian immer; sudo nicht zwingend. Die Sperren (fd 8,
# fd 9) bekommt er nicht mit: ein Prozess, der den Deskriptor erbt und
# weiterläuft, hielte sie fest.
as_app() {
    (cd "$ZIEL" && runuser -u "$APP_USER" -- "$@") 8>&- 9>&-
}

# git als root: safe.directory gilt nur für den einen Aufruf – keine globale
# Einstellung (ohne HOME bräche „git config --global“ ab, und ein Eintrag
# bliebe für immer stehen). Hooks aus .git laufen nie (core.hooksPath).
#   git_in <verzeichnis> <git-argumente…>      g <git-argumente…>  (= git_in "$ZIEL")
git_in() {
    local verzeichnis="$1"
    shift
    git -c safe.directory="$verzeichnis" -c core.hooksPath=/dev/null -C "$verzeichnis" "$@"
}
g() {
    git_in "$ZIEL" "$@"
}

# Ausgaben von git ohne Benutzerteil in Adressen – stünden Zugangsdaten in
# einer Remote-Adresse, kämen sie sonst ins Protokoll.
ohne_zugangsdaten() { sed -E 's#(://)[^/@[:space:]]+@#\1#g'; }

# apt unbeaufsichtigt: auf die dpkg-Sperre warten (apt-daily und
# unattended-upgrades laufen im Hintergrund), bei geänderten Conffiles nie
# fragen, sondern die vorhandene Fassung behalten. „apt-get update“ wartet
# trotz Lock::Timeout nicht auf die Sperre der Paketlisten – daher die Schleife.
APT=(apt-get -o DPkg::Lock::Timeout=600 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)
apt_holen() {
    local n=0
    until "${APT[@]}" update -qq; do
        if (( ++n >= 20 )); then
            echo "apt-get update scheitert weiter – Netz und Paketquellen prüfen." >&2
            return 1
        fi
        hinweis "apt ist belegt oder nicht erreichbar – neuer Versuch in 15 s"
        sleep 15
    done
}

# Ein Verzeichnis in storage/ anlegen – als Anwendungsbenutzer, nicht als
# root. storage/ gehört der Anwendung: liegt dort ein Verweis (etwa
# storage/logs → /etc/profile.d), übereignete ein „install -d -o“ als root
# dessen Ziel der Anwendung. Als sie selbst angelegt, bekommt sie nur, was
# sie ohnehin darf.
app_verzeichnis() {
    as_app mkdir -p -- "$1"
    as_app chmod "${2:-750}" -- "$1"
}

# Zufallskennwort nur aus Buchstaben und Ziffern: lässt sich in der Shelly-
# Oberfläche, in URLs und Konfigurationsdateien ohne Maskieren eintragen.
zufall() {
    local laenge="${1:-24}"
    openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | cut -c "1-$laenge"
}

# IP, unter der der Container im lokalen Netz erreichbar ist.
primary_ip() {
    local ip=""
    if command -v ip >/dev/null 2>&1; then
        ip="$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p' | head -1)"
        [[ -z "$ip" ]] && ip="$(ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -1)"
    fi
    [[ -z "$ip" ]] && ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    echo "${ip:-127.0.0.1}"
}

# Einen Wert aus JSON auf der Standardeingabe holen – erster Schlüssel dieses
# Namens, egal wie tief. Die Ausgaben von influx und der API unterscheiden
# sich je nach Fassung darin, ob sie ein Objekt oder eine Liste liefern.
json_wert() {
    php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        $suche = function ($d, $k) use (&$suche) {
            if (!is_array($d)) { return null; }
            if (array_key_exists($k, $d) && is_scalar($d[$k])) { return $d[$k]; }
            foreach ($d as $v) { $r = $suche($v, $k); if ($r !== null) { return $r; } }
            return null;
        };
        $r = $suche($d, $argv[1]);
        echo is_bool($r) ? ($r ? "true" : "false") : (string) $r;
    ' "$1"
}

# ----------------------------------------------------- Zugangsdaten ablegen
#
# Alle erzeugten Kennwörter und Tokens stehen in /etc/powermanagement/
# zugaenge.txt (nur root). Jeder Wert wird sofort geschrieben, nicht erst am
# Ende: bricht ein Lauf ab, findet der nächste sie wieder. Bei der InfluxDB
# ist das wichtig – ihre Ersteinrichtung geht nur einmal, und ohne das dabei
# erzeugte Token käme danach niemand mehr hinein.
install -d -m 750 "$ETC"
[[ -f "$ZUGAENGE" ]] || install -m 600 /dev/null "$ZUGAENGE"
chmod 600 "$ZUGAENGE"

zugang() {
    sed -n "s/^$1=//p" "$ZUGAENGE" | tail -1
}

zugang_setzen() {
    local schluessel="$1" wert="$2" tmp
    if [[ "$wert" == *$'\n'* ]]; then
        echo "Wert für $schluessel enthält einen Zeilenumbruch – nicht ablegbar." >&2
        exit 1
    fi
    tmp="$(mktemp "$ETC/.zugaenge.XXXXXX")"
    grep -v "^$schluessel=" "$ZUGAENGE" > "$tmp" || true
    printf '%s=%s\n' "$schluessel" "$wert" >> "$tmp"
    chmod 600 "$tmp"
    mv "$tmp" "$ZUGAENGE"
}

# Vorhandenen Wert nehmen oder einen neuen erzeugen und sofort ablegen.
zugang_oder_neu() {
    local schluessel="$1" wert
    wert="$(zugang "$schluessel")"
    if [[ -z "$wert" ]]; then
        wert="$(zufall "${2:-24}")"
        zugang_setzen "$schluessel" "$wert"
    fi
    printf '%s' "$wert"
}

# ------------------------------------------------ mitgelieferte Vorlagen
#
# Dateien, die nach der Installation jemand von Hand anpassen könnte
# (Node-RED-Flows und -Einstellungen, Mosquitto-ACL): Neu ersetzt wird nur,
# was seit der letzten Auslieferung niemand verändert hat. Dafür bleibt eine
# Kopie jeder ausgelieferten Fassung in /var/lib/powermanagement/vorlagen.
# Ist die Datei verändert, kommt die neue Fassung daneben und es gibt einen
# Hinweis – überschrieben wird nichts.
#
#   vorlage_uebernehmen <quelle> <ziel> <besitzer:gruppe> <modus> <neu-datei> [erzwingen]
#   Ergebnis in VORLAGE_ERGEBNIS: neu | gleich | ersetzt | behalten
VORLAGE_ERGEBNIS=""
vorlage_uebernehmen() {
    local quelle="$1" ziel="$2" besitzer="$3" modus="$4" neu="$5" erzwingen="${6:-nein}"
    local merk
    merk="$VORLAGEN/$(printf '%s' "$ziel" | tr '/' '_')"
    install -d -m 700 "$VORLAGEN"

    if [[ ! -e "$ziel" ]]; then
        VORLAGE_ERGEBNIS="neu"
    elif cmp -s "$quelle" "$ziel"; then
        VORLAGE_ERGEBNIS="gleich"
    elif [[ "$erzwingen" == "ja" ]]; then
        # Umbenennen statt „cp -p“: cp folgte als root einem Verweis, den der
        # Besitzer des Ordners (nodered) unter dem Namen der Sicherung angelegt
        # hat, und überschriebe dessen Ziel (Befund 8). rename folgt keinem
        # Verweis; install legt die Datei unten neu an.
        mv -fT -- "$ziel" "$ziel.vor-$(date +%Y%m%d-%H%M%S)"
        VORLAGE_ERGEBNIS="ersetzt"
    elif [[ -f "$merk" ]] && cmp -s "$merk" "$ziel"; then
        VORLAGE_ERGEBNIS="ersetzt"
    else
        install -o "${besitzer%%:*}" -g "${besitzer##*:}" -m "$modus" "$quelle" "$neu"
        VORLAGE_ERGEBNIS="behalten"
        return 0
    fi
    if [[ "$VORLAGE_ERGEBNIS" != "gleich" ]]; then
        install -o "${besitzer%%:*}" -g "${besitzer##*:}" -m "$modus" "$quelle" "$ziel"
    fi
    cp "$quelle" "$merk"
    # Eine liegengebliebene -neu-Datei ist jetzt überholt.
    rm -f "$neu"
}

# ------------------------------------------------- Zugangsdaten für git
#
# Ein privates Repository braucht ein Zugangstoken. Es steht in einer
# Textdatei auf dem Container – vorgesehen ist /etc/powermanagement/git.conf:
#
#   GITHUB_TOKEN=github_pat_...
#   GIT_REPO=https://github.com/agrotevent/powermanagement.git   # optional
#   GIT_BRANCH=main                                              # optional
#
# Daraus entsteht /etc/powermanagement/git-credentials, das git selbst liest.
# Das Token steht damit nie in der Kommandozeile (und nicht in der
# Prozessliste), nie in .git/config und nie in der Adresse des Remotes.
GIT_STORE="$ETC/git-credentials"
GIT_CONF_REPO=""
GIT_CONF_BRANCH=""
GIT_TOKEN=""
GIT_TOKEN_USER=""
GIT_CRED_LINE=""

zugangsdatei_finden() {
    local kandidat
    for kandidat in "$ETC/git.conf" "$ETC/git.txt" "$ETC/github.txt" /root/powermanagement-git.txt; do
        if [[ -f "$kandidat" ]]; then
            printf '%s' "$kandidat"
            return 0
        fi
    done
    return 1
}

urlenc() {
    local s="$1" out="" i c
    for (( i = 0; i < ${#s}; i++ )); do
        c="${s:i:1}"
        case "$c" in
            [A-Za-z0-9._~-]) out+="$c" ;;
            *)               out+="$(printf '%%%02X' "'$c")" ;;
        esac
    done
    printf '%s' "$out"
}

zugangsdaten_lesen() {
    local datei="$1" zeile schluessel wert
    if [[ ! -r "$datei" ]]; then
        echo "Die Zugangsdatei $datei lässt sich nicht lesen." >&2
        exit 1
    fi
    while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        zeile="${zeile%$'\r'}"
        zeile="${zeile#"${zeile%%[![:space:]]*}"}"
        zeile="${zeile%"${zeile##*[![:space:]]}"}"
        [[ -z "$zeile" || "$zeile" == \#* ]] && continue
        case "$zeile" in
            *://*:*@*)
                GIT_CRED_LINE="$zeile" ;;
            *=*)
                schluessel="${zeile%%=*}"
                wert="${zeile#*=}"
                schluessel="$(printf '%s' "$schluessel" | tr -d '[:space:]' | tr '[:lower:]' '[:upper:]')"
                wert="${wert#"${wert%%[![:space:]]*}"}"
                wert="${wert%"${wert##*[![:space:]]}"}"
                wert="${wert#[\"\']}"
                wert="${wert%[\"\']}"
                case "$schluessel" in
                    GITHUB_TOKEN|GIT_TOKEN|TOKEN|GIT_PASSWORD|PASSWORD) GIT_TOKEN="$wert" ;;
                    GITHUB_USER|GIT_USER|USERNAME|USER)                 GIT_TOKEN_USER="$wert" ;;
                    GIT_REPO|REPO|REPOSITORY|URL)                       GIT_CONF_REPO="$wert" ;;
                    GIT_BRANCH|BRANCH)                                  GIT_CONF_BRANCH="$wert" ;;
                esac ;;
            *)
                # Eine Datei, in der nur das Token steht.
                [[ -z "$GIT_TOKEN" ]] && GIT_TOKEN="$zeile" ;;
        esac
    done < "$datei"
}

git_zugang_ablegen() {
    local repo="${1:-}" schema="https" host="github.com" benutzer zeile
    if [[ "$repo" == git@* || "$repo" == ssh://* ]]; then
        echo "    Repository über SSH – das Token wird nicht gebraucht."
        return 0
    fi
    if [[ -z "$GIT_CRED_LINE" && -z "$GIT_TOKEN" ]]; then
        echo "In $GIT_CRED_FILE steht kein Token (erwartet: GITHUB_TOKEN=...)." >&2
        exit 1
    fi
    if [[ -n "$GIT_CRED_LINE" ]]; then
        zeile="$GIT_CRED_LINE"
    else
        if [[ "$repo" =~ ^([a-zA-Z][a-zA-Z0-9+.-]*)://([^/@]*@)?([^/]+) ]]; then
            schema="${BASH_REMATCH[1]}"
            host="${BASH_REMATCH[3]}"
        fi
        # Bei GitHub ist der Benutzername neben einem Token beliebig.
        benutzer="${GIT_TOKEN_USER:-x-access-token}"
        zeile="$schema://$(urlenc "$benutzer"):$(urlenc "$GIT_TOKEN")@$host"
    fi
    install -m 600 /dev/null "$GIT_STORE"
    printf '%s\n' "$zeile" > "$GIT_STORE"
    chmod 600 "$GIT_CRED_FILE" 2>/dev/null || true
    echo "    Zugangsdaten aus $GIT_CRED_FILE übernommen (${zeile%%:*}://…@${zeile##*@})."
}

git_helper_setzen() {
    [[ -f "$GIT_STORE" ]] || return 0
    g config credential.helper "store --file=$GIT_STORE"
}

# ================================================================ Pakete
schritt "Pakete installieren"
export DEBIAN_FRONTEND=noninteractive
# needrestart (falls vorhanden) soll nicht mitten im Lauf Dienste neu starten
# oder nachfragen – das Skript startet seine Dienste selbst.
export NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1
apt_holen

PAKETE=(
    nginx php-fpm php-cli php-sqlite3 php-gd php-curl php-mbstring php-xml
    # rastert PDF-Grundrisse; ohne zeichnet sie der Browser (in Safari nur flach)
    poppler-utils
    git curl ca-certificates gnupg openssl logrotate
    # Werkzeuge für einen WireGuard-Tunnel im Container (Einrichtung: Doku)
    wireguard-tools
    # mosquitto_sub/_pub zur Fehlersuche – auch bei einem fremden Broker
    mosquitto-clients
)
[[ "$MIT_MOSQUITTO" == "ja" ]] && PAKETE+=(mosquitto)
# Node-RED 4 braucht Node.js ≥ 18; Debian 13 bringt 20 mit.
[[ "$MIT_NODERED" == "ja" ]] && PAKETE+=(nodejs npm)
"${APT[@]}" install -y --no-install-recommends "${PAKETE[@]}" >/dev/null

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
# Die FPM-Version kann von der CLI-Version abweichen (Reste einer älteren
# Installation) – maßgeblich ist, wo ein Pool-Verzeichnis liegt.
FPM_VERSION="$(find /etc/php -mindepth 2 -maxdepth 2 -type d -name fpm 2>/dev/null \
    | awk -F/ '{print $4}' | sort -V | tail -1)"
if [[ -z "$FPM_VERSION" ]]; then
    echo "Kein PHP-FPM gefunden (/etc/php/*/fpm). Paket phpX.Y-fpm installieren." >&2
    exit 1
fi
echo "    PHP-CLI $PHP_VERSION, PHP-FPM $FPM_VERSION"
if ! php -r 'exit((int) !(PHP_VERSION_ID >= 80200 && extension_loaded("sodium") && extension_loaded("pdo_sqlite") && extension_loaded("mbstring") && extension_loaded("curl") && extension_loaded("gd")));'; then
    echo "PHP ist älter als 8.2 oder es fehlen Erweiterungen (sodium, pdo_sqlite, mbstring, curl, gd)." >&2
    php -m | grep -E '^(sodium|pdo_sqlite|mbstring|curl|gd)$' | sed 's/^/    vorhanden: /' >&2 || true
    exit 1
fi

if [[ "$MIT_NODERED" == "ja" ]]; then
    NODE_HAUPT="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
    if (( NODE_HAUPT < 18 )); then
        echo "Node.js $NODE_HAUPT ist zu alt – Node-RED 4 braucht mindestens 18." >&2
        exit 1
    fi
    echo "    Node.js $(node --version), npm $(npm --version)"
fi

# ================================================================ Quellcode
schritt "Anwendung nach $ZIEL bringen"

SKRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
CHECKOUT=""
if [[ -f "$SKRIPT_DIR/../bin/power" && -f "$SKRIPT_DIR/../deploy/install-debian.sh" ]]; then
    CHECKOUT="$(cd "$SKRIPT_DIR/.." && pwd -P)"
fi

if [[ -z "$GIT_CRED_FILE" ]]; then
    GIT_CRED_FILE="$(zugangsdatei_finden || true)"
fi
if [[ -n "$GIT_CRED_FILE" ]]; then
    zugangsdaten_lesen "$GIT_CRED_FILE"
    # Repository und Zweig dürfen aus derselben Datei kommen – dann steht in
    # der Befehlszeile nichts Vertrauliches und nichts Langes.
    [[ -z "$REPO" && -n "$GIT_CONF_REPO" && -z "$CHECKOUT" ]] && REPO="$GIT_CONF_REPO"
    [[ "$ZWEIG_GESETZT" == "nein" && -n "$GIT_CONF_BRANCH" ]] && ZWEIG="$GIT_CONF_BRANCH" && ZWEIG_GESETZT="ja"
    ZUGANG_FUER="$REPO"
    if [[ -z "$ZUGANG_FUER" && -n "$CHECKOUT" && -d "$CHECKOUT/.git" ]]; then
        ZUGANG_FUER="$(git_in "$CHECKOUT" remote get-url origin 2>/dev/null || true)"
    fi
    git_zugang_ablegen "$ZUGANG_FUER"
fi

mkdir -p "$(dirname "$ZIEL")"

if [[ -d "$ZIEL/.git" ]]; then
    # Vorhandene Installation: auf den Stand des Zweigs bringen, aber nur
    # vorwärts – lokale Änderungen und abweichende Stände fasst das
    # Update-Skript an, nicht die Installation. Ohne --zweig bleibt der
    # ausgecheckte Zweig – nicht still main (Befund 3).
    if [[ "$ZWEIG_GESETZT" == "nein" ]]; then
        ZWEIG="$(g symbolic-ref --short -q HEAD || echo main)"
    fi
    git_helper_setzen
    STAND_VORHER="$(g rev-parse HEAD)"
    if timeout 600 git -c safe.directory="$ZIEL" -c core.hooksPath=/dev/null -C "$ZIEL" \
        fetch --quiet origin "$ZWEIG" 2>/dev/null; then
        if ! AUSCHECKEN="$(g checkout --quiet "$ZWEIG" 2>&1)"; then
            hinweis "Der Zweig $ZWEIG lässt sich in $ZIEL nicht auschecken – der Stand bleibt, wie er ist:"
            printf '%s\n' "$AUSCHECKEN" | sed 's/^/      /' >&2
        elif ! g merge --ff-only --quiet "origin/$ZWEIG" 2>/dev/null; then
            hinweis "$ZIEL lässt sich nicht einfach vorspulen (lokale Änderungen?) – bleibt, wie es ist."
            hinweis "Aktualisieren mit: $ZIEL/deploy/update-debian.sh"
        elif [[ -z "${PM_NEU_GESTARTET:-}" && "$(g rev-parse HEAD)" != "$STAND_VORHER" ]] \
            && g merge-base --is-ancestor "$STAND_VORHER" HEAD \
            && ! g diff --quiet "$STAND_VORHER" HEAD -- deploy/install-debian.sh; then
            # bash liest ein laufendes Skript Stück für Stück aus der Datei,
            # die es beim Start geöffnet hat – git hat sie eben ersetzt, nicht
            # überschrieben. Ohne Neustart liefe der Rest dieses Laufs noch in
            # der alten Fassung (Befunde 1, 7, 13, 16). Nur vorwärts: eine
            # ältere Fassung startet nie. Die Sperre (fd 9) geht mit.
            if bash -n "$ZIEL/deploy/install-debian.sh"; then
                echo "    Stand: $(g log -1 --format='%h %s')"
                echo "    Neue Fassung des Installationsskripts – neu gestartet."
                exec env PM_NEU_GESTARTET=1 PM_UPDATE_SPERRE=geerbt \
                    /bin/bash "$ZIEL/deploy/install-debian.sh" ${PM_ARGS[@]+"${PM_ARGS[@]}"}
            fi
            hinweis "Die neue Fassung von deploy/install-debian.sh ist fehlerhaft (bash -n) – dieser Lauf bleibt bei der bisherigen."
        fi
    else
        hinweis "Repository nicht erreichbar – vorhandener Stand in $ZIEL wird verwendet."
    fi
    echo "    Stand: $(g log -1 --format='%h %s')"
elif [[ -n "$REPO" ]]; then
    if [[ -e "$ZIEL" && -n "$(ls -A "$ZIEL" 2>/dev/null)" ]]; then
        echo "$ZIEL ist nicht leer und kein Git-Arbeitsverzeichnis – dorthin wird nicht geklont." >&2
        exit 1
    fi
    KLON_ZUGANG=()
    [[ -f "$GIT_STORE" ]] && KLON_ZUGANG=(-c "credential.helper=store --file=$GIT_STORE")
    if ! git ${KLON_ZUGANG[@]+"${KLON_ZUGANG[@]}"} -c core.hooksPath=/dev/null \
        clone --quiet --branch "$ZWEIG" "$REPO" "$ZIEL"; then
        echo "Das Repository ließ sich nicht klonen." >&2
        echo "Ist es privat? Dann ein Token hinterlegen:" >&2
        echo "  install -d -m 750 $ETC" >&2
        printf '  printf %sGITHUB_TOKEN=%%s\\n%s %s<token>%s > %s/git.conf\n' "'" "'" "'" "'" "$ETC" >&2
        echo "  chmod 600 $ETC/git.conf" >&2
        exit 1
    fi
    git_helper_setzen
    echo "    Geklont: $(g log -1 --format='%h %s')"
elif [[ -n "$CHECKOUT" && "$CHECKOUT" == "$(cd "$ZIEL" 2>/dev/null && pwd -P)" ]]; then
    echo "    Das Skript liegt bereits in $ZIEL – wird so verwendet."
elif [[ -n "$CHECKOUT" && -d "$CHECKOUT/.git" ]]; then
    # Aus dem Checkout, in dem dieses Skript liegt, nach --ziel klonen. Der
    # Remote zeigt danach auf dasselbe Repository wie der Checkout, nicht
    # auf dessen Verzeichnis – Updates kommen so aus dem Original.
    if [[ -e "$ZIEL" && -n "$(ls -A "$ZIEL" 2>/dev/null)" ]]; then
        echo "$ZIEL ist nicht leer und kein Git-Arbeitsverzeichnis – dorthin wird nicht geklont." >&2
        exit 1
    fi
    # Der Checkout gehört womöglich einem anderen Benutzer – dann verweigert
    # git ihn („dubious ownership“). Die Ausnahme gilt nur für diesen Klon:
    # -c für clone selbst und eigens für upload-pack, das clone für den
    # Checkout startet (dorthin reicht git -c nicht weiter). --no-local:
    # Objekte kopieren statt fest verlinken – sonst übereignete das chown
    # unten auch die Dateien im Checkout root.
    KLON_SICHER=(-c "safe.directory=$CHECKOUT" -c "safe.directory=$CHECKOUT/.git")
    printf -v UPLOAD_PACK '%q ' git "${KLON_SICHER[@]}" upload-pack
    git "${KLON_SICHER[@]}" -c core.hooksPath=/dev/null \
        clone --quiet --no-local --upload-pack="$UPLOAD_PACK" "$CHECKOUT" "$ZIEL"
    if [[ "$ZWEIG_GESETZT" == "ja" ]]; then
        g checkout --quiet "$ZWEIG"
    fi
    URSPRUNG="$(git_in "$CHECKOUT" remote get-url origin 2>/dev/null || true)"
    if [[ -n "$URSPRUNG" ]]; then
        g remote set-url origin "$URSPRUNG"
        git_helper_setzen
        timeout 600 git -c safe.directory="$ZIEL" -c core.hooksPath=/dev/null -C "$ZIEL" \
            fetch --quiet origin 2>/dev/null \
            || hinweis "$(printf '%s' "$URSPRUNG" | ohne_zugangsdaten) gerade nicht erreichbar – Updates holen es später."
    fi
    echo "    Aus $CHECKOUT übernommen: $(g log -1 --format='%h %s')"
elif [[ -n "$CHECKOUT" ]]; then
    # Entpacktes Archiv ohne .git – kopieren. Updates per git gehen dann
    # nicht; das sagt die Zusammenfassung.
    mkdir -p "$ZIEL"
    cp -a "$CHECKOUT/." "$ZIEL/"
    hinweis "Kein Git-Arbeitsverzeichnis – deploy/update-debian.sh funktioniert so nicht."
elif [[ -f "$ZIEL/bin/power" ]]; then
    echo "    Vorhandene Dateien in $ZIEL werden verwendet."
else
    echo "Weder --repo angegeben noch liegt dieses Skript in einem Checkout." >&2
    echo "Beispiel: $0 --repo=https://github.com/agrotevent/powermanagement.git" >&2
    exit 1
fi

# Läuft eine Kopie von anderswo (etwa ein früher heruntergeladenes
# /root/install-debian.sh), die nicht der Fassung in $ZIEL entspricht? Dann
# führt dieser Lauf womöglich veraltete Schritte aus.
if [[ -f "$ZIEL/deploy/install-debian.sh" \
      && "$SKRIPT_DIR" != "$(cd "$ZIEL/deploy" 2>/dev/null && pwd -P)" ]] \
    && ! cmp -s -- "${BASH_SOURCE[0]}" "$ZIEL/deploy/install-debian.sh"; then
    hinweis "Dieses Skript (${BASH_SOURCE[0]}) weicht von $ZIEL/deploy/install-debian.sh ab –"
    hinweis "beim nächsten Mal besser jenes aufrufen."
fi

# Der Quellcode gehört root – die Anwendung (www-data) kann ihn nicht
# verändern. Schreiben darf sie nur in storage/.
# Lesbar für alle (www-data muss den Code lesen), schreibbar nur für root.
# config/config.php und storage/ bekommen weiter unten eigene Rechte.
find "$ZIEL" -path "$ZIEL/storage" -prune -o ! -path "$ZIEL/config/config.php" \
    -exec chown root:root {} + -exec chmod go+rX,go-w {} +
install -d -o "$APP_USER" -g "$APP_USER" -m 750 "$ZIEL/storage"
# Was aus dem Repository kommt (storage/logs, cache, plans … mit .gitkeep),
# hat git als root angelegt – erst der Anwendung übereignen, sonst darf sie
# die Rechte daran nicht setzen. -R folgt keinen Verweisen (-P ist die
# Vorgabe) und ändert nur die Einträge selbst.
chown -R -P "$APP_USER:$APP_USER" "$ZIEL/storage"
# mqtt/: Soll und Ergebnis der eigenen MQTT-Zugänge (deploy/mqtt-zugaenge.sh);
# update/: Aufträge an den Update-Dienst (soll) und dessen Rückmeldungen (ist)
for d in logs plans demo cache inventar sessions backups mqtt mqtt/soll mqtt/ist update update/soll update/ist; do
    app_verzeichnis "$ZIEL/storage/$d"
done
# Nur für root: Marke eines laufenden Updates, Kopie der alten Fassung,
# Verlauf (/var/lib/…) und die Protokolle der Updates (/var/log/…)
install -d -m 750 /var/log/powermanagement
install -d -m 700 /var/log/powermanagement/update /var/lib/powermanagement/update

HOST_IP="$(primary_ip)"
if [[ "$PORT" == "80" ]]; then
    APP_URL="http://$HOST_IP"
else
    APP_URL="http://$HOST_IP:$PORT"
fi

# ================================================================ Konfiguration
schritt "Konfiguration erzeugen"
CONFIG="$ZIEL/config/config.php"
if [[ -f "$CONFIG" ]]; then
    echo "    config/config.php existiert bereits – bleibt unverändert"
else
    # Nur den Schlüssel setzen; alles andere (InfluxDB, MQTT, Titel) kommt
    # über „bin/power einstellung" in die Datenbank und ist damit auch in
    # der Oberfläche änderbar.
    APP_KEY="base64:$(openssl rand -base64 32)"
    # Die Datei hat die richtigen Rechte, bevor der Schlüssel hineinkommt –
    # mit umask 022 wäre sie sonst kurz für alle lesbar (Befund 12).
    # file_put_contents behält Besitzer und Modus. Erst fertig geschrieben
    # kommt sie an ihren Platz: ein abgebrochener Lauf hinterlässt keine leere
    # config.php, die der nächste für vorhanden hielte.
    CONFIG_NEU="$ZIEL/config/.config.php.neu"
    install -o root -g "$APP_USER" -m 640 /dev/null "$CONFIG_NEU"
    if ! PM_SCHLUESSEL="$APP_KEY" php -r '
        $inhalt = file_get_contents($argv[1]);
        $neu = str_replace("base64:CHANGEME000000000000000000000000000000000000", getenv("PM_SCHLUESSEL"), $inhalt, $anzahl);
        if ($anzahl !== 1) { fwrite(STDERR, "Platzhalter für app.key nicht gefunden\n"); exit(1); }
        if (file_put_contents($argv[2], $neu) !== strlen($neu)) { fwrite(STDERR, "config.php ließ sich nicht schreiben\n"); exit(1); }
    ' "$ZIEL/config/config.example.php" "$CONFIG_NEU"; then
        rm -f "$CONFIG_NEU"
        exit 1
    fi
    mv -f "$CONFIG_NEU" "$CONFIG"
    echo "    Neuer app.key erzeugt – er verschlüsselt die hinterlegten Zugänge. Mit sichern!"
fi
chown "root:$APP_USER" "$CONFIG"
chmod 640 "$CONFIG"

# ================================================================ Mosquitto
MQTT_HOST="127.0.0.1"
if [[ "$MIT_MOSQUITTO" == "ja" ]]; then
    schritt "MQTT-Broker (Mosquitto) einrichten"
    MQTT_SHELLY_KENNWORT="$(zugang_oder_neu MQTT_SHELLY_KENNWORT)"
    MQTT_NODERED_KENNWORT="$(zugang_oder_neu MQTT_NODERED_KENNWORT)"
    MQTT_PM_KENNWORT="$(zugang_oder_neu MQTT_POWERMANAGEMENT_KENNWORT)"
    MQTT_PM_BENUTZER="powermanagement"
    MQTT_NR_BENUTZER="nodered"

    # Mosquitto 2 liest Kennwort- und ACL-Datei erst nach dem Wechsel auf
    # seinen eigenen Benutzer und warnt, wenn sie jemand anderem gehören.
    # powermanagement.acl ist die Grundlage; Mosquitto liest die wirksame
    # Fassung, die deploy/mqtt-zugaenge.sh daraus baut (weiter unten).
    vorlage_uebernehmen "$ZIEL/deploy/mosquitto/powermanagement.acl" \
        /etc/mosquitto/powermanagement.acl mosquitto:mosquitto 600 \
        /etc/mosquitto/powermanagement.acl.powermanagement-neu
    if [[ "$VORLAGE_ERGEBNIS" == "behalten" ]]; then
        hinweis "/etc/mosquitto/powermanagement.acl ist verändert – neue Fassung liegt als .powermanagement-neu daneben."
        hinweis "Für eigene Zugänge je Shelly die pattern-Zeilen von dort übernehmen."
    fi

    # Kennwortdatei: vorhandene Einträge (etwa von Hand ergänzte Benutzer)
    # bleiben, die drei eigenen werden gesetzt. mosquitto_passwd selbst
    # möchte eine Datei, die root gehört, der Broker eine, die mosquitto
    # gehört – also auf einer Kopie arbeiten und danach übergeben.
    #
    # Nicht mit „mosquitto_passwd -b datei benutzer kennwort“: dann stünde
    # das Kennwort in der Prozessliste, die im Container jeder lesen kann.
    # Stattdessen die drei eigenen Einträge im Klartext in eine eigene
    # Datei (printf ist eingebaut, kein eigener Prozess), dort hashen (-U)
    # und in die vorhandene Datei übernehmen – deren übrige Einträge sind
    # schon gehasht und blieben es.
    PASSWD=/etc/mosquitto/powermanagement.passwd
    # Oben in der Datei steht womöglich der Block mit den eigenen Zugängen
    # der Shellys – den schreibt deploy/mqtt-zugaenge.sh unter derselben
    # Sperre. Der Abgleich hier lässt ihn stehen (keiner seiner Namen ist
    # shelly, nodered oder powermanagement). Die Sperrdatei liegt in
    # /etc/mosquitto (gehört root), nicht im für alle beschreibbaren
    # /run/lock, und nur root darf sie öffnen – sonst könnte jemand die
    # Sperre halten und den Helfer dauerhaft aussperren.
    UMASK_VORHER="$(umask)"
    umask 077
    exec 8>>/etc/mosquitto/powermanagement-mqtt-zugaenge.lock
    umask "$UMASK_VORHER"
    chmod 600 /etc/mosquitto/powermanagement-mqtt-zugaenge.lock
    if ! flock -w 30 8; then
        echo "Die Kennwortdatei ist gesperrt (läuft deploy/mqtt-zugaenge.sh?) – später erneut versuchen." >&2
        exit 1
    fi
    NEU_PASSWD="$(mktemp)"
    {
        printf 'shelly:%s\n' "$MQTT_SHELLY_KENNWORT"
        printf '%s:%s\n' "$MQTT_NR_BENUTZER" "$MQTT_NODERED_KENNWORT"
        printf '%s:%s\n' "$MQTT_PM_BENUTZER" "$MQTT_PM_KENNWORT"
    } > "$NEU_PASSWD"
    mosquitto_passwd -U "$NEU_PASSWD"
    TMP_PASSWD="$(mktemp)"
    if [[ -f "$PASSWD" ]]; then
        awk -F: -v a=shelly -v b="$MQTT_NR_BENUTZER" -v c="$MQTT_PM_BENUTZER" '$1 != a && $1 != b && $1 != c' "$PASSWD" > "$TMP_PASSWD"
    fi
    cat "$NEU_PASSWD" >> "$TMP_PASSWD"
    rm -f "$NEU_PASSWD"
    install -o mosquitto -g mosquitto -m 600 "$TMP_PASSWD" "$PASSWD"
    rm -f "$TMP_PASSWD"
    exec 8>&-

    # Eigene Zugänge je Shelly: Block in der Kennwortdatei prüfen und die
    # wirksame ACL bauen. Neu geladen wird hier nicht – der Broker startet
    # gleich ohnehin neu.
    HELFER_RC=0
    HELFER_MELDUNG="$(bash "$ZIEL/deploy/mqtt-zugaenge.sh" --ziel="$ZIEL" --app-benutzer="$APP_USER" \
        --neu-laden=nein 2>&1 >/dev/null)" || HELFER_RC=$?
    if [[ "$HELFER_RC" -ne 0 ]]; then
        hinweis "deploy/mqtt-zugaenge.sh meldet einen Fehler (Exit $HELFER_RC):"
        printf '%s\n' "$HELFER_MELDUNG" | sed 's/^/      /' >&2
    elif [[ -n "$HELFER_MELDUNG" ]]; then
        printf '%s\n' "$HELFER_MELDUNG" | sed 's/^/    /'
    fi
    if [[ ! -f /etc/mosquitto/powermanagement-wirksam.acl ]]; then
        # Ohne wirksame ACL startet der Broker nicht – dann wenigstens die
        # Grundlage (ohne Sperren).
        install -o mosquitto -g mosquitto -m 600 /etc/mosquitto/powermanagement.acl \
            /etc/mosquitto/powermanagement-wirksam.acl
    fi

    vorlage_uebernehmen "$ZIEL/deploy/mosquitto/broker.conf" \
        /etc/mosquitto/conf.d/powermanagement.conf root:root 644 \
        /etc/mosquitto/powermanagement.conf.powermanagement-neu
    [[ "$VORLAGE_ERGEBNIS" == "behalten" ]] && hinweis "/etc/mosquitto/conf.d/powermanagement.conf ist verändert – neue Fassung: /etc/mosquitto/powermanagement.conf.powermanagement-neu"

    systemctl enable --quiet mosquitto
    systemctl restart mosquitto
    echo "    Broker auf Port 1883, Benutzer: shelly, $MQTT_NR_BENUTZER, $MQTT_PM_BENUTZER"
else
    schritt "Vorhandenen MQTT-Broker verwenden"
    MQTT_HOST="$MQTT_HOST_EXTERN"
    MQTT_PM_BENUTZER="$MQTT_BENUTZER_EXTERN"
    MQTT_PM_KENNWORT="$MQTT_KENNWORT_EXTERN"
    MQTT_NR_BENUTZER="$MQTT_BENUTZER_EXTERN"
    MQTT_NODERED_KENNWORT="$MQTT_KENNWORT_EXTERN"
    MQTT_SHELLY_KENNWORT=""
    echo "    $MQTT_HOST:$MQTT_PORT, Benutzer ${MQTT_PM_BENUTZER:-(ohne)}"
fi

# ================================================================ InfluxDB
if [[ "$MIT_INFLUX" == "ja" ]]; then
    schritt "InfluxDB 2 installieren"
    if ! dpkg-query -W -f='${Status}' influxdb2 2>/dev/null | grep -q "install ok installed" \
        || ! command -v influx >/dev/null 2>&1; then
        # Paketquelle von InfluxData. Der Schlüssel wird über seinen
        # Fingerabdruck geprüft, nicht über eine Prüfsumme der Datei – die
        # ändert sich bei jeder Verlängerung des Schlüssels, der
        # Fingerabdruck nicht.
        install -d -m 755 /etc/apt/keyrings
        GNUPG_TMP="$(mktemp -d)"
        curl -fsSL --max-time 60 https://repos.influxdata.com/influxdata-archive.key -o "$GNUPG_TMP/schluessel.asc" || {
            echo "Der Paketschlüssel von InfluxData ließ sich nicht laden (repos.influxdata.com)." >&2
            echo "Netz prüfen – oder mit --ohne-influx eine vorhandene InfluxDB nutzen." >&2
            exit 1
        }
        FPR="$(gpg --homedir "$GNUPG_TMP" --show-keys --with-colons --with-fingerprint "$GNUPG_TMP/schluessel.asc" 2>/dev/null \
            | awk -F: '$1 == "fpr" { print $10; exit }')"
        if [[ "$FPR" != "$INFLUX_SCHLUESSEL" ]]; then
            echo "Der Paketschlüssel von InfluxData hat nicht den erwarteten Fingerabdruck." >&2
            echo "  erwartet: $INFLUX_SCHLUESSEL" >&2
            echo "  erhalten: ${FPR:-(keiner)}" >&2
            echo "Hat InfluxData den Schlüssel gewechselt, steht der neue unter" >&2
            echo "https://docs.influxdata.com/influxdb/v2/install/ – dann mit" >&2
            echo "--influx-schluessel=<fingerabdruck> erneut aufrufen." >&2
            rm -rf "$GNUPG_TMP"
            exit 1
        fi
        gpg --homedir "$GNUPG_TMP" --dearmor < "$GNUPG_TMP/schluessel.asc" > /etc/apt/keyrings/influxdata-archive.gpg
        chmod 644 /etc/apt/keyrings/influxdata-archive.gpg
        rm -rf "$GNUPG_TMP"
        echo 'deb [signed-by=/etc/apt/keyrings/influxdata-archive.gpg] https://repos.influxdata.com/debian stable main' \
            > /etc/apt/sources.list.d/influxdata.list
        apt_holen
        "${APT[@]}" install -y --no-install-recommends influxdb2 influxdb2-cli >/dev/null
    fi
    # Keine Nutzungsstatistik an InfluxData schicken.
    if [[ -f /etc/influxdb/config.toml ]] && ! grep -q '^reporting-disabled' /etc/influxdb/config.toml; then
        echo 'reporting-disabled = true' >> /etc/influxdb/config.toml
    fi
    # Bis die Ersteinrichtung gemacht ist, steht /api/v2/setup jedem offen,
    # der die InfluxDB erreicht – wer zuerst kommt, legt Administrator und
    # Operator-Token an. Deshalb lauscht sie bis dahin nur auf 127.0.0.1.
    # Bricht das Skript vorher ab, bleibt es dabei (sicher, nicht offen);
    # der nächste Lauf räumt die Datei weg.
    INFLUX_ERST=/etc/systemd/system/influxdb.service.d/powermanagement-ersteinrichtung.conf
    install -d -m 755 "$(dirname "$INFLUX_ERST")"
    printf '[Service]\nEnvironment=INFLUXD_HTTP_BIND_ADDRESS=127.0.0.1:8086\n' > "$INFLUX_ERST"
    systemctl daemon-reload
    systemctl enable --quiet influxdb
    systemctl restart influxdb
    influx_warten() {
        echo "    Warte auf die InfluxDB …"
        for _ in $(seq 1 60); do
            curl -sf --max-time 2 "$INFLUX_LOKAL/health" >/dev/null 2>&1 && break
            sleep 1
        done
        if ! curl -sf --max-time 2 "$INFLUX_LOKAL/health" >/dev/null 2>&1; then
            echo "Die InfluxDB antwortet nicht auf $INFLUX_LOKAL/health." >&2
            echo "Nachsehen: systemctl status influxdb; journalctl -u influxdb" >&2
            exit 1
        fi
    }
    influx_warten

    schritt "InfluxDB einrichten (Organisation, Bucket, Tokens)"
    ERSTEINRICHTUNG="$(curl -sS --max-time 10 "$INFLUX_LOKAL/api/v2/setup" | json_wert allowed)"
    if [[ "$ERSTEINRICHTUNG" == "true" ]]; then
        # Ersteinrichtung über die API statt „influx setup": Kennwort und
        # Token laufen so über die Standardeingabe, nicht über die
        # Kommandozeile, wo jeder sie in der Prozessliste sähe.
        INFLUX_ADMIN_KENNWORT="$(zugang_oder_neu INFLUX_ADMIN_KENNWORT)"
        zugang_setzen INFLUX_ADMIN_BENUTZER admin
        ANTWORT="$(PM_KENNWORT="$INFLUX_ADMIN_KENNWORT" PM_ORG="$INFLUX_ORG" PM_BUCKET="$INFLUX_DB" PM_SEKUNDEN="$AUFBEWAHRUNG_S" php -r '
            echo json_encode([
                "username" => "admin",
                "password" => getenv("PM_KENNWORT"),
                "org" => getenv("PM_ORG"),
                "bucket" => getenv("PM_BUCKET"),
                "retentionPeriodSeconds" => (int) getenv("PM_SEKUNDEN"),
            ]);' | curl -sS --fail-with-body --max-time 30 -X POST -H 'Content-Type: application/json' \
                --data-binary @- "$INFLUX_LOKAL/api/v2/setup")" || {
            echo "Die Ersteinrichtung der InfluxDB ist fehlgeschlagen:" >&2
            echo "$ANTWORT" >&2
            exit 1
        }
        zugang_setzen INFLUX_OPERATOR_TOKEN "$(printf '%s' "$ANTWORT" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["auth"]["token"] ?? "";')"
        zugang_setzen INFLUX_ORG_ID "$(printf '%s' "$ANTWORT" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["org"]["id"] ?? "";')"
        zugang_setzen INFLUX_BUCKET_ID "$(printf '%s' "$ANTWORT" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["bucket"]["id"] ?? "";')"
        echo "    Organisation „${INFLUX_ORG}“, Bucket „${INFLUX_DB}“ (Aufbewahrung: $AUFBEWAHRUNG_TEXT) angelegt"
    else
        echo "    InfluxDB ist bereits eingerichtet"
    fi
    # Eingerichtet: wieder wie konfiguriert erreichbar (Oberfläche, Grafana).
    rm -f "$INFLUX_ERST"
    systemctl daemon-reload
    systemctl restart influxdb
    influx_warten

    INFLUX_OPERATOR_TOKEN="$(zugang INFLUX_OPERATOR_TOKEN)"
    if [[ -z "$INFLUX_OPERATOR_TOKEN" ]]; then
        echo "Die InfluxDB ist eingerichtet, aber das Operator-Token fehlt in $ZUGAENGE." >&2
        echo "Entweder INFLUX_OPERATOR_TOKEN=<token> dort eintragen (influx auth list)" >&2
        echo "oder mit --ohne-influx --influx-url=$INFLUX_LOKAL --influx-token=<token> installieren." >&2
        exit 1
    fi

    # influx liest Adresse, Token und Organisation aus der Umgebung – das
    # Token steht so nicht in der Prozessliste.
    influx_cli() {
        INFLUX_HOST="$INFLUX_LOKAL" INFLUX_TOKEN="$INFLUX_OPERATOR_TOKEN" INFLUX_ORG="$INFLUX_ORG" influx "$@"
    }

    INFLUX_BUCKET_ID="$(zugang INFLUX_BUCKET_ID)"
    if [[ -z "$INFLUX_BUCKET_ID" ]]; then
        # „bucket list" endet mit Fehler, wenn es den Bucket nicht gibt – das
        # ist hier kein Abbruchgrund, sondern die Frage.
        INFLUX_BUCKET_ID="$(influx_cli bucket list --name "$INFLUX_DB" --json 2>/dev/null | json_wert id || true)"
        if [[ -z "$INFLUX_BUCKET_ID" ]]; then
            INFLUX_BUCKET_ID="$(influx_cli bucket create --name "$INFLUX_DB" --retention "${AUFBEWAHRUNG_S}s" --json | json_wert id)"
        fi
        zugang_setzen INFLUX_BUCKET_ID "$INFLUX_BUCKET_ID"
    fi

    # Explizite DBRP-Zuordnung: InfluxQL über /query und /write?db=… (v1)
    # finden den Bucket darüber. InfluxDB 2 legt zwar eine „virtuelle"
    # Zuordnung von selbst an, die gilt aber nur, solange der Bucket genau
    # so heißt wie die Datenbank – die explizite übersteht ein Umbenennen.
    DBRP_VORHANDEN="$(influx_cli v1 dbrp list --db "$INFLUX_DB" --json 2>/dev/null | php -r '
        $l = json_decode(stream_get_contents(STDIN), true);
        $l = $l["content"] ?? $l;
        foreach ((array) $l as $d) {
            if (is_array($d) && ($d["database"] ?? "") === $argv[1] && empty($d["virtual"])) { echo $d["id"] ?? "ja"; break; }
        }' "$INFLUX_DB" || true)"
    if [[ -z "$DBRP_VORHANDEN" ]]; then
        if influx_cli v1 dbrp create --db "$INFLUX_DB" --rp autogen --bucket-id "$INFLUX_BUCKET_ID" --default >/dev/null; then
            echo "    DBRP-Zuordnung $INFLUX_DB/autogen → Bucket $INFLUX_DB angelegt"
        else
            # Kein Abbruch: Die virtuelle Zuordnung reicht für InfluxQL, und
            # „gibt es schon" ist hier der häufigste Grund.
            hinweis "DBRP-Zuordnung ließ sich nicht anlegen – vorhandene: influx v1 dbrp list --db $INFLUX_DB"
        fi
    fi

    # Zwei Tokens mit dem Nötigsten: die Oberfläche und der Sammler lesen und
    # schreiben, Node-RED schreibt nur.
    INFLUX_TOKEN_PM="$(zugang INFLUX_TOKEN_OBERFLAECHE)"
    if [[ -z "$INFLUX_TOKEN_PM" ]]; then
        INFLUX_TOKEN_PM="$(influx_cli auth create --read-bucket "$INFLUX_BUCKET_ID" --write-bucket "$INFLUX_BUCKET_ID" \
            --description "PowerManagement Oberfläche und Sammler (lesen, schreiben)" --json | json_wert token)"
        [[ -n "$INFLUX_TOKEN_PM" ]] || { echo "Token für die Oberfläche ließ sich nicht anlegen." >&2; exit 1; }
        zugang_setzen INFLUX_TOKEN_OBERFLAECHE "$INFLUX_TOKEN_PM"
    fi
    INFLUX_TOKEN_NR="$(zugang INFLUX_TOKEN_NODERED)"
    if [[ -z "$INFLUX_TOKEN_NR" ]]; then
        INFLUX_TOKEN_NR="$(influx_cli auth create --write-bucket "$INFLUX_BUCKET_ID" \
            --description "PowerManagement Node-RED (nur schreiben)" --json | json_wert token)"
        [[ -n "$INFLUX_TOKEN_NR" ]] || { echo "Token für Node-RED ließ sich nicht anlegen." >&2; exit 1; }
        zugang_setzen INFLUX_TOKEN_NODERED "$INFLUX_TOKEN_NR"
    fi
    INFLUX_URL="$INFLUX_LOKAL"
    echo "    Tokens: Oberfläche/Sammler (lesen+schreiben), Node-RED (nur schreiben)"
else
    schritt "Vorhandene InfluxDB verwenden"
    INFLUX_URL="$INFLUX_URL_EXTERN"
    INFLUX_TOKEN_PM="$INFLUX_TOKEN_EXTERN"
    INFLUX_TOKEN_NR="$INFLUX_TOKEN_EXTERN"
    if curl -sf --max-time 5 "$INFLUX_URL/ping" >/dev/null 2>&1; then
        echo "    $INFLUX_URL antwortet"
    else
        hinweis "$INFLUX_URL antwortet (noch) nicht auf /ping – es geht trotzdem weiter."
    fi
    [[ -n "$INFLUX_TOKEN_EXTERN" ]] || hinweis "Ohne --influx-token wird ohne Anmeldung geschrieben und gelesen."
fi

# ================================================================ Node-RED
if [[ "$MIT_NODERED" == "ja" ]]; then
    schritt "Node-RED einrichten"
    # Eigener Systembenutzer; sein Heimatverzeichnis ist das userDir, damit
    # npm (Palette im Editor) seinen Cache dort anlegt, wo er schreiben darf.
    install -d -m 755 "$(dirname "$NODERED_DIR")"
    if ! id -u nodered >/dev/null 2>&1; then
        adduser --system --group --home "$NODERED_DIR" --shell /usr/sbin/nologin nodered >/dev/null
    fi
    install -d -o nodered -g nodered -m 750 "$NODERED_DIR"

    # Node-RED liegt in /opt/node-red statt global (npm -g): die Version ist
    # dort in einer package.json festgehalten, root gehört der Code (der
    # Dienst kann ihn nicht verändern), und es kommt nichts mit anderen
    # global installierten npm-Paketen durcheinander. Entfernen heißt
    # schlicht: Verzeichnis löschen.
    if [[ ! -f "$NODERED_APP/node_modules/node-red/package.json" ]]; then
        echo "    Node-RED 4 wird installiert (npm, dauert ein bis zwei Minuten) …"
        install -d -m 755 "$NODERED_APP"
        npm install --prefix "$NODERED_APP" --omit=dev --no-audit --no-fund --loglevel=error "node-red@4" >/dev/null
    fi
    echo "    Node-RED $(node -p "require('$NODERED_APP/node_modules/node-red/package.json').version")"

    # Anmeldung am Editor: bcrypt-Hash aus PHP. PHP schreibt ihn als $2y$,
    # das bcryptjs in Node-RED als $2b$ erwartet – das Verfahren ist
    # dasselbe, nur die Kennung unterscheidet sich.
    NODERED_ADMIN_KENNWORT="$(zugang NODERED_ADMIN_KENNWORT)"
    HASH_DATEI="$NODERED_DIR/admin-kennwort.bcrypt"
    if [[ -z "$NODERED_ADMIN_KENNWORT" || ! -s "$HASH_DATEI" ]]; then
        NODERED_ADMIN_KENNWORT="$(zugang_oder_neu NODERED_ADMIN_KENNWORT 20)"
        # Geschrieben von nodered selbst: der Ordner gehört ihm, und ein
        # Verweis darin (auf eine Datei, die root liest) brächte als root
        # geschrieben und per chown übergeben ihm fremde Dateien.
        PM_KENNWORT="$NODERED_ADMIN_KENNWORT" php -r '
            $h = password_hash(getenv("PM_KENNWORT"), PASSWORD_BCRYPT);
            echo "\$2b\$" . substr($h, 4), "\n";' \
            | runuser -u nodered -- sh -c 'umask 077; rm -f -- "$1"; cat > "$1"' _ "$HASH_DATEI" 9>&-
    fi
    zugang_setzen NODERED_ADMIN_BENUTZER admin

    vorlage_uebernehmen "$ZIEL/deploy/node-red/settings.js" "$NODERED_DIR/settings.js" nodered:nodered 640 \
        "$NODERED_DIR/settings.powermanagement-neu.js"
    [[ "$VORLAGE_ERGEBNIS" == "behalten" ]] && hinweis "settings.js ist verändert – neue Fassung: $NODERED_DIR/settings.powermanagement-neu.js"

    # Flows: nur einspielen, wenn noch keine da sind (oder --flows-ersetzen).
    # Hat seit der letzten Auslieferung niemand etwas geändert, ist das
    # Ersetzen ebenso gefahrlos. Sonst kommt die neue Fassung daneben.
    vorlage_uebernehmen "$ZIEL/deploy/node-red/flows.json" "$NODERED_DIR/flows.json" nodered:nodered 640 \
        "$NODERED_DIR/flows.powermanagement-neu.json" "$FLOWS_ERSETZEN"
    FLOWS_ERGEBNIS="$VORLAGE_ERGEBNIS"
    case "$FLOWS_ERGEBNIS" in
        neu)      echo "    Flows eingespielt" ;;
        gleich)   echo "    Flows sind auf dem mitgelieferten Stand" ;;
        ersetzt)  echo "    Flows durch die mitgelieferte Fassung ersetzt" ;;
        behalten) hinweis "Die Flows in Node-RED wurden verändert und bleiben, wie sie sind."
                  hinweis "Die mitgelieferte Fassung liegt unter $NODERED_DIR/flows.powermanagement-neu.json"
                  hinweis "(im Editor: Menü → Importieren) – oder erneut mit --flows-ersetzen aufrufen."
                  hinweis "Eigene MQTT-Zugänge der Shellys brauchen die neuen Flows (Schutzliste pm/status/mqtt-zugaenge,"
                  hinweis "Antworten unter <präfix>/pm-nodered/rpc)." ;;
    esac

    # Zugang zum Broker in flows_cred.json unter der festen ID des Broker-
    # Nodes. Andere Einträge (Nodes, die jemand ergänzt hat) bleiben stehen.
    # Werte über die Umgebung, nicht über die Kommandozeile.
    # Als nodered: sein Ordner, seine Datei – ein Verweis darin führt dann
    # nirgends hin, wo nodered nicht ohnehin schreiben darf.
    PM_DATEI="$NODERED_DIR/flows_cred.json" PM_BENUTZER="$MQTT_NR_BENUTZER" PM_KENNWORT="$MQTT_NODERED_KENNWORT" \
        runuser -u nodered -- node -e '
        const fs = require("fs");
        const datei = process.env.PM_DATEI;
        let d = {};
        try { d = JSON.parse(fs.readFileSync(datei, "utf8")); } catch (e) { d = {}; }
        if (!d || typeof d !== "object" || Array.isArray(d) || typeof d.$ === "string") {
            // verschlüsselt (credentialSecret war einmal gesetzt) oder kaputt:
            // lässt sich nicht ergänzen – sichern und neu anfangen
            try { fs.copyFileSync(datei, datei + ".vor-" + Date.now()); } catch (e) {}
            d = {};
        }
        d.pm_broker = Object.assign({}, d.pm_broker, { user: process.env.PM_BENUTZER, password: process.env.PM_KENNWORT });
        try { fs.unlinkSync(datei + ".neu"); } catch (e) {}
        fs.writeFileSync(datei + ".neu", JSON.stringify(d, null, 4) + "\n", { mode: 0o600, flag: "wx" });
        fs.renameSync(datei + ".neu", datei);
    ' 9>&-

    # Umgebung für den Dienst. Werte in einfachen Anführungszeichen: so
    # nimmt systemd sie wörtlich.
    for w in "$MQTT_HOST" "$INFLUX_URL" "$INFLUX_DB" "$INFLUX_TOKEN_NR"; do
        if [[ "$w" == *"'"* || "$w" == *$'\n'* ]]; then
            echo "Ein Wert für $NODERED_ENV enthält ' oder einen Zeilenumbruch – nicht ablegbar." >&2
            exit 1
        fi
    done
    TMP_ENV="$(mktemp "$ETC/.node-red.env.XXXXXX")"
    cat > "$TMP_ENV" <<UMGEBUNG
# Umgebung für powermanagement-nodered.service – geschrieben von
# deploy/install-debian.sh. Die Flows lesen die Werte mit env.get() bzw.
# \${MQTT_HOST} im Broker-Node. Nach Änderungen:
#   systemctl restart powermanagement-nodered
MQTT_HOST='$MQTT_HOST'
MQTT_PORT='$MQTT_PORT'
INFLUX_URL='$INFLUX_URL'
INFLUX_DB='$INFLUX_DB'
INFLUX_TOKEN='$INFLUX_TOKEN_NR'
# Nur für InfluxDB 1.8 ohne Token:
INFLUX_USER=''
INFLUX_PASSWORD=''
UMGEBUNG
    chown root:nodered "$TMP_ENV"
    chmod 640 "$TMP_ENV"
    mv "$TMP_ENV" "$NODERED_ENV"

    sed -e "s|__NODERED_APP__|$NODERED_APP|g" -e "s|__NODERED_DIR__|$NODERED_DIR|g" \
        "$ZIEL/deploy/powermanagement-nodered.service" > /etc/systemd/system/powermanagement-nodered.service
    systemctl daemon-reload
    systemctl enable --quiet powermanagement-nodered.service
    # Neu starten, nicht nur starten: Flows, Umgebung oder Kennwort können
    # sich eben geändert haben.
    systemctl restart powermanagement-nodered.service
fi

# ================================================================ PHP-FPM
schritt "PHP-FPM-Pool einrichten"
POOL="/etc/php/$FPM_VERSION/fpm/pool.d/powermanagement.conf"
sed -e "s|__APP_USER__|$APP_USER|g" -e "s|__APP_DIR__|$ZIEL|g" \
    "$ZIEL/deploy/php-fpm-pool.conf" > "$POOL"
systemctl enable --quiet "php$FPM_VERSION-fpm"
systemctl restart "php$FPM_VERSION-fpm"

# ================================================================ nginx
schritt "nginx einrichten"
SITE="/etc/nginx/sites-available/powermanagement"
# In der Site stecken oft eigene Anpassungen (Proxy-Adressen, Zertifikate) –
# ersetzt wird sie nur, solange sie niemand verändert hat.
SITE_NEU="$(mktemp)"
sed -e "s|__APP_DIR__|$ZIEL|g" \
    -e "s|__FPM_SOCKET__|/run/php/powermanagement.sock|g" \
    -e "s|__SERVER_NAME__|$SERVER_NAME|g" \
    -e "s|__LISTEN__|$LISTEN|g" \
    -e "s|__PORT__|$PORT|g" \
    "$ZIEL/deploy/nginx-powermanagement.conf" > "$SITE_NEU"
vorlage_uebernehmen "$SITE_NEU" "$SITE" root:root 644 /etc/nginx/powermanagement.powermanagement-neu
rm -f "$SITE_NEU"
if [[ "$VORLAGE_ERGEBNIS" == "behalten" ]]; then
    hinweis "$SITE ist von Hand verändert und bleibt so. Die neue Fassung liegt unter"
    hinweis "/etc/nginx/powermanagement.powermanagement-neu (Unterschiede: diff -u $SITE /etc/nginx/powermanagement.powermanagement-neu)."
fi
ln -sf "$SITE" /etc/nginx/sites-enabled/powermanagement
rm -f /etc/nginx/sites-enabled/default

# Beansprucht eine andere Seite denselben Port als default_server, lehnt
# nginx die Konfiguration ab. Dann ohne die Kennzeichnung weiter – der
# Aufruf über die reine IP landet dann bei der anderen Seite.
DEFAULT_KONFLIKT="nein"
NGINX_TEST="$(nginx -t 2>&1 || true)"
if [[ "$NGINX_TEST" == *"duplicate default server"* ]]; then
    DEFAULT_KONFLIKT="ja"
    sed -i "s|listen $LISTEN:$PORT default_server;|listen $LISTEN:$PORT;|" "$SITE"
    # Diese Änderung stammt vom Skript, nicht von Hand – als ausgeliefert merken.
    cp "$SITE" "$VORLAGEN/$(printf '%s' "$SITE" | tr '/' '_')"
    hinweis "Eine andere nginx-Seite ist default_server auf Port $PORT – PowerManagement läuft ohne diese Kennzeichnung."
fi
nginx -t
systemctl enable --quiet nginx
systemctl reload nginx || systemctl restart nginx

# ================================================================ Datenbank
schritt "Datenbank anlegen und Administrator erzeugen"
ADMIN_KENNWORT=""
# Dass die Datenbankdatei existiert, heißt nicht, dass es einen Administrator
# gibt (Befund 2): Jeder Aufruf der Anwendung legt sie an, auch leer – etwa
# die Anmeldeseite, sobald nginx sie ausliefert –, und ein abgebrochenes
# „bin/power install“ hinterlässt sie halb fertig. Maßgeblich ist, ob es
# Benutzer gibt. Gibt es welche, legt das Skript niemanden an – auch keinen
# Administrator, den jemand bewusst entfernt oder umbenannt hat.
DATENBANK_VORHER="nein"
[[ -f "$ZIEL/storage/database.sqlite" ]] && DATENBANK_VORHER="ja"
as_app php "$ZIEL/bin/power" migrate
BENUTZER_ZAHL="$(as_app php -r 'require "src/bootstrap.php"; echo (int) App\Support\Database::scalar("SELECT COUNT(*) FROM users");')" || BENUTZER_ZAHL=""
if [[ ! "$BENUTZER_ZAHL" =~ ^[0-9]+$ ]]; then
    echo "Die Benutzer in der Datenbank ließen sich nicht zählen – Ausgabe oben prüfen." >&2
    exit 1
fi
if (( BENUTZER_ZAHL > 0 )); then
    echo "    Datenbank vorhanden ($BENUTZER_ZAHL Benutzer) – nur Migrationen ausgeführt"
else
    [[ "$DATENBANK_VORHER" == "ja" ]] && hinweis "Die Datenbank hat noch keine Benutzer – der Administrator wird jetzt angelegt."
    ADMIN_KENNWORT="$(zufall 16)"
    # Kennwort über die Standardeingabe – so steht es nicht in der Prozessliste.
    # Bei der ersten Anmeldung muss es geändert werden.
    INSTALL_ARGS=(install "$ADMIN_USER" --stdin)
    [[ -n "$TITEL" ]] && INSTALL_ARGS+=("--name=$TITEL")
    # Ein Fehler darf nicht untergehen: sonst stünde ein Erstkennwort in der
    # Zusammenfassung, zu dem es keinen Benutzer gibt.
    if ! AUSGABE="$(printf '%s\n' "$ADMIN_KENNWORT" | as_app php "$ZIEL/bin/power" "${INSTALL_ARGS[@]}" 2>&1)"; then
        printf '%s\n' "$AUSGABE" | grep -v 'Passwort:' >&2 || true
        echo "bin/power install ist fehlgeschlagen – Ausgabe oben prüfen, dann das Skript noch einmal aufrufen." >&2
        exit 1
    fi
    printf '%s\n' "$AUSGABE" | grep -v 'Passwort:' || true
    if grep -qx 'Administrator angelegt:' <<< "$AUSGABE"; then
        zugang_setzen OBERFLAECHE_BENUTZER "$ADMIN_USER"
        zugang_setzen OBERFLAECHE_KENNWORT "$ADMIN_KENNWORT"
    else
        ADMIN_KENNWORT=""
    fi
fi

# ================================================================ Einstellungen
schritt "Zugänge in die Anwendung eintragen"
# Zugangsdaten landen verschlüsselt in der Datenbank (mit app.key).
# Werte über die Standardeingabe: Tokens und Kennwörter erscheinen so nicht
# in der Prozessliste.
#
# In eine vorhandene Datenbank (es gibt Benutzer) nur, was dort noch fehlt
# (Befund 4): Titel, InfluxDB und MQTT lassen sich in der Oberfläche ändern
# (System → Einstellungen) – ein weiterer Lauf mit denselben Optionen setzte
# sie sonst still zurück. „immer“: nur der Zugang zum Broker im Container.
BEHALTEN=()
einstellung() {
    local ausgabe args=(einstellung "$1" --stdin)
    if (( BENUTZER_ZAHL > 0 )) && [[ "${3:-}" != "immer" ]]; then
        args+=(--nur-wenn-leer)
    fi
    ausgabe="$(printf '%s\n' "$2" | as_app php "$ZIEL/bin/power" "${args[@]}")"
    case "$ausgabe" in
        "$1 bleibt"*)      echo "    $1 – bleibt, in der Anwendung anders eingetragen"; BEHALTEN+=("$1") ;;
        "$1 unverändert"*) echo "    $1 (unverändert)" ;;
        *)                 echo "    $1" ;;
    esac
}
# Bei einer neuen Datenbank hat „bin/power install --name“ den Titel gesetzt.
if [[ -n "$TITEL" ]] && (( BENUTZER_ZAHL > 0 )); then
    einstellung app.name "$TITEL"
fi
einstellung influx.url "$INFLUX_URL"
einstellung influx.database "$INFLUX_DB"
einstellung influx.org "$INFLUX_ORG"
[[ -n "$INFLUX_TOKEN_PM" ]] && einstellung influx.token "$INFLUX_TOKEN_PM"
einstellung mqtt.host "$MQTT_HOST"
einstellung mqtt.port "$MQTT_PORT"
# Das Kennwort von „powermanagement“ am Broker im Container setzt jeder Lauf
# in dessen Kennwortdatei – die Anwendung muss dasselbe haben. Zeigt mqtt.host
# (aus der Oberfläche) auf einen anderen Broker, bleibt dessen Zugang.
MQTT_ZUGANG=""
if [[ "$MIT_MOSQUITTO" == "ja" && " ${BEHALTEN[*]} " != *" mqtt.host "* ]]; then
    MQTT_ZUGANG="immer"
fi
[[ -n "$MQTT_PM_BENUTZER" ]] && einstellung mqtt.username "$MQTT_PM_BENUTZER" "$MQTT_ZUGANG"
[[ -n "$MQTT_PM_KENNWORT" ]] && einstellung mqtt.password "$MQTT_PM_KENNWORT" "$MQTT_ZUGANG"

# ================================================================ Dienste
schritt "Sammler, Wartung und Protokolle einrichten"
DIENSTE=(powermanagement-sammler.service powermanagement-wartung.service powermanagement-wartung.timer
    powermanagement-update.service powermanagement-update.path powermanagement-update.timer
    powermanagement-update-zeitplan.service powermanagement-update-zeitplan.timer)
# Eigene MQTT-Zugänge: der Helfer läuft bei jeder Änderung des Solls (.path)
# und alle zwei Minuten (.timer) – nur mit dem Broker hier im Container.
[[ "$MIT_MOSQUITTO" == "ja" ]] && DIENSTE+=(powermanagement-mqtt-zugaenge.service
    powermanagement-mqtt-zugaenge.path powermanagement-mqtt-zugaenge.timer)
for unit in "${DIENSTE[@]}"; do
    sed -e "s|__APP_USER__|$APP_USER|g" -e "s|__APP_DIR__|$ZIEL|g" \
        "$ZIEL/deploy/$unit" > "/etc/systemd/system/$unit"
done
sed -e "s|__APP_USER__|$APP_USER|g" -e "s|__APP_DIR__|$ZIEL|g" \
    "$ZIEL/deploy/powermanagement.logrotate" > /etc/logrotate.d/powermanagement
systemctl daemon-reload
systemctl enable --quiet powermanagement-sammler.service
systemctl restart powermanagement-sammler.service
systemctl enable --quiet --now powermanagement-wartung.timer
if [[ "$MIT_MOSQUITTO" == "ja" ]]; then
    systemctl enable --quiet --now powermanagement-mqtt-zugaenge.path powermanagement-mqtt-zugaenge.timer
    # Einmal gleich laufen lassen: das Ergebnis oben entstand noch vor der
    # Broker-Konfiguration (acl_wirksam) – die Oberfläche soll den Stand
    # danach sehen.
    systemctl start --no-block powermanagement-mqtt-zugaenge.service || true
fi

# Einstellungen dieser Installation für das Update-Skript
cat > "$INSTALLATION_CONF" <<KONF
# Geschrieben von deploy/install-debian.sh am $(date '+%d.%m.%Y %H:%M') –
# deploy/update-debian.sh liest hier, was zur Installation gehört.
ZIEL=$ZIEL
APP_USER=$APP_USER
MIT_INFLUX=$MIT_INFLUX
MIT_NODERED=$MIT_NODERED
MIT_MOSQUITTO=$MIT_MOSQUITTO
NODERED_APP=$NODERED_APP
NODERED_DIR=$NODERED_DIR
KONF
chmod 644 "$INSTALLATION_CONF"

# Update-Dienst erst jetzt einschalten: der Helfer liest installation.conf
# nicht, aber update-debian.sh, das er startet. Die Pfad-Einheit nimmt
# Aufträge der Oberfläche an, der Zeitgeber weckt den Dienst regelmäßig
# (Lebenszeichen, Stand, Wiederanlauf). Einmal gleich starten – erstes
# Lebenszeichen für die Oberfläche. Der Zeitplan (automatische Updates, als
# Anwendungsbenutzer) läuft alle fünf Minuten von selbst.
systemctl enable --quiet --now powermanagement-update.path powermanagement-update.timer powermanagement-update-zeitplan.timer
systemctl start --no-block powermanagement-update.service || true

# ================================================================ Prüfen
schritt "Prüfen"
# Lauscht nginx nur auf einer Adresse, ist 127.0.0.1 dort nicht erreichbar.
PRUEF_HOST="127.0.0.1"
[[ "$LISTEN" != "0.0.0.0" && "$LISTEN" != "*" ]] && PRUEF_HOST="$LISTEN"
# curl schreibt „000" auch selbst, wenn keine Verbindung zustande kommt –
# ein „|| echo 000" ergäbe dann „000000".
HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H "Host: $SERVER_NAME" "http://$PRUEF_HOST:$PORT/login" 2>/dev/null)" || true
HTTP_CODE="${HTTP_CODE:-000}"
if [[ "$HTTP_CODE" == "200" ]]; then
    echo "    Oberfläche antwortet (HTTP 200)"
else
    hinweis "Die Oberfläche antwortet mit HTTP $HTTP_CODE statt 200."
    hinweis "Nachsehen: systemctl status nginx php$FPM_VERSION-fpm; /var/log/nginx/powermanagement.error.log"
fi

if [[ "$MIT_NODERED" == "ja" ]]; then
    # Node-RED braucht nach dem Start einige Sekunden, bis es verbunden ist
    # und den ersten Herzschlag schickt – den prüft bin/power pruefen mit.
    echo "    Warte auf Node-RED …"
    for _ in $(seq 1 60); do
        curl -s -o /dev/null --max-time 2 http://127.0.0.1:1880/node-red/ && break
        sleep 1
    done
    sleep 8
fi

PRUEF_RC=0
PRUEFUNG="$(as_app php "$ZIEL/bin/power" pruefen 2>&1)" || PRUEF_RC=$?
printf '%s\n' "$PRUEFUNG" | sed 's/^/    /'
if [[ "$PRUEF_RC" -ne 0 ]]; then
    hinweis "bin/power pruefen meldet Fehler (siehe oben). Die Installation ist trotzdem abgeschlossen."
fi

# ================================================================ Zugänge
# zugaenge.txt einmal ordentlich schreiben: Abschnitte, Erklärungen, feste
# Reihenfolge. Unbekannte Schlüssel (von Hand ergänzt) bleiben am Ende.
zugang_setzen OBERFLAECHE_URL "$APP_URL"
[[ "$MIT_NODERED" == "ja" ]] && zugang_setzen NODERED_URL "$APP_URL/node-red/"
if [[ "$MIT_MOSQUITTO" == "ja" ]]; then
    zugang_setzen MQTT_HOST "$HOST_IP"
    zugang_setzen MQTT_PORT "$MQTT_PORT"
    zugang_setzen MQTT_SHELLY_BENUTZER shelly
fi
[[ "$MIT_INFLUX" == "ja" ]] && zugang_setzen INFLUX_URL "http://$HOST_IP:8086"

BEKANNT=(OBERFLAECHE_URL OBERFLAECHE_BENUTZER OBERFLAECHE_KENNWORT
         NODERED_URL NODERED_ADMIN_BENUTZER NODERED_ADMIN_KENNWORT
         MQTT_HOST MQTT_PORT MQTT_SHELLY_BENUTZER MQTT_SHELLY_KENNWORT MQTT_NODERED_KENNWORT MQTT_POWERMANAGEMENT_KENNWORT
         INFLUX_URL INFLUX_ADMIN_BENUTZER INFLUX_ADMIN_KENNWORT INFLUX_OPERATOR_TOKEN INFLUX_ORG_ID INFLUX_BUCKET_ID
         INFLUX_TOKEN_OBERFLAECHE INFLUX_TOKEN_NODERED)
TMP_Z="$(mktemp "$ETC/.zugaenge.XXXXXX")"
{
    echo "# PowerManagement – Zugangsdaten"
    echo "# Zuletzt geschrieben von deploy/install-debian.sh am $(date '+%d.%m.%Y %H:%M')."
    echo "# Nur für root lesbar. Das Installationsskript liest die Werte bei jedem"
    echo "# Lauf wieder ein – erzeugte Kennwörter bleiben so gleich."
    echo "#"
    echo "# OBERFLAECHE_KENNWORT ist das Erstkennwort; nach der ersten Anmeldung gilt es nicht mehr."
    echo "# MQTT_SHELLY_* ist der gemeinsame Zugang der Shellys ohne eigenen Zugang."
    echo "# INFLUX_OPERATOR_TOKEN darf alles in der InfluxDB – gut aufheben, nicht weitergeben."
    ueberschrift=""
    for k in "${BEKANNT[@]}"; do
        v="$(zugang "$k")"
        case "$k" in
            OBERFLAECHE_URL)  ueberschrift="# --- Oberfläche" ;;
            NODERED_URL)      ueberschrift="# --- Node-RED-Editor" ;;
            MQTT_HOST)        ueberschrift="# --- MQTT-Broker (Mosquitto)" ;;
            INFLUX_URL)       ueberschrift="# --- InfluxDB" ;;
        esac
        [[ -z "$v" ]] && continue
        # Abschnittsüberschrift erst mit dem ersten Wert darunter
        if [[ -n "$ueberschrift" ]]; then
            printf '\n%s\n' "$ueberschrift"
            ueberschrift=""
        fi
        printf '%s=%s\n' "$k" "$v"
    done
    SONSTIGE="$(grep -E '^[A-Z0-9_]+=' "$ZUGAENGE" | grep -vE "^($(IFS='|'; echo "${BEKANNT[*]}"))=" || true)"
    if [[ -n "$SONSTIGE" ]]; then
        echo; echo "# --- Weitere"
        printf '%s\n' "$SONSTIGE"
    fi
} > "$TMP_Z"
chmod 600 "$TMP_Z"
mv "$TMP_Z" "$ZUGAENGE"

# ================================================================ Fertig
schritt "Fertig"
echo
echo "  Oberfläche:   $APP_URL"
if [[ -n "$ADMIN_KENNWORT" ]]; then
    echo "                Benutzer $ADMIN_USER, Kennwort $ADMIN_KENNWORT"
    echo "                (Erstkennwort – bitte bei der ersten Anmeldung ändern)"
else
    echo "                (Administrator bestand schon – Kennwort unverändert)"
fi
if [[ "$MIT_NODERED" == "ja" ]]; then
    echo
    echo "  Node-RED:     $APP_URL/node-red/"
    echo "                Benutzer admin, Kennwort $(zugang NODERED_ADMIN_KENNWORT)"
fi
if [[ "$MIT_MOSQUITTO" == "ja" ]]; then
    echo
    echo "  MQTT für die Shellys:"
    echo "                Server   $HOST_IP:$MQTT_PORT"
    echo "                Benutzer shelly"
    echo "                Kennwort $MQTT_SHELLY_KENNWORT"
    echo "                Gen2+: „Generic status update over MQTT“ und „RPC status notifications“ einschalten"
    echo "                Eigene Zugänge je Shelly: Oberfläche → Messtechnik → MQTT-Zugänge"
fi
if [[ "$MIT_INFLUX" == "ja" ]]; then
    echo
    echo "  InfluxDB:     http://$HOST_IP:8086"
    echo "                Benutzer admin, Kennwort $(zugang INFLUX_ADMIN_KENNWORT)"
    echo "                Organisation $INFLUX_ORG, Bucket $INFLUX_DB"
else
    echo
    echo "  InfluxDB:     $INFLUX_URL (vorhanden), Datenbank $INFLUX_DB"
fi
if (( ${#BEHALTEN[@]} > 0 )); then
    echo
    echo "  Beibehalten:  ${BEHALTEN[*]}"
    echo "                (in der Anwendung anders eingetragen – Optionen dieses Aufrufs gelten"
    echo "                dafür nur bei einer neuen Datenbank; ändern unter System → Einstellungen)"
fi
echo
if [[ "$PRUEF_RC" -eq 0 ]]; then
    echo "  Prüfung:      in Ordnung (bin/power pruefen)"
else
    echo "  Prüfung:      mit Fehlern – siehe „Prüfen“ oben"
fi
echo
echo "  Alle Zugänge: $ZUGAENGE (nur root)"
echo
echo "  WireGuard: wireguard-tools ist installiert, ein Tunnel ist nicht eingerichtet."
echo "  Wie Victron- und ComAp-Anlagen an entfernten Standorten angebunden werden,"
echo "  steht in docs/installation-debian-lxc.md, Abschnitt „WireGuard“."
if [[ "$DEFAULT_KONFLIKT" == "ja" ]]; then
    echo
    echo "  Hinweis: Eine andere nginx-Seite ist default_server auf Port $PORT."
    echo "  PowerManagement ist dann über http://$SERVER_NAME erreichbar – oder mit --port"
    echo "  einen eigenen Port wählen."
fi
if [[ "$NAMENSRAEUME" == "nein" ]]; then
    echo
    echo "  Hinweis: $NAMENSRAEUME_TEXT"
fi
echo
