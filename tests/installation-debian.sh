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
#   4. Selbst-Update über die Konsole: die neue Fassung des Update-Skripts
#      übernimmt im selben Lauf (Stufe 2); --pruefen ändert nichts
#   5a. kaputte Migration: vollständig zurück (rc 2) – Code, Zweig, Schema,
#      Einheiten und Vorlagen wie vorher, der Stand gilt als gescheitert
#   5b. Prüfung scheitert erst nach Abgleich und Neustart (Anmeldeseite 500):
#      auch neue Einheit, Logrotate, Pool, Site samt Merker kommen zurück
#   6. Abbruch (systemctl stop) und Absturz (SIGKILL) mitten im Update:
#      Zurückrollen bzw. --wiederaufnehmen von Hand; ein zweiter Lauf
#      wartet nicht, sondern meldet „besetzt“
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
                for f in /var/log/nginx/powermanagement.error.log /opt/powermanagement/storage/logs/*.log \
                         /var/lib/powermanagement/update/offen /var/lib/powermanagement/update/verlauf \
                         /var/log/powermanagement/update/*.log; do
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
                   storage/sessions storage/backups storage/mqtt storage/mqtt/soll storage/mqtt/ist
                   storage/update storage/update/soll storage/update/ist)
MARKE_OFFEN=/var/lib/powermanagement/update/offen
GESCHEITERT=/var/lib/powermanagement/update/gescheitert
WARTUNG="$ZIEL/storage/update/ist/wartung"
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
kopf()     { git -C "$ZIEL" rev-parse HEAD; }
zweig()    { git -C "$ZIEL" symbolic-ref --short -q HEAD || echo -; }
enthaelt() { grep -qF -- "$2" "$1"; }
# update_lauf [optionen]: update-debian.sh direkt, Ausgabe nach $LAUF, rc in RC
update_lauf() {
    RC=0
    "$ZIEL/deploy/update-debian.sh" "$@" > "$LAUF" 2>&1 < /dev/null || RC=$?
    sed 's/^/  | /' "$LAUF"
}
# Alles, was ein Zurückrollen wiederherstellen muss: Einheiten, Logrotate,
# Pool, Site, Mosquitto, die gemerkten Vorlagen, das Schema, der Zweig.
pruefsummen() {
    sha256sum /etc/systemd/system/powermanagement-* /etc/logrotate.d/powermanagement \
        /etc/php/*/fpm/pool.d/powermanagement.conf /etc/nginx/sites-available/powermanagement \
        /etc/mosquitto/powermanagement.acl /etc/mosquitto/conf.d/powermanagement.conf \
        /var/lib/powermanagement/vorlagen/* 2>&1
    runuser -u www-data -- php -r '$p = new PDO("sqlite:" . $argv[1]);
        foreach ($p->query("SELECT name, sql FROM sqlite_master ORDER BY name") as $r) { echo $r[0], "|", $r[1], "\n"; }' \
        "$ZIEL/storage/database.sqlite" | sha256sum
    git -C "$ZIEL" symbolic-ref HEAD
}
# fortschritt_abwarten <datei> <schritt>: bis der Fortschritt mindestens dort steht (≤ 300 s)
fortschritt_abwarten() {
    local n=0
    for _ in $(seq 1 300); do
        n="$(awk '{print $2}' "$1" 2>/dev/null || true)"
        [[ "${n:-0}" =~ ^[0-9]+$ && "${n:-0}" -ge "$2" ]] && return 0
        sleep 1
    done
    return 1
}
# einheit_abwarten <einheit>: bis sie nicht mehr läuft (≤ 1200 s)
einheit_abwarten() {
    for _ in $(seq 1 1200); do
        case "$(systemctl is-active "$1" 2>/dev/null || true)" in
            active|activating|deactivating|reloading) sleep 1 ;;
            *) return 0 ;;
        esac
    done
    return 1
}

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
    # Zustand und Protokolle der Updates nur für root; kein Update offen
    for d in /var/lib/powermanagement/update /var/log/powermanagement/update; do
        pruefe "$d: root 700" test "$(stat -c '%U %a' "$d" 2>/dev/null)" = "root 700"
    done
    pruefe "keine Marke eines unterbrochenen Updates" test ! -e "$MARKE_OFFEN"
    pruefe "keine Schreibsperre der Oberfläche" test ! -e "$WARTUNG"

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
pruefe "Ergebnis eingespielt" enthaelt "$LAUF" "==> Ergebnis: eingespielt"
pruefe_installation
abschluss "Update ohne HOME"

# ------------------------------------------------------------ 4
schritt "4 · Selbst-Update über die Konsole: die neue Fassung übernimmt im selben Lauf"
ALT="$(kopf)"
# Nur die neue Fassung gibt diese Zeile aus – Stufe 1 (die laufende, alte
# Fassung) kennt sie nicht. Erscheint sie, hat Stufe 2 der neuen übernommen.
PRUEFZEILE="Prüfung: Stufe 2 der neuen Fassung"
NEU="$(neuer_stand "Neue Fassung des Update-Skripts" \
    "sed -i '/^stufe2() {\$/a\\    echo \"    $PRUEFZEILE\"' deploy/update-debian.sh; grep -qF '$PRUEFZEILE' deploy/update-debian.sh")"
INDEX_VORHER="$(stat -c %Y "$ZIEL/.git/index")"
update_lauf --pruefen
pruefe "--pruefen endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "--pruefen: Stand unverändert" test "$(kopf)" = "$ALT"
pruefe "--pruefen: nur vorwärts (Linear: ja)" enthaelt "$LAUF" "Linear:      ja"
pruefe "--pruefen: .git/index unverändert" test "$(stat -c %Y "$ZIEL/.git/index")" = "$INDEX_VORHER"
update_lauf
pruefe "Update endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "auf dem neuen Stand" test "$(kopf)" = "$NEU"
pruefe "die neue Fassung übernimmt" enthaelt "$LAUF" "Die neue Fassung des Update-Skripts übernimmt."
pruefe "Prüfzeile der neuen Fassung genau einmal" test "$(grep -cF "$PRUEFZEILE" "$LAUF")" = 1
pruefe "Ergebnis eingespielt" enthaelt "$LAUF" "==> Ergebnis: eingespielt"
pruefe "Verlauf: eingespielt" bash -c "tail -n 1 /var/lib/powermanagement/update/verlauf | grep -q ' aktualisieren eingespielt'"
pruefe "Kopie der alten Fassung aufgeräumt" test ! -e /var/lib/powermanagement/update/alt/update-debian.sh
pruefe "kein Schnappschuss übrig" leer "$(find "$ZIEL/storage/backups" -maxdepth 1 -name '.schnappschuss-*' -print)"
pruefe_installation
abschluss "Selbst-Update"

# ------------------------------------------------------------ 5a
schritt "5a · Kaputte Migration: vollständig zurück"
ALT="$(kopf)"
SUMMEN="$(pruefsummen)"
NEU="$(neuer_stand "Kaputte Migration" "printf 'SELEKT kaputt;\n' > database/migrations/099_pruefung_kaputt.sql")"
update_lauf
pruefe "Update endet mit 2 – zurückgerollt (kam: $RC)" test "$RC" = 2
pruefe "Ergebnis zurueckgerollt (migration)" enthaelt "$LAUF" "==> Ergebnis: zurueckgerollt (migration)"
pruefe "Stand wie vorher" test "$(kopf)" = "$ALT"
pruefe "Zweig bleibt main" test "$(zweig)" = main
pruefe "Einheiten, Vorlagen und Schema wie vorher" test "$(pruefsummen)" = "$SUMMEN"
[[ "$(pruefsummen)" == "$SUMMEN" ]] || diff <(printf '%s\n' "$SUMMEN") <(pruefsummen) | sed 's/^/            /'
pruefe "der Stand gilt als gescheitert" grep -q "^$NEU [0-9]* migration\$" "$GESCHEITERT"
pruefe_installation
abschluss "kaputte Migration"
NEU="$(neuer_stand "Kaputte Migration entfernt" "git rm -q database/migrations/099_pruefung_kaputt.sql")"
update_lauf
pruefe "danach: Update endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "danach: auf dem neuen Stand" test "$(kopf)" = "$NEU"
abschluss "kaputte Migration"

# ------------------------------------------------------------ 5b
schritt "5b · Prüfung scheitert nach Abgleich und Neustart: auch Einheiten und Vorlagen zurück"
ALT="$(kopf)"
SUMMEN="$(pruefsummen)"
# Eine Migration, die gelingt; geänderte Vorlagen (Logrotate, Pool, Site);
# eine neue Einheit; und eine Anmeldeseite, die erst mit dem neuen Code
# (nach dem Neuladen von PHP-FPM) mit 500 antwortet.
NEU="$(neuer_stand "Anmeldeseite kaputt" '
    printf "CREATE TABLE pruefung_update (id INTEGER);\n" > database/migrations/099_pruefung_tabelle.sql
    printf "# Prüfung\n" >> deploy/powermanagement.logrotate
    printf "; Prüfung\n" >> deploy/php-fpm-pool.conf
    printf "# Prüfung\n" >> deploy/nginx-powermanagement.conf
    printf "[Unit]\nDescription=Prüfung\n\n[Timer]\nOnCalendar=yearly\nUnit=powermanagement-wartung.service\n" \
        > deploy/powermanagement-pruefung.timer
    sed -i "/^        powermanagement-wartung.timer\$/a\\        powermanagement-pruefung.timer" deploy/update-debian.sh
    grep -q "powermanagement-pruefung.timer" deploy/update-debian.sh
    sed -i "/^declare(strict_types=1);\$/a if ((\$_SERVER[\"REQUEST_URI\"] ?? \"\") === \"/login\") { http_response_code(500); exit; }" public/index.php
    grep -q "http_response_code(500)" public/index.php')"
update_lauf
pruefe "Update endet mit 2 – zurückgerollt (kam: $RC)" test "$RC" = 2
pruefe "Ergebnis zurueckgerollt (pruefung)" enthaelt "$LAUF" "==> Ergebnis: zurueckgerollt (pruefung)"
pruefe "Anmeldeseite scheiterte erst mit dem neuen Code" enthaelt "$LAUF" "antwortet mit HTTP 500 statt 200"
pruefe "Abgleich mit der alten Fassung" enthaelt "$LAUF" "Abgleich mit der alten Fassung:"
pruefe "Stand wie vorher" test "$(kopf)" = "$ALT"
pruefe "Zweig bleibt main" test "$(zweig)" = main
pruefe "Einheiten, Logrotate, Pool, Site samt Merker und Schema wie vorher" test "$(pruefsummen)" = "$SUMMEN"
[[ "$(pruefsummen)" == "$SUMMEN" ]] || diff <(printf '%s\n' "$SUMMEN") <(pruefsummen) | sed 's/^/            /'
pruefe "neue Einheit entfernt" test ! -e /etc/systemd/system/powermanagement-pruefung.timer
pruefe "neue Einheit nicht aktiv" bash -c '! systemctl is-active --quiet powermanagement-pruefung.timer'
pruefe "der Stand gilt als gescheitert" grep -q "^$NEU [0-9]* pruefung\$" "$GESCHEITERT"
pruefe_installation
abschluss "Prüfung scheitert"
NEU="$(neuer_stand "Anmeldeseite wieder heil" "sed -i '/http_response_code(500)/d' public/index.php")"
update_lauf
pruefe "danach: Update endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "danach: auf dem neuen Stand" test "$(kopf)" = "$NEU"
pruefe "danach: Site ersetzt, nicht „von Hand verändert“" \
    bash -c 'grep -qF "nginx-Site aktualisiert" "$1" && ! grep -qF "von Hand verändert" "$1"' _ "$LAUF"
pruefe "danach: Merker passt zur Site" \
    cmp -s /etc/nginx/sites-available/powermanagement /var/lib/powermanagement/vorlagen/_etc_nginx_sites-available_powermanagement
pruefe "danach: neue Einheit da" test -f /etc/systemd/system/powermanagement-pruefung.timer
pruefe_installation
abschluss "Prüfung scheitert"

# ------------------------------------------------------------ 6
schritt "6 · Abbruch (systemctl stop) und Absturz (SIGKILL) mitten im Update"
ALT="$(kopf)"
GESCHEITERT_VORHER="$(cat "$GESCHEITERT" 2>/dev/null || true)"
# Stufe 2 wartet nach dem Abgleich 30 s – Zeit, um dazwischenzugehen.
NEU="$(neuer_stand "Langsames Update" \
    "sed -i '/^stufe2() {\$/,/^}\$/ s/^    schritt 9 \"Dienste starten\" dienste\$/    sleep 30\n&/' deploy/update-debian.sh; grep -q '^    sleep 30\$' deploy/update-debian.sh")"
FORTSCHRITT=/run/pm-pruefung/fortschritt
ERGEBNIS=/run/pm-pruefung/ergebnis
install -d -m 700 /run/pm-pruefung
rm -f /run/pm-pruefung/fortschritt /run/pm-pruefung/ergebnis
# Wie §19.2 der Anleitung: eigene Einheit, KillMode=mixed – systemctl stop
# schickt TERM nur an das Skript; es beendet den laufenden Schritt und rollt zurück.
systemd-run --quiet --collect --unit=pm-pruefung-abbruch -p KillMode=mixed -p TimeoutStopSec=20min \
    "$ZIEL/deploy/update-debian.sh" --fortschritt-datei="$FORTSCHRITT" --ergebnis-datei="$ERGEBNIS"
pruefe "Fortschritt erreicht den Abgleich" fortschritt_abwarten "$FORTSCHRITT" 8
pruefe "Schreibsperre der Oberfläche steht" test -e "$WARTUNG"
update_lauf
pruefe "ein zweiter Lauf endet sofort mit 1 (kam: $RC)" test "$RC" = 1
pruefe "ein zweiter Lauf meldet „Ein anderes Update läuft gerade.“" enthaelt "$LAUF" "Ein anderes Update läuft gerade."
systemctl stop pm-pruefung-abbruch
journalctl --sync 2>/dev/null || true
journalctl -a -u pm-pruefung-abbruch --no-pager -o cat > "$LAUF" 2>&1 || true
sed 's/^/  | /' "$LAUF"
pruefe "Ergebnis zurueckgerollt (abgebrochen)" \
    bash -c 'grep -qx "ergebnis zurueckgerollt" "$1" && grep -qx "grund abgebrochen" "$1"' _ "$ERGEBNIS"
pruefe "Stand wie vorher" test "$(kopf)" = "$ALT"
pruefe "Zweig bleibt main" test "$(zweig)" = main
pruefe "ein Abbruch zählt nicht als gescheitert" test "$(cat "$GESCHEITERT" 2>/dev/null || true)" = "$GESCHEITERT_VORHER"
pruefe_installation
abschluss "Abbruch"

rm -f /run/pm-pruefung/fortschritt /run/pm-pruefung/ergebnis
systemd-run --quiet --collect --unit=pm-pruefung-absturz -p KillMode=mixed -p TimeoutStopSec=20min \
    "$ZIEL/deploy/update-debian.sh" --fortschritt-datei="$FORTSCHRITT"
pruefe "Fortschritt erreicht den Abgleich" fortschritt_abwarten "$FORTSCHRITT" 8
systemctl kill -s KILL pm-pruefung-absturz
pruefe "Einheit nach SIGKILL beendet" einheit_abwarten pm-pruefung-absturz
pruefe "Marke „offen“ bleibt" test -e "$MARKE_OFFEN"
pruefe "Schreibsperre bleibt" test -e "$WARTUNG"
update_lauf
pruefe "ein neues Update lehnt ab (kam: $RC)" test "$RC" = 1
pruefe "ein neues Update nennt --wiederaufnehmen" enthaelt "$LAUF" "--wiederaufnehmen"
update_lauf --wiederaufnehmen
pruefe "--wiederaufnehmen endet mit 2 – zurückgerollt (kam: $RC)" test "$RC" = 2
pruefe "Ergebnis zurueckgerollt (unterbrochen)" enthaelt "$LAUF" "==> Ergebnis: zurueckgerollt (unterbrochen)"
pruefe "Stand wie vorher" test "$(kopf)" = "$ALT"
pruefe "Zweig bleibt main" test "$(zweig)" = main
pruefe "ein Absturz zählt nicht als gescheitert" test "$(cat "$GESCHEITERT" 2>/dev/null || true)" = "$GESCHEITERT_VORHER"
pruefe_installation
abschluss "Absturz"

NEU="$(neuer_stand "Wieder schnell" "sed -i '/^    sleep 30\$/d' deploy/update-debian.sh")"
update_lauf
pruefe "danach: Update endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "danach: auf dem neuen Stand" test "$(kopf)" = "$NEU"
pruefe_installation
abschluss "Absturz"

echo
echo "Installation, zweiter Lauf, Updates (ohne HOME, Selbst-Update), Zurückrollen, Abbruch"
echo "und Absturz in Ordnung."
