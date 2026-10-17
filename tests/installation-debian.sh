#!/usr/bin/env bash
#
# Installation auf einem frischen Debian 13 durchspielen – so, wie sie in
# einem Proxmox-LXC läuft: systemd als PID 1, root, Grundsystem nach
# docs/installation-debian-lxc.md (Abschnitt 2), Checkout unter
# /opt/powermanagement, dann deploy/install-debian.sh.
#
# Der Container (Docker, privilegiert – sonst kein systemd) wird gebaut,
# geprüft und wieder entfernt. Geprüft wird:
#
#   1. Erstinstallation: Code gehört root, storage/ der Anwendung, alle
#      Dienste laufen, die Oberfläche antwortet, Anmeldung mit dem
#      Erstkennwort, bin/power pruefen ohne Fehler, Broker nur mit Kennwort
#   2. derselbe Aufruf noch einmal, inzwischen mit einer neuen Fassung des
#      Installationsskripts im Repository: das Skript holt sie und startet
#      sie (einmal), der Zweig bleibt; app.key, Kennwörter und Administrator
#      bleiben, alles läuft weiter
#   3. Update auf einen neuen Stand, der einen Ordner in storage/ mitbringt,
#      danach ein Update ohne Neues
#   3b. Update ohne HOME und ohne Terminal (systemd-run, wie ein Dienst):
#      läuft durch, Ausgabe ohne Farben, kein globales safe.directory
#
# Aufruf:  tests/installation-debian.sh
#          PM_BEHALTEN=1 tests/installation-debian.sh   Container bleibt stehen
#          PM_BASIS=<bild> tests/installation-debian.sh anderes Debian-13-Bild
#
# Braucht Docker und Netz (Debian-Spiegel, repos.influxdata.com, npm). Läuft
# in der CI (Job „Installation“); auf dem eigenen Rechner nur mit Docker.

set -euo pipefail

if [[ "${1:-}" != "--im-container" ]]; then
    # ======================================================== außerhalb
    REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
    NAME="pm-installation-$$"
    BILD="pm-installation-debian13"
    command -v docker >/dev/null 2>&1 || { echo "Docker fehlt." >&2; exit 1; }

    BASIS="${PM_BASIS:-}"
    if [[ -z "$BASIS" ]]; then
        # Docker Hub begrenzt anonyme Abrufe – dann der Spiegel von Google.
        BASIS="debian:trixie"
        docker pull -q "$BASIS" >/dev/null 2>&1 || BASIS="mirror.gcr.io/library/debian:trixie"
    fi
    echo "Bild: $BASIS + systemd"
    # Nur systemd dazu – alles andere muss das Skript (oder Abschnitt 2 der
    # Anleitung) selbst mitbringen, wie auf der Proxmox-Vorlage.
    docker build -q -t "$BILD" --build-arg "BASIS=$BASIS" - >/dev/null <<'DOCKERFILE'
