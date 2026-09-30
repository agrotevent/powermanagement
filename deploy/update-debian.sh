#!/usr/bin/env bash
#
# PowerManagement auf den Stand aus dem Git-Repository bringen.
#
# Das Skript sichert vorher Datenbank und Konfiguration, holt die
# Änderungen, führt die Migrationen aus, zieht fehlende Pakete nach und
# startet die Dienste neu. Geht dabei etwas schief, kommt der alte Stand
# zurück – Quellcode und Datenbank. Die Anwendung bleibt also nicht halb
# aktualisiert stehen.
#
# Beispiele:
#   ./deploy/update-debian.sh                   aktualisieren
#   ./deploy/update-debian.sh --pruefen         nur zeigen, was sich ändern würde
#   ./deploy/update-debian.sh --flows-ersetzen  auch veränderte Node-RED-Flows ersetzen
#
# Unverändert bleiben: config/config.php, storage/ (Datenbank, Grundrisse,
# Protokolle), eigene Änderungen an den Node-RED-Flows, an settings.js, an
# der nginx-Site und an der Mosquitto-ACL. Neue Fassungen dieser Dateien
# legt das Skript daneben (…powermanagement-neu…) und sagt Bescheid.

# -E: der ERR-Trap muss auch greifen, wenn ein Kommando in einer Funktion
# scheitert – sonst bliebe ein halb eingespieltes Update stehen.
set -Eeuo pipefail

ETC="/etc/powermanagement"
INSTALLATION_CONF="$ETC/installation.conf"
VORLAGEN="/var/lib/powermanagement/vorlagen"

# Vorgaben – überschrieben von dem, was die Installation festgehalten hat.
ZIEL="/opt/powermanagement"
APP_USER="www-data"
MIT_INFLUX="ja"
MIT_NODERED="ja"
MIT_MOSQUITTO="ja"
NODERED_APP="/opt/node-red"
NODERED_DIR="/var/lib/powermanagement/node-red"

if [[ -r "$INSTALLATION_CONF" ]]; then
    # Nicht per „source": nur bekannte Schlüssel übernehmen, nichts ausführen.
    while IFS='=' read -r schluessel wert; do
        case "$schluessel" in
            ZIEL|APP_USER|MIT_INFLUX|MIT_NODERED|MIT_MOSQUITTO|NODERED_APP|NODERED_DIR)
                printf -v "$schluessel" '%s' "$wert" ;;
        esac
    done < <(grep -E '^[A-Z_]+=' "$INSTALLATION_CONF")
fi

ZWEIG=""
FLOWS_ERSETZEN="nein"
ERZWINGEN="nein"
NUR_ZEIGEN="nein"
BEHALTEN=5
GIT_CRED_FILE=""

hilfe() {
    cat <<HILFE
Aufruf: $0 [Optionen]

  --ziel=<pfad>          Installationsverzeichnis   (Vorgabe: $ZIEL)
  --zweig=<name>         Zweig                      (Vorgabe: der ausgecheckte)
  --git-zugang=<datei>   Token für ein privates Repository
                         (Vorgabe: /etc/powermanagement/git.conf, Inhalt GITHUB_TOKEN=…)
  --flows-ersetzen       Node-RED-Flows auch dann ersetzen, wenn sie im Editor
                         verändert wurden (die alten werden gesichert)
  --erzwingen            lokale Änderungen am Quellcode verwerfen statt abzubrechen
  --behalten=<anzahl>    so viele Sicherungen bleiben liegen (Vorgabe: $BEHALTEN)
  --pruefen              nur zeigen, was sich ändern würde – nichts anfassen
  -h, --hilfe            diese Hilfe
HILFE
}

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
        --ziel|--zweig|--git-zugang|--behalten)
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
        --ziel)            ZIEL="${wert%/}" ;;
        --zweig)           ZWEIG="$wert" ;;
        --git-zugang)      GIT_CRED_FILE="$wert" ;;
        --behalten)        BEHALTEN="$wert" ;;
        --flows-ersetzen)  FLOWS_ERSETZEN="ja" ;;
        --erzwingen)       ERZWINGEN="ja" ;;
        --pruefen)         NUR_ZEIGEN="ja" ;;
        -h|--hilfe|--help) hilfe; exit 0 ;;
        *) echo "Unbekannte Option: $opt" >&2; hilfe >&2; exit 1 ;;
    esac
