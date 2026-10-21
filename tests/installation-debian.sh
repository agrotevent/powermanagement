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
#   2. derselbe Aufruf noch einmal: app.key, Kennwörter und Administrator
#      bleiben, alles läuft weiter
#   3. Update auf einen neuen Stand, der einen Ordner in storage/ mitbringt,
#      danach ein Update ohne Neues
#
# Bei jedem Schritt dazu die Grenzen für Grundrisse bis 100 MB, wie nginx
# und PHP-FPM sie wirklich anwenden; vor dem Update stehen Pool und Site auf
# den alten Werten (64M), danach wird über beide hochgeladen.
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

    pruefe_grenzen
}

# Grundrisse bis 100 MB (SiteService::MAX_UPLOAD_BYTES): was PHP-FPM aus dem
# Pool liest und was nginx je Adresse annimmt. Mit „hochladen“ zusätzlich
# über beide hinweg, angemeldet: knapp 100 MiB angenommen, ein Byte mehr mit
# der Grenze abgelehnt.
pruefe_grenzen() {
    local php_v fpm_tt code seite jar token benutzer kennwort site antwort
    php_v="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
    fpm_tt="$("php-fpm$php_v" -tt 2>&1 || true)"
    pruefe "PHP-FPM liest upload_max_filesize = 100M" grep -q 'php_admin_value\[upload_max_filesize\] = 100M' <<<"$fpm_tt"
    pruefe "PHP-FPM liest post_max_size = 105M" grep -q 'php_admin_value\[post_max_size\] = 105M' <<<"$fpm_tt"

    # Angekündigte Größe über der Grenze: nginx antwortet, bevor ein Byte kommt.
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H 'Host: powermanagement.lan' -H 'Expect:' \
        -H "Content-Length: $((17 * 1024 * 1024))" -X POST http://127.0.0.1/login 2>/dev/null)" || true
    pruefe "nginx: 17 MiB an /login → 413 (kam: ${code:-000})" test "${code:-000}" = 413
    seite="$(curl -s --max-time 10 -H 'Host: powermanagement.lan' -H 'Expect:' \
        -H "Content-Length: $((106 * 1024 * 1024))" -X POST http://127.0.0.1/standorte/grundriss 2>/dev/null)" || true
    pruefe "nginx: 106 MiB an /standorte/grundriss → eigene Seite „bis 100 MB“" grep -q 'Grundrisse lassen sich bis 100 MB hochladen' <<<"$seite"
    code="$(head -c $((100 * 1024 * 1024)) /dev/zero | curl -s -o /dev/null -w '%{http_code}' --max-time 60 -H 'Host: powermanagement.lan' \
        -H 'Expect:' -H 'Content-Type: application/octet-stream' --data-binary @- http://127.0.0.1/standorte/grundriss 2>/dev/null)" || true
    pruefe "nginx und PHP: 100 MiB an /standorte/grundriss angenommen (ohne Anmeldung → 302, kam: ${code:-000})" test "${code:-000}" = 302

    [[ "${1:-}" == "hochladen" ]] || return 0
    benutzer="pruefung-upload-$$"
    kennwort="Pruefung-$(openssl rand -hex 8)"
    site="$(cd "$ZIEL" && runuser -u www-data -- php -r '
        require "src/bootstrap.php";
        App\Services\BenutzerService::anlegen($argv[1], $argv[2], "admin", false);
        echo App\Services\SiteService::saveSite(null, ["name" => $argv[1]]);' -- "$benutzer" "$kennwort")"
    php -r 'require "/src/tests/hilfen/grundriss-dateien.php";
        pruefPdf("/root/plan-knapp.pdf", 1, 100 * 1024 * 1024 - 2048);
        pruefPdf("/root/plan-ueber.pdf", 1, 100 * 1024 * 1024 + 1);'
    jar="$(mktemp)"
    seite="$(curl -s --max-time 10 -c "$jar" -b "$jar" -H 'Host: powermanagement.lan' http://127.0.0.1/login)" || true
    token="$(printf '%s' "$seite" | sed -n 's/.*name="_token" value="\([0-9a-f]*\)".*/\1/p' | head -1)"
    curl -s -o /dev/null --max-time 10 -c "$jar" -b "$jar" -H 'Host: powermanagement.lan' --data-urlencode "_token=$token" \
        --data-urlencode "username=$benutzer" --data-urlencode "password=$kennwort" http://127.0.0.1/login || true
    seite="$(curl -s --max-time 10 -c "$jar" -b "$jar" -H 'Host: powermanagement.lan' http://127.0.0.1/standorte)" || true
    pruefe "Formular nennt 100 MB (data-max-bytes)" grep -q 'data-max-bytes="104857600"' <<<"$seite"
    token="$(printf '%s' "$seite" | sed -n 's/.*name="_token" value="\([0-9a-f]*\)".*/\1/p' | head -1)"
    for datei in knapp ueber; do
        curl -s -o /dev/null --max-time 300 -c "$jar" -b "$jar" -H 'Host: powermanagement.lan' -H 'Expect:' \
            -F "_token=$token" -F "site_id=$site" -F neue_etagen=1 -F "plan=@/root/plan-$datei.pdf;type=application/pdf" \
            http://127.0.0.1/standorte/grundriss || true
        antwort="$(curl -s --max-time 10 -c "$jar" -b "$jar" -H 'Host: powermanagement.lan' http://127.0.0.1/standorte)" || true
        if [[ "$datei" == knapp ]]; then
            pruefe "knapp 100 MiB über nginx und PHP-FPM hochgeladen" grep -q 'Grundriss hinterlegt' <<<"$antwort"
        else
            pruefe "100 MiB + 1 Byte: abgelehnt mit der Grenze von PHP" grep -q 'größer, als der Server annimmt (höchstens 100 MB)' <<<"$antwort"
        fi
    done
    rm -f "$jar" /root/plan-knapp.pdf /root/plan-ueber.pdf
    (cd "$ZIEL" && runuser -u www-data -- php -r '
        require "src/bootstrap.php";
        App\Services\SiteService::deleteSite((int) $argv[2]);
        App\Services\BenutzerService::loeschen((int) App\Support\Database::scalar("SELECT id FROM users WHERE username = ?", [$argv[1]]));' \
        -- "$benutzer" "$site") || true
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
schritt "2 · Derselbe Aufruf noch einmal"
KONFIG_VORHER="$(sha256sum "$ZIEL/config/config.php")"
ZUGAENGE_VORHER="$(grep -E '^[A-Z0-9_]+=' "$ZUGAENGE" | sort)"
if ! "$ZIEL/deploy/install-debian.sh" --name="Prüfung Installation" > "$LAUF" 2>&1; then
    cat "$LAUF"
    fehler "deploy/install-debian.sh bricht beim zweiten Lauf ab"
    abschluss "zweiter Lauf"
fi
sed 's/^/  | /' "$LAUF"
ok "deploy/install-debian.sh läuft auch ein zweites Mal durch"
pruefe "config/config.php (app.key) unverändert" test "$(sha256sum "$ZIEL/config/config.php")" = "$KONFIG_VORHER"
pruefe "Kennwörter und Tokens unverändert" test "$(grep -E '^[A-Z0-9_]+=' "$ZUGAENGE" | sort)" = "$ZUGAENGE_VORHER"
pruefe "Administrator nicht neu angelegt" grep -q 'Administrator bestand schon' "$LAUF"
pruefe_installation
abschluss "zweiter Lauf"

# ------------------------------------------------------------ 3
schritt "3 · Update auf einen neuen Stand mit neuem Ordner in storage/"
# Wie eine Installation von vor den 100 MB: Pool und Site mit den alten
# Grenzen, die Site so gemerkt, als hätte das Skript sie damals geliefert –
# das Update muss beides auf die neuen Werte bringen.
POOL_DATEI="$(find /etc/php -path '*/fpm/pool.d/powermanagement.conf' | head -1)"
SITE_DATEI=/etc/nginx/sites-available/powermanagement
sed -i -e 's/upload_max_filesize\] = 100M/upload_max_filesize] = 64M/' -e 's/post_max_size\] = 105M/post_max_size] = 66M/' "$POOL_DATEI"
sed -i 's/client_max_body_size 105m;/client_max_body_size 64m;/' "$SITE_DATEI"
cp "$SITE_DATEI" "/var/lib/powermanagement/vorlagen/$(printf '%s' "$SITE_DATEI" | tr '/' '_')"
systemctl restart "$(systemctl list-unit-files 'php*-fpm.service' --no-legend | awk '{print $1}' | sort -V | tail -1)"
systemctl reload nginx
pruefe "vorher: Pool mit den alten Grenzen" grep -q 'upload_max_filesize\] = 64M' "$POOL_DATEI"
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
pruefe "Update: nginx-Site wieder mit 105m für Grundrisse" grep -q 'client_max_body_size 105m;' "$SITE_DATEI"
pruefe_installation
pruefe_grenzen hochladen

if ! "$ZIEL/deploy/update-debian.sh" > "$LAUF" 2>&1; then
    cat "$LAUF"
    fehler "deploy/update-debian.sh ohne Neues bricht ab"
else
    pruefe "Update ohne Neues meldet den Stand" grep -q 'Bereits auf dem neuesten Stand' "$LAUF"
fi
abschluss "Update"

echo
echo "Installation, zweiter Lauf und Update in Ordnung."