ARG BASIS
FROM ${BASIS}
RUN apt-get update -qq \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends \
        systemd systemd-sysv dbus iproute2 >/dev/null \
 && rm -rf /var/lib/apt/lists/*
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
DOCKERFILE

    aufraeumen() {
        local rc=$?
        if [[ $rc -ne 0 ]]; then
            echo
            echo "=== Diagnose (Installation fehlgeschlagen) ==="
            docker exec "$NAME" bash -c '
                systemctl --no-pager --failed
                for u in nginx mosquitto influxdb powermanagement-nodered powermanagement-sammler \
                         powermanagement-mqtt-zugaenge powermanagement-wartung; do
                    echo "--- journalctl -u $u"; journalctl -u "$u" --no-pager -n 30 2>/dev/null
                done
                for f in /var/log/nginx/powermanagement.error.log /opt/powermanagement/storage/logs/*.log; do
                    [ -f "$f" ] && { echo "--- $f"; tail -n 30 "$f"; }
                done
                echo "--- storage/"; ls -la /opt/powermanagement/storage 2>/dev/null' || true
        fi
        if [[ "${PM_BEHALTEN:-}" == "1" ]]; then
            echo "Container bleibt stehen: docker exec -it $NAME bash   (entfernen: docker rm -f $NAME)"
        else
            docker rm -f "$NAME" >/dev/null 2>&1 || true
        fi
        exit "$rc"
    }
    trap aufraeumen EXIT

    docker run -d --name "$NAME" --hostname pm-pruefung --privileged --cgroupns=host \
        -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock \
        -v "$REPO_DIR:/src:ro" "$BILD" >/dev/null
    # Warten, bis systemd hochgefahren ist. „degraded“ genügt: im Container
    # scheitern Einheiten, die es im LXC so nicht gibt.
    zustand=""
    for _ in $(seq 1 60); do
        zustand="$(docker exec "$NAME" systemctl is-system-running 2>/dev/null || true)"
        [[ "$zustand" == "running" || "$zustand" == "degraded" ]] && break
        sleep 1
    done
    echo "systemd im Container: ${zustand:-?}"
    docker exec "$NAME" bash /src/tests/installation-debian.sh --im-container
    exit 0
fi

# ============================================================ im Container
export DEBIAN_FRONTEND=noninteractive
ZIEL=/opt/powermanagement
QUELLE=/srv/pm-quelle   # Arbeitskopie, aus der die Stände kommen
REMOTE=/srv/pm.git      # spielt GitHub: daraus klonen und aktualisieren
ZUGAENGE=/etc/powermanagement/zugaenge.txt
LAUF=/root/lauf.log
APP_VERZEICHNISSE=(storage storage/logs storage/plans storage/demo storage/cache storage/inventar
                   storage/sessions storage/backups storage/mqtt storage/mqtt/soll storage/mqtt/ist)
FEHLER=0

schritt() { printf '\n\033[1;35m### %s\033[0m\n' "$1"; }
ok()      { printf '    ok      %s\n' "$1"; }
fehler()  { printf '    FEHLER  %s\n' "$1"; FEHLER=$((FEHLER + 1)); }
pruefe()  { local text="$1"; shift; if "$@"; then ok "$text"; else fehler "$text"; fi; }
leer()    { [[ -z "$1" ]] || { printf '%s\n' "$1" | head -5 | sed 's/^/            /'; return 1; }; }
zugang()  { sed -n "s/^$1=//p" "$ZUGAENGE" | tail -1; }
abschluss() {
    if (( FEHLER > 0 )); then
        echo
        echo "$FEHLER Prüfung(en) fehlgeschlagen ($1)." >&2
        exit 1
    fi
}
einchecken() {
    GIT_AUTHOR_NAME=Prüfung GIT_AUTHOR_EMAIL=pruefung@example.invalid \
    GIT_COMMITTER_NAME=Prüfung GIT_COMMITTER_EMAIL=pruefung@example.invalid \
        git -C "$QUELLE" commit -q -m "$1"
}
# neuer_stand <betreff> <bash-befehl>: den Befehl in $QUELLE ausführen,
# einchecken, nach $REMOTE schieben; gibt den neuen Commit aus.
neuer_stand() {
    (cd "$QUELLE" && bash -euo pipefail -c "$2") >&2
    git -C "$QUELLE" add -A -f
    einchecken "$1"
    git -C "$QUELLE" push -q origin main
    git -C "$QUELLE" rev-parse HEAD
}
ohne_esc() { ! grep -q $'\033' "$1"; }

# Erst den Stand der Anwendung abwarten, dann prüfen, was ein Betreiber
# nach der Installation vorfinden muss.
pruefe_installation() {
    local einheit fpm code d antwort jar seite token kennwort i ausgabe pruef_ok

    # Der Abgleich der MQTT-Zugänge läuft am Ende ohne Warten an.
    for _ in $(seq 1 30); do
        [[ "$(systemctl is-active powermanagement-mqtt-zugaenge.service 2>/dev/null || true)" != "activating" ]] && break
        sleep 1
    done

    fpm="$(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}' | sort -V | tail -1)"
    for einheit in nginx "${fpm:-php-fpm}" mosquitto influxdb powermanagement-nodered powermanagement-sammler \
                   powermanagement-wartung.timer powermanagement-mqtt-zugaenge.path powermanagement-mqtt-zugaenge.timer; do
        pruefe "$einheit läuft" systemctl is-active --quiet "$einheit"
    done
    pruefe "Abgleich der MQTT-Zugänge ohne Fehler" bash -c '! systemctl is-failed --quiet powermanagement-mqtt-zugaenge.service'

    # Rechte: Code gehört root, storage/ der Anwendung, nichts für alle
    # beschreibbar, Geheimnisse nur für die, die sie brauchen.
    pruefe "Quellcode gehört root" leer "$(find "$ZIEL" -path "$ZIEL/storage" -prune -o \
        -path "$ZIEL/config/config.php" -prune -o ! -user root -print)"
    pruefe "storage/ gehört www-data" leer "$(find "$ZIEL/storage" ! -user www-data -print)"
    for d in "${APP_VERZEICHNISSE[@]}"; do
        pruefe "$d: 750" test "$(stat -c %a "$ZIEL/$d" 2>/dev/null)" = 750
    done
    pruefe "config/config.php: root:www-data 640" \
        test "$(stat -c '%U:%G %a' "$ZIEL/config/config.php")" = "root:www-data 640"
    pruefe "nichts für alle beschreibbar" leer "$(find "$ZIEL" ! -type l -perm -o+w -print)"
    pruefe "zugaenge.txt nur für root" test "$(stat -c '%U %a' "$ZUGAENGE")" = "root 600"

    # Oberfläche über nginx und PHP-FPM
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H 'Host: powermanagement.lan' \
        http://127.0.0.1/login 2>/dev/null)" || true
    pruefe "Anmeldeseite antwortet mit 200 (kam: ${code:-000})" test "${code:-000}" = 200

    # Anmelden mit dem Erstkennwort: Sitzung (storage/sessions), CSRF und
    # Datenbank arbeiten; danach geht es zum Kennwortwechsel.
    jar="$(mktemp)"
    seite="$(curl -s --max-time 10 -c "$jar" -b "$jar" -H 'Host: powermanagement.lan' http://127.0.0.1/login)" || true
    token="$(printf '%s' "$seite" | sed -n 's/.*name="_token" value="\([0-9a-f]*\)".*/\1/p' | head -1)"
    kennwort="$(zugang OBERFLAECHE_KENNWORT)"
    antwort="$(curl -s -o /dev/null --max-time 10 -w '%{http_code} %{redirect_url}' -c "$jar" -b "$jar" \
        -H 'Host: powermanagement.lan' --data-urlencode "_token=$token" \
        --data-urlencode "username=$(zugang OBERFLAECHE_BENUTZER)" --data-urlencode "password=$kennwort" \
        http://127.0.0.1/login)" || true
    rm -f "$jar"
    pruefe "Anmeldung mit dem Erstkennwort führt zum Kennwortwechsel (kam: $antwort)" \
        bash -c '[[ "$1" == "302 "*"/profil" ]]' _ "$antwort"

    # Broker: Shellys mit Kennwort ja, ohne oder mit falschem nein
    kennwort="$(zugang MQTT_SHELLY_KENNWORT)"
    pruefe "MQTT: Shelly-Zugang wird angenommen" \
        mosquitto_pub -h 127.0.0.1 -p 1883 -u shelly -P "$kennwort" -q 1 -t shellies/pruefung/online -m true
    pruefe "MQTT: falsches Kennwort wird abgewiesen" \
        bash -c '! mosquitto_pub -h 127.0.0.1 -p 1883 -u shelly -P falsch -t shellies/pruefung/online -m true 2>/dev/null'
    pruefe "MQTT: ohne Anmeldung wird abgewiesen" \
        bash -c '! mosquitto_pub -h 127.0.0.1 -p 1883 -t shellies/pruefung/online -m true 2>/dev/null'

    # Selbstprüfung der Anwendung (MQTT, InfluxDB, Node-RED-Herzschlag …).
    # Node-RED braucht nach einem Neustart etwas, bis es sich meldet.
    pruef_ok="nein"
    ausgabe=""
    for i in $(seq 1 30); do
        if ausgabe="$(cd "$ZIEL" && runuser -u www-data -- php bin/power pruefen 2>&1)"; then
            pruef_ok="ja"
            break
        fi
        sleep 3
    done
    if [[ "$pruef_ok" == "ja" ]]; then
        ok "bin/power pruefen ohne Fehler (Versuch $i)"
    else
        fehler "bin/power pruefen meldet Fehler:"
        printf '%s\n' "$ausgabe" | sed 's/^/            /'
    fi
}