done

if [[ $EUID -ne 0 ]]; then
    echo "Bitte als root ausführen (oder mit sudo)." >&2
    exit 1
fi
if [[ ! "$BEHALTEN" =~ ^[1-9][0-9]*$ ]]; then
    echo "--behalten erwartet eine Zahl ab 1." >&2
    exit 1
fi
if [[ ! -d /run/systemd/system ]] || ! command -v systemctl >/dev/null 2>&1; then
    echo "Hier läuft kein systemd – PowerManagement braucht es." >&2
    exit 1
fi

schritt() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
hinweis() { printf '\033[1;33m    %s\033[0m\n' "$1" >&2; }

# Ein Verzeichnis in storage/ anlegen – als Anwendungsbenutzer, nicht als
# root (siehe install-debian.sh: ein Verweis in storage/ soll root nicht dazu
# bringen, dessen Ziel der Anwendung zu übereignen).
app_verzeichnis() {
    as_app mkdir -p -- "$1"
    as_app chmod "${2:-750}" -- "$1"
}

as_app() {
    (cd "$ZIEL" && runuser -u "$APP_USER" -- "$@")
}

# Zwei Läufe gleichzeitig (etwa von Hand und aus einem Zeitplan) würden
# sich gegenseitig die Datenbank zurückrollen.
exec 9>/run/lock/powermanagement-update.lock
if ! flock -n 9; then
    echo "Ein anderes Update läuft gerade." >&2
    exit 1
fi

# ------------------------------------------------ mitgelieferte Vorlagen
# Gleiche Logik wie im Installationsskript: ersetzt wird nur, was seit der
# letzten Auslieferung niemand verändert hat; sonst kommt die neue Fassung
# daneben. Ergebnis in VORLAGE_ERGEBNIS: neu | gleich | ersetzt | behalten
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
        cp -p "$ziel" "$ziel.vor-$(date +%Y%m%d-%H%M%S)"
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
    rm -f "$neu"
}

# ------------------------------------------------- Zugangsdaten für git
# Wie im Installationsskript: Token aus /etc/powermanagement/git.conf, von
# git über /etc/powermanagement/git-credentials gelesen – nie in der
# Kommandozeile, nie in .git/config, nie in der Adresse des Remotes.
GIT_STORE="$ETC/git-credentials"
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
                esac ;;
            *)
                [[ -z "$GIT_TOKEN" ]] && GIT_TOKEN="$zeile" ;;
        esac
    done < "$datei"
}