# ------------------------------------------------------------ Grundsystem
schritt "Grundsystem wie in docs/installation-debian-lxc.md, Abschnitt 2"
apt-get update -qq
apt-get install -y -qq ca-certificates curl git locales tzdata >/dev/null
echo "    $(. /etc/os-release && echo "$PRETTY_NAME"), $(git --version)"

schritt "Repository vorbereiten (Arbeitsstand aus /src)"
rm -rf "$QUELLE" "$REMOTE" "$ZIEL"
mkdir -p "$QUELLE"
# Die verfolgten Dateien im Arbeitsstand – auf dem eigenen Rechner also auch
# noch nicht eingecheckte Änderungen. Laufzeitdaten (config.php,
# Datenbank) bleiben draußen.
git -c safe.directory=/src -C /src ls-files -z \
    | tar -C /src --null --ignore-failed-read -T - -cf - 2>/dev/null \
    | tar -C "$QUELLE" --no-same-owner -xf -
git -C "$QUELLE" init -q -b main
git -C "$QUELLE" add -A -f
einchecken "Stand zum Prüfen"
git clone -q --bare "$QUELLE" "$REMOTE"
git -C "$QUELLE" remote add origin "$REMOTE"
echo "    $(git -C "$QUELLE" ls-files | wc -l) Dateien"

# ------------------------------------------------------------ 1
schritt "1 · Erstinstallation (Checkout unter $ZIEL, wie in der Anleitung)"
git clone -q "$REMOTE" "$ZIEL"
if ! "$ZIEL/deploy/install-debian.sh" --name="Prüfung Installation" > "$LAUF" 2>&1; then
    cat "$LAUF"
    fehler "deploy/install-debian.sh bricht ab"
    abschluss "Erstinstallation"
fi
sed 's/^/  | /' "$LAUF"
ok "deploy/install-debian.sh läuft durch"
pruefe "Administrator angelegt" grep -q 'Erstkennwort – bitte bei der ersten Anmeldung ändern' "$LAUF"
pruefe "Abschlussprüfung des Skripts ohne Fehler" grep -q 'Prüfung:      in Ordnung' "$LAUF"
pruefe_installation
abschluss "Erstinstallation"

# ------------------------------------------------------------ 2
schritt "2 · Derselbe Aufruf noch einmal (das Repository hat inzwischen ein neues Installationsskript)"
KONFIG_VORHER="$(sha256sum "$ZIEL/config/config.php")"
ZUGAENGE_VORHER="$(grep -E '^[A-Z0-9_]+=' "$ZUGAENGE" | sort)"
# Nur die neue Fassung gibt diese Zeile aus – die laufende alte sieht sie nie.
MARKE="Prüfung: neue Fassung des Installationsskripts"
NEU="$(neuer_stand "Neue Fassung des Installationsskripts" \
    "sed -i '/^PM_ARGS=/a echo \"    $MARKE\"' deploy/install-debian.sh")"
if ! "$ZIEL/deploy/install-debian.sh" --name="Prüfung Installation" > "$LAUF" 2>&1; then
    cat "$LAUF"
    fehler "deploy/install-debian.sh bricht beim zweiten Lauf ab"
    abschluss "zweiter Lauf"