git_zugang_ablegen() {
    local repo="${1:-}" schema="https" host="github.com" benutzer zeile
    if [[ "$repo" == git@* || "$repo" == ssh://* ]]; then
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
        benutzer="${GIT_TOKEN_USER:-x-access-token}"
        zeile="$schema://$(urlenc "$benutzer"):$(urlenc "$GIT_TOKEN")@$host"
    fi
    install -m 600 /dev/null "$GIT_STORE"
    printf '%s\n' "$zeile" > "$GIT_STORE"
    chmod 600 "$GIT_CRED_FILE" 2>/dev/null || true
}

# ================================================================ Stand
if [[ ! -d "$ZIEL/.git" ]]; then
    echo "In $ZIEL liegt kein Git-Arbeitsverzeichnis." >&2
    echo "Dieses Skript aktualisiert nur Installationen, die per git geholt wurden." >&2
    exit 1
fi
if [[ ! -f "$ZIEL/bin/power" ]]; then
    echo "In $ZIEL steckt keine PowerManagement-Installation." >&2
    exit 1
fi
git config --global --get-all safe.directory 2>/dev/null | grep -qxF "$ZIEL" \
    || git config --global --add safe.directory "$ZIEL"

AKTUELLER_ZWEIG="$(git -C "$ZIEL" rev-parse --abbrev-ref HEAD)"
[[ -z "$ZWEIG" ]] && ZWEIG="$AKTUELLER_ZWEIG"
ALT="$(git -C "$ZIEL" rev-parse HEAD)"

schritt "Stand prüfen"
echo "    Verzeichnis: $ZIEL"
echo "    Zweig:       $ZWEIG"
echo "    Aktuell:     $(git -C "$ZIEL" log -1 --format='%h %s')"

LOKAL="$(git -C "$ZIEL" status --porcelain --untracked-files=no)"
if [[ -n "$LOKAL" ]]; then
    hinweis "In $ZIEL gibt es lokale Änderungen:"
    printf '%s\n' "$LOKAL" | sed 's/^/      /' >&2
    if [[ "$ERZWINGEN" != "ja" ]]; then
        hinweis "Abbruch. Mit --erzwingen werden sie verworfen – sonst vorher sichern."
        exit 1
    fi
    hinweis "--erzwingen: die Änderungen werden verworfen."
fi

schritt "Änderungen holen"
if [[ -z "$GIT_CRED_FILE" ]]; then
    GIT_CRED_FILE="$(zugangsdatei_finden || true)"
fi
if [[ -n "$GIT_CRED_FILE" ]]; then
    zugangsdaten_lesen "$GIT_CRED_FILE"
    git_zugang_ablegen "$(git -C "$ZIEL" remote get-url origin 2>/dev/null || true)"
fi
if [[ -f "$GIT_STORE" ]]; then
    git -C "$ZIEL" config credential.helper "store --file=$GIT_STORE"
fi

# GIT_TERMINAL_PROMPT=0: ohne gültiges Token mit einer Meldung abbrechen,
# statt auf eine Eingabe zu warten, die niemand tippt.
if ! GIT_TERMINAL_PROMPT=0 git -C "$ZIEL" fetch --quiet origin "$ZWEIG"; then
    REMOTE_URL="$(git -C "$ZIEL" remote get-url origin 2>/dev/null || echo '?')"
    hinweis "Die Änderungen ließen sich nicht holen (Remote: $REMOTE_URL)."
    if [[ "$REMOTE_URL" == http* ]]; then
        if [[ -f "$GIT_STORE" ]]; then
            hinweis "Ein Token liegt vor – es passt offenbar nicht mehr (abgelaufen, ohne Leserecht?)."
            hinweis "Neues Token in ${GIT_CRED_FILE:-$ETC/git.conf} eintragen (GITHUB_TOKEN=…)."
        else
            hinweis "Ist das Repository privat? Dann ein Token hinterlegen:"
            hinweis "  install -d -m 750 $ETC"
            hinweis "  printf 'GITHUB_TOKEN=%s\\n' '<token>' > $ETC/git.conf; chmod 600 $ETC/git.conf"
        fi
    fi
    hinweis "Verändert wurde nichts."
    exit 1
fi
NEU="$(git -C "$ZIEL" rev-parse "origin/$ZWEIG")"

CODE_NEU="ja"
if [[ "$ALT" == "$NEU" ]]; then
    CODE_NEU="nein"
    echo "    Bereits auf dem neuesten Stand ($(git -C "$ZIEL" log -1 --format='%h %s'))."
    if [[ "$FLOWS_ERSETZEN" != "ja" ]]; then
        exit 0
    fi
    echo "    --flows-ersetzen: nur die Node-RED-Flows werden neu eingespielt."
else
    echo "    Neu:         $(git -C "$ZIEL" log -1 --format='%h %s' "$NEU")"
    echo
    git -C "$ZIEL" log --oneline --no-decorate "$ALT..$NEU" | sed 's/^/      /'
    MIGRATIONEN="$(git -C "$ZIEL" diff --name-only "$ALT" "$NEU" -- database/migrations || true)"
    if [[ -n "$MIGRATIONEN" ]]; then
        echo
        echo "    Neue Migrationen:"
        printf '%s\n' "$MIGRATIONEN" | sed 's/^/      /'
    fi
    if ! git -C "$ZIEL" diff --quiet "$ALT" "$NEU" -- deploy/node-red/flows.json; then
        echo
        echo "    Die Node-RED-Flows haben sich geändert."
    fi
fi

if [[ "$NUR_ZEIGEN" == "ja" ]]; then
    echo
    echo "    --pruefen: es wurde nichts verändert."
    exit 0
fi

# ================================================================ Sicherung
SICHERUNG=""
DB="$ZIEL/storage/database.sqlite"
if [[ "$CODE_NEU" == "ja" ]]; then
    schritt "Sicherung anlegen"
    BACKUP_DIR="$ZIEL/storage/backups"
    STEMPEL="$(date +%Y%m%d-%H%M%S)"
    app_verzeichnis "$BACKUP_DIR"
    TREIBER="$(as_app php -r '$c = require $argv[1]; echo $c["database"]["driver"] ?? "sqlite";' "$ZIEL/config/config.php" 2>/dev/null || echo sqlite)"

    ZUM_SICHERN=()
    if [[ "$TREIBER" == "sqlite" && -f "$DB" ]]; then
        # VACUUM INTO liefert eine in sich stimmige Kopie, auch während
        # Oberfläche und Sammler auf die Datenbank zugreifen (WAL-Modus) –
        # ein einfaches cp könnte eine halb geschriebene Seite erwischen.
        SCHNAPPSCHUSS_DIR="$BACKUP_DIR/.schnappschuss-$STEMPEL"
        SCHNAPPSCHUSS="$SCHNAPPSCHUSS_DIR/database.sqlite"
        app_verzeichnis "$SCHNAPPSCHUSS_DIR" 700
        as_app php -r '
            $pdo = new PDO("sqlite:" . $argv[1]);
            $pdo->exec("VACUUM INTO " . $pdo->quote($argv[2]));
        ' "$DB" "$SCHNAPPSCHUSS"
        ZUM_SICHERN+=(-C "$SCHNAPPSCHUSS_DIR" database.sqlite)
    elif [[ "$TREIBER" != "sqlite" ]]; then
        hinweis "Datenbank ist $TREIBER – die sichert dieses Skript nicht mit (mysqldump o. Ä. vorher selbst)."
    fi
    SICHERUNG="$BACKUP_DIR/vor-update-$STEMPEL.tar.gz"
    as_app tar czf "$SICHERUNG" -C "$ZIEL" config/config.php ${ZUM_SICHERN[@]+"${ZUM_SICHERN[@]}"}
    as_app chmod 600 -- "$SICHERUNG"
    echo "    $SICHERUNG ($(du -h "$SICHERUNG" | cut -f1))"

    # Ältere Sicherungen aufräumen
    # Die Namen erzeugt dieses Skript selbst (nur Ziffern und -), ls ist hier sicher.
    # shellcheck disable=SC2012
    mapfile -t ALTE < <(ls -1t "$BACKUP_DIR"/vor-update-*.tar.gz 2>/dev/null | tail -n "+$((BEHALTEN + 1))" || true)
    for datei in ${ALTE[@]+"${ALTE[@]}"}; do
        [[ -n "$datei" ]] && rm -f "$datei"
    done
fi

# ================================================================ Zurückrollen
MIGRIERT="nein"
SITE="/etc/nginx/sites-available/powermanagement"
SITE_SICHERUNG="$(mktemp)"
[[ -f "$SITE" ]] && cp -p "$SITE" "$SITE_SICHERUNG"

zurueckrollen() {
    # set -E vererbt den Trap auch in Subshells (as_app). Zurückrollen soll
    # nur der Hauptprozess, und zwar einmal – die Subshell meldet nur den
    # Fehler nach oben.
    if [[ "$BASHPID" != "$$" ]]; then
        exit 1
    fi
    trap - ERR
    hinweis "Das Update ist fehlgeschlagen – der alte Stand wird wiederhergestellt."
    systemctl stop powermanagement-sammler.service >/dev/null 2>&1 || true
    git -C "$ZIEL" reset --hard --quiet "$ALT" || true
    if [[ "$MIGRIERT" == "ja" && -n "${SCHNAPPSCHUSS:-}" && -f "$SCHNAPPSCHUSS" ]]; then
        # Migrationen haben die Datenbank womöglich halb umgebaut – die
        # Kopie von vorher zurücklegen.
        if as_app cp "$SCHNAPPSCHUSS" "$DB.zurueck" && mv "$DB.zurueck" "$DB"; then
            rm -f "$DB-wal" "$DB-shm"
            hinweis "Datenbank auf den Stand vor dem Update zurückgesetzt."
        else
            hinweis "Datenbank ließ sich nicht zurücklegen – Kopie von vorher: $SCHNAPPSCHUSS"
        fi
    fi
    if [[ -s "$SITE_SICHERUNG" ]]; then
        cp -p "$SITE_SICHERUNG" "$SITE"
        systemctl reload nginx >/dev/null 2>&1 || true
    fi
    FPM="$(find /etc/php -mindepth 2 -maxdepth 2 -type d -name fpm 2>/dev/null | awk -F/ '{print $4}' | sort -V | tail -1)"
    if [[ -n "$FPM" ]]; then
        systemctl restart "php$FPM-fpm" >/dev/null 2>&1 || true
    fi
    systemctl start powermanagement-sammler.service >/dev/null 2>&1 || true
    hinweis "Wiederhergestellt auf $(git -C "$ZIEL" log -1 --format='%h %s')."
    [[ -n "$SICHERUNG" ]] && hinweis "Sicherung: $SICHERUNG"
    exit 1
}
trap zurueckrollen ERR

if [[ "$CODE_NEU" == "ja" ]]; then
    # ============================================================ Quellcode
    schritt "Sammler anhalten und Quellcode aktualisieren"
    systemctl stop powermanagement-sammler.service >/dev/null 2>&1 || true
    if [[ "$ERZWINGEN" == "ja" ]]; then
        git -C "$ZIEL" reset --hard --quiet
        git -C "$ZIEL" checkout --quiet -B "$ZWEIG" "origin/$ZWEIG"
    else
        git -C "$ZIEL" checkout --quiet "$ZWEIG"
        git -C "$ZIEL" merge --ff-only --quiet "origin/$ZWEIG"
    fi
    echo "    Jetzt auf $(git -C "$ZIEL" log -1 --format='%h %s')"

    # Rechte wie bei der Installation: Code gehört root, storage/ der
    # Anwendung. storage/ und config.php bleiben dabei außen vor – die
    # Oberfläche läuft weiter und soll nicht kurz ihre Dateien verlieren.
    find "$ZIEL" -path "$ZIEL/storage" -prune -o ! -path "$ZIEL/config/config.php" \
        -exec chown root:root {} + -exec chmod go+rX,go-w {} +
    for d in logs plans demo cache sessions backups; do
        app_verzeichnis "$ZIEL/storage/$d"
    done
    chown -R -P "$APP_USER:$APP_USER" "$ZIEL/storage"
    chown "root:$APP_USER" "$ZIEL/config/config.php"
    chmod 640 "$ZIEL/config/config.php"

    # ============================================================ Datenbank
    schritt "Migrationen ausführen"
    MIGRIERT="ja"
    as_app php "$ZIEL/bin/power" migrate

    # ============================================================ Pakete
    schritt "Fehlende Pakete nachziehen"
    # Neuere Stände brauchen womöglich Pakete, die ältere Installationen
    # nicht haben. Nachinstalliert wird nur, was fehlt – Aktualisierungen
    # vorhandener Pakete bleiben apt bzw. unattended-upgrades überlassen.
    GEBRAUCHT=(nginx php-fpm php-cli php-sqlite3 php-gd php-curl php-mbstring php-xml
               poppler-utils git curl ca-certificates gnupg openssl logrotate
               wireguard-tools mosquitto-clients)
    [[ "$MIT_MOSQUITTO" == "ja" ]] && GEBRAUCHT+=(mosquitto)
    [[ "$MIT_NODERED" == "ja" ]] && GEBRAUCHT+=(nodejs npm)
    [[ "$MIT_INFLUX" == "ja" ]] && GEBRAUCHT+=(influxdb2 influxdb2-cli)
    FEHLT=()
    for p in "${GEBRAUCHT[@]}"; do
        dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q "install ok installed" || FEHLT+=("$p")
    done
    if [[ ${#FEHLT[@]} -gt 0 ]]; then
        echo "    Nachinstallieren: ${FEHLT[*]}"
        export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1
        apt-get update -qq
        apt-get install -y --no-install-recommends "${FEHLT[@]}" >/dev/null
    else
        echo "    Alles vorhanden."
    fi
    if [[ "$MIT_NODERED" == "ja" && ! -f "$NODERED_APP/node_modules/node-red/package.json" ]]; then
        echo "    Node-RED fehlt in $NODERED_APP – wird installiert …"
        install -d -m 755 "$NODERED_APP"
        npm install --prefix "$NODERED_APP" --omit=dev --no-audit --no-fund --loglevel=error "node-red@4" >/dev/null
    fi
fi

# ================================================================ Dienste und Vorlagen
schritt "Dienste und Konfiguration abgleichen"
FPM_VERSION="$(find /etc/php -mindepth 2 -maxdepth 2 -type d -name fpm 2>/dev/null \
    | awk -F/ '{print $4}' | sort -V | tail -1)"
NODERED_NEUSTART="nein"
MOSQUITTO_NEUSTART="nein"
EINHEITEN_NEU="nein"

# systemd-Einheiten: gehören der Anwendung, werden immer abgeglichen.
EINHEITEN=(powermanagement-sammler.service powermanagement-wartung.service powermanagement-wartung.timer)
[[ "$MIT_NODERED" == "ja" ]] && EINHEITEN+=(powermanagement-nodered.service)
for einheit in "${EINHEITEN[@]}"; do
    [[ -f "$ZIEL/deploy/$einheit" ]] || continue
    gerendert="$(sed -e "s|__APP_USER__|$APP_USER|g" -e "s|__APP_DIR__|$ZIEL|g" \
        -e "s|__NODERED_APP__|$NODERED_APP|g" -e "s|__NODERED_DIR__|$NODERED_DIR|g" "$ZIEL/deploy/$einheit")"
    ziel="/etc/systemd/system/$einheit"
    if [[ ! -f "$ziel" ]] || [[ "$gerendert" != "$(cat "$ziel")" ]]; then
        printf '%s\n' "$gerendert" > "$ziel"
        echo "    $einheit aktualisiert"
        EINHEITEN_NEU="ja"
        [[ "$einheit" == powermanagement-nodered.service ]] && NODERED_NEUSTART="ja"
    fi
done
if [[ "$EINHEITEN_NEU" == "ja" ]]; then
    systemctl daemon-reload
fi
# Einschalten *und* starten, bei jedem Lauf: „enable" allein wirkt erst
# beim nächsten Neustart des Containers – und der kommt im LXC selten.
systemctl enable --quiet powermanagement-sammler.service
systemctl enable --quiet --now powermanagement-wartung.timer
[[ "$MIT_NODERED" == "ja" ]] && systemctl enable --quiet powermanagement-nodered.service

sed -e "s|__APP_USER__|$APP_USER|g" -e "s|__APP_DIR__|$ZIEL|g" \
    "$ZIEL/deploy/powermanagement.logrotate" > /etc/logrotate.d/powermanagement

# PHP-FPM-Pool: gehört der Anwendung (Upload-Grenzen, open_basedir).
if [[ -n "$FPM_VERSION" ]]; then
    sed -e "s|__APP_USER__|$APP_USER|g" -e "s|__APP_DIR__|$ZIEL|g" \
        "$ZIEL/deploy/php-fpm-pool.conf" > "/etc/php/$FPM_VERSION/fpm/pool.d/powermanagement.conf"
fi

# nginx-Site: nur ersetzen, wenn niemand sie verändert hat. Port, Adresse
# und Servername stehen in der vorhandenen Site – daraus neu erzeugen.
if [[ -f "$SITE" ]]; then
    LISTEN_ZEILE="$(grep -m1 -E '^\s*listen\s+[^[]' "$SITE" | sed -E 's/^\s*listen\s+([^ ;]+).*/\1/' || true)"
    if [[ "$LISTEN_ZEILE" == *:* ]]; then
        S_LISTEN="${LISTEN_ZEILE%:*}"
        S_PORT="${LISTEN_ZEILE##*:}"
    else
        S_LISTEN="0.0.0.0"
        S_PORT="$LISTEN_ZEILE"
    fi
    S_NAME="$(grep -m1 -E '^\s*server_name\s' "$SITE" | sed -E 's/^\s*server_name\s+([^;]+);.*/\1/' || true)"
    SITE_NEU="$(mktemp)"
    sed -e "s|__APP_DIR__|$ZIEL|g" \
        -e "s|__FPM_SOCKET__|/run/php/powermanagement.sock|g" \
        -e "s|__SERVER_NAME__|${S_NAME:-powermanagement.lan}|g" \
        -e "s|__LISTEN__|${S_LISTEN:-0.0.0.0}|g" \
        -e "s|__PORT__|${S_PORT:-80}|g" \
        "$ZIEL/deploy/nginx-powermanagement.conf" > "$SITE_NEU"
    # Ohne default_server betrieben (Zusammenstoß mit einer anderen Seite)?
    # Dann auch die neue Fassung ohne.
    if ! grep -qE '^\s*listen\s.*default_server' "$SITE"; then
        sed -i -E 's/^(\s*listen\s+[^[;]+) default_server;/\1;/' "$SITE_NEU"
    fi
    vorlage_uebernehmen "$SITE_NEU" "$SITE" root:root 644 /etc/nginx/powermanagement.powermanagement-neu
    rm -f "$SITE_NEU"
    case "$VORLAGE_ERGEBNIS" in
        ersetzt)  echo "    nginx-Site aktualisiert" ;;
        behalten) hinweis "Die nginx-Site ist von Hand verändert und bleibt so. Neue Fassung:"
                  hinweis "  /etc/nginx/powermanagement.powermanagement-neu (diff -u $SITE /etc/nginx/powermanagement.powermanagement-neu)" ;;
    esac
    nginx -t 2>&1 | sed 's/^/    /'
fi

if [[ "$MIT_MOSQUITTO" == "ja" ]]; then
    vorlage_uebernehmen "$ZIEL/deploy/mosquitto/powermanagement.conf" \
        /etc/mosquitto/conf.d/powermanagement.conf root:root 644 \
        /etc/mosquitto/powermanagement.conf.powermanagement-neu
    [[ "$VORLAGE_ERGEBNIS" == "ersetzt" || "$VORLAGE_ERGEBNIS" == "neu" ]] && MOSQUITTO_NEUSTART="ja"
    [[ "$VORLAGE_ERGEBNIS" == "behalten" ]] && hinweis "Mosquitto-Konfiguration verändert – neue Fassung: /etc/mosquitto/powermanagement.conf.powermanagement-neu"
    vorlage_uebernehmen "$ZIEL/deploy/mosquitto/powermanagement.acl" \
        /etc/mosquitto/powermanagement.acl mosquitto:mosquitto 600 \
        /etc/mosquitto/powermanagement.acl.powermanagement-neu
    [[ "$VORLAGE_ERGEBNIS" == "ersetzt" || "$VORLAGE_ERGEBNIS" == "neu" ]] && MOSQUITTO_NEUSTART="ja"
    [[ "$VORLAGE_ERGEBNIS" == "behalten" ]] && hinweis "Mosquitto-ACL verändert – neue Fassung: /etc/mosquitto/powermanagement.acl.powermanagement-neu"
fi

if [[ "$MIT_NODERED" == "ja" && -d "$NODERED_DIR" ]]; then
    vorlage_uebernehmen "$ZIEL/deploy/node-red/settings.js" "$NODERED_DIR/settings.js" nodered:nodered 640 \
        "$NODERED_DIR/settings.powermanagement-neu.js"
    [[ "$VORLAGE_ERGEBNIS" == "ersetzt" || "$VORLAGE_ERGEBNIS" == "neu" ]] && NODERED_NEUSTART="ja"
    [[ "$VORLAGE_ERGEBNIS" == "behalten" ]] && hinweis "settings.js ist verändert – neue Fassung: $NODERED_DIR/settings.powermanagement-neu.js"

    # Flows: eigene Änderungen gehen vor. Nur wenn seit der letzten
    # Auslieferung niemand im Editor etwas geändert (und bereitgestellt)
    # hat, wird ersetzt – sonst liegt die neue Fassung daneben.
    vorlage_uebernehmen "$ZIEL/deploy/node-red/flows.json" "$NODERED_DIR/flows.json" nodered:nodered 640 \
        "$NODERED_DIR/flows.powermanagement-neu.json" "$FLOWS_ERSETZEN"
    case "$VORLAGE_ERGEBNIS" in
        neu|ersetzt)
            echo "    Node-RED-Flows auf die mitgelieferte Fassung gebracht"
            NODERED_NEUSTART="ja" ;;
        behalten)
            hinweis "Die Node-RED-Flows wurden im Editor verändert und bleiben, wie sie sind."
            hinweis "Die neue Fassung liegt unter $NODERED_DIR/flows.powermanagement-neu.json –"
            hinweis "im Editor über Menü → Importieren übernehmen, oder: $0 --flows-ersetzen" ;;
    esac
fi

# ================================================================ Neustart
schritt "Dienste starten"
if [[ -n "$FPM_VERSION" ]]; then
    systemctl restart "php$FPM_VERSION-fpm"
fi
systemctl reload nginx || systemctl restart nginx
if [[ "$MOSQUITTO_NEUSTART" == "ja" ]]; then
    systemctl restart mosquitto
    echo "    mosquitto neu gestartet"
fi
if [[ "$MIT_NODERED" == "ja" && "$NODERED_NEUSTART" == "ja" ]]; then
    systemctl restart powermanagement-nodered.service
    echo "    Node-RED neu gestartet"
fi
systemctl restart powermanagement-sammler.service
echo "    Sammler läuft wieder"

# Ab hier ist das Update eingespielt – was jetzt noch scheitert, rollt es
# nicht mehr zurück.
trap - ERR
rm -f "$SITE_SICHERUNG"
[[ -n "${SCHNAPPSCHUSS_DIR:-}" ]] && rm -rf "$SCHNAPPSCHUSS_DIR"

if [[ "$CODE_NEU" == "ja" ]]; then
    # Bewusst am Ende: das Kacheln großer Grundrisse dauert, und alles
    # andere läuft da schon wieder.
    schritt "Grundrisse kacheln"
    if ! as_app php "$ZIEL/bin/power" plan:kacheln; then
        hinweis "Kacheln ließen sich nicht berechnen – die Oberfläche holt das beim ersten Aufruf nach."
    fi
fi

schritt "Prüfen"
sleep 3
PRUEF_RC=0
as_app php "$ZIEL/bin/power" pruefen 2>&1 | sed 's/^/    /' || PRUEF_RC=$?
if [[ "$PRUEF_RC" -ne 0 ]]; then
    hinweis "bin/power pruefen meldet Fehler (siehe oben) – das Update selbst ist eingespielt."
fi

schritt "Fertig"
cat <<ZUSAMMENFASSUNG

  Vorher:    $(git -C "$ZIEL" log -1 --format='%h' "$ALT")
  Jetzt:     $(git -C "$ZIEL" log -1 --format='%h %s')
ZUSAMMENFASSUNG
if [[ -n "$SICHERUNG" ]]; then
    cat <<ZUSAMMENFASSUNG
  Sicherung: $SICHERUNG

  Zurückrollen, falls nötig (die Sicherung enthält config/config.php und
  database.sqlite – mit app.key, also nicht nach /tmp auspacken):
    systemctl stop powermanagement-sammler
    git -C $ZIEL reset --hard $ALT
    install -d -m 700 /root/pm-zurueck && tar xzf $SICHERUNG -C /root/pm-zurueck
    install -o $APP_USER -g $APP_USER -m 640 /root/pm-zurueck/database.sqlite $DB
    rm -f $DB-wal $DB-shm
    systemctl start powermanagement-sammler

ZUSAMMENFASSUNG
fi