fi
sed 's/^/  | /' "$LAUF"
ok "deploy/install-debian.sh läuft auch ein zweites Mal durch"
pruefe "auf dem neuen Stand" test "$(git -C "$ZIEL" rev-parse HEAD)" = "$NEU"
pruefe "Zweig bleibt main" test "$(git -C "$ZIEL" symbolic-ref --short HEAD)" = main
pruefe "neue Fassung des Skripts neu gestartet" grep -q 'Neue Fassung des Installationsskripts – neu gestartet' "$LAUF"
pruefe "neue Fassung läuft genau einmal" test "$(grep -c "$MARKE" "$LAUF")" = 1
pruefe "Ausgabe ohne Farben (kein Terminal)" ohne_esc "$LAUF"
pruefe "config/config.php (app.key) unverändert" test "$(sha256sum "$ZIEL/config/config.php")" = "$KONFIG_VORHER"
pruefe "Kennwörter und Tokens unverändert" test "$(grep -E '^[A-Z0-9_]+=' "$ZUGAENGE" | sort)" = "$ZUGAENGE_VORHER"
pruefe "Administrator nicht neu angelegt" grep -q 'Administrator bestand schon' "$LAUF"
pruefe_installation
abschluss "zweiter Lauf"

# ------------------------------------------------------------ 3
schritt "3 · Update auf einen neuen Stand mit neuem Ordner in storage/"
mkdir -p "$QUELLE/storage/pruefung-update"
: > "$QUELLE/storage/pruefung-update/.gitkeep"
printf '\n' >> "$QUELLE/README.md"
git -C "$QUELLE" add -f storage/pruefung-update/.gitkeep README.md
einchecken "Neuer Stand mit einem Ordner in storage/"
git -C "$QUELLE" push -q origin main
NEU="$(git -C "$QUELLE" rev-parse HEAD)"
if ! "$ZIEL/deploy/update-debian.sh" > "$LAUF" 2>&1; then
    cat "$LAUF"
    fehler "deploy/update-debian.sh bricht ab"
    abschluss "Update"
fi
sed 's/^/  | /' "$LAUF"
ok "deploy/update-debian.sh läuft durch"
pruefe "auf dem neuen Stand" test "$(git -C "$ZIEL" rev-parse HEAD)" = "$NEU"
pruefe "neuer Ordner in storage/ gehört www-data" \
    test "$(stat -c %U:%G "$ZIEL/storage/pruefung-update" 2>/dev/null)" = "www-data:www-data"
pruefe_installation

if ! "$ZIEL/deploy/update-debian.sh" > "$LAUF" 2>&1; then
    cat "$LAUF"
    fehler "deploy/update-debian.sh ohne Neues bricht ab"
else
    pruefe "Update ohne Neues meldet den Stand" grep -q 'Bereits auf dem neuesten Stand' "$LAUF"
fi
abschluss "Update"

# ------------------------------------------------------------ 3b
schritt "3b · Update ohne HOME und ohne Terminal (systemd-run, wie ein Dienst)"
NEU="$(neuer_stand "Stand für ein Update ohne HOME" "printf '\n' >> README.md")"
# Eine Einheit ohne User= bekommt von systemd kein HOME und kein Terminal.
RC=0
systemd-run --quiet --wait --collect --unit=pm-pruefung-ohne-home \
    "$ZIEL/deploy/update-debian.sh" || RC=$?
journalctl --sync 2>/dev/null || true
journalctl -a -u pm-pruefung-ohne-home --no-pager -o cat > "$LAUF" 2>&1 || true
sed 's/^/  | /' "$LAUF"
pruefe "update-debian.sh ohne HOME endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "auf dem neuen Stand" test "$(git -C "$ZIEL" rev-parse HEAD)" = "$NEU"
pruefe "Ausgabe ohne Farben (kein Terminal)" ohne_esc "$LAUF"
pruefe "kein globales safe.directory (git config --global)" \
    bash -c '! git config --global --get-all safe.directory >/dev/null'
pruefe_installation
abschluss "Update ohne HOME"

echo
echo "Installation, zweiter Lauf, Update und Update ohne HOME in Ordnung."
