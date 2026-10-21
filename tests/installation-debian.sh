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
#      bleiben, Titel und Einstellungen aus der Oberfläche auch, der Zugang
#      der Anwendung zum Broker passt wieder zur Kennwortdatei, alles läuft weiter
#   2b. eine Datenbank ohne Benutzer (wie nach einem abgebrochenen ersten
#      Lauf): derselbe Aufruf legt den Administrator an, mit neuem Erstkennwort
#   3. Update auf einen neuen Stand, der einen Ordner in storage/ mitbringt,
#      danach ein Update ohne Neues; fehlende Kacheln (samt dem Rest eines
#      getöteten Baus) rechnet danach powermanagement-kacheln als www-data
#   3b. Update ohne HOME und ohne Terminal (systemd-run, wie ein Dienst):
#      läuft durch, Ausgabe ohne Farben, kein globales safe.directory
#   3c. Node-RED-Flows: neue Flows ersetzen unveränderte (wie die
#      Analogeingänge der Tanksensoren); im Editor veränderte bleiben, die neue
#      Fassung liegt daneben, Hinweis und Zusammenfassung sagen es;
#      --flows-ersetzen sichert die eigene Fassung und spielt die neue ein
#   3d. von Hand veränderte nginx-Site ohne den Block für Grundrisse: bleibt,
#      das Update nennt den fehlenden Block, die neue Fassung liegt daneben
#   4. Update über die Oberfläche (System → Update: anmelden, suchen,
#      aktualisieren, Status abfragen über Neustarts hinweg) mit Selbst-Update:
#      die neue Fassung des Update-Skripts übernimmt im selben Lauf (Stufe 2);
#      --pruefen ändert nichts; Audit, Protokoll ohne Farben und Zugangsdaten
#   5a. kaputte Migration (bin/power update:starten --warten): vollständig
#      zurück (rc 2) – Code, Zweig, Schema, Einheiten und Vorlagen wie vorher,
#      der Stand gilt als gescheitert
#   5b. Prüfung scheitert erst nach Abgleich und Neustart (Anmeldeseite 500,
#      update:starten --warten): auch neue Einheit, Logrotate, Pool, Site,
#      Node-RED-Flows samt Merker kommen zurück
#   6. Abbruch (systemctl stop) und Absturz (SIGKILL) mitten in einem Update
#      aus der Oberfläche: Zurückrollen bzw. Nachlauf und Wiederanlauf über
#      den Update-Dienst; ein zweiter Lauf wartet nicht, sondern meldet „besetzt“
#   7. Automatik (Zeitplan als App-Benutzer): im Zeitfenster von selbst
#      eingespielt, höchstens einmal je Tag; während eines Aufbaus verschoben
#      (einmal ins Audit), danach eingespielt
#   8. Update-Dienst: Aufträge als App-Benutzer nach storage/update/soll
#      (Suchen, Aktualisieren mit Stufe 2 und Protokoll ins Journal), dann
#      untergeschobene Aufträge (Müll, Verweis auf /etc/shadow, FIFO, Ordner,
#      update.conf, soll/ als Verweis) – abgelehnt, nichts als root angefasst
#
# Mit --umstieg=<commit> stattdessen der einmalige Umstieg von einer Fassung
# ohne Update-Funktion (Job „Umstieg“): U1 Erstinstallation dieses Stands,
# U2 genau der Befehl aus der Anleitung (zwei Läufe in einer Einheit) –
# danach ist die Update-Funktion eingerichtet, die Flows sind neu und alle
# Migrationen (auch 016 Kundenbereich und 018 Tanks) eingespielt; U3 ein Update über die
# Oberfläche.
#
# Bei jedem Schritt dazu die Grenzen für Grundrisse bis 100 MB, wie nginx
# und PHP-FPM sie wirklich anwenden – mit angekündigter Länge und in Stücken
# (chunked), also auch nach jedem Update und jedem Zurückrollen (5a, 5b, 6:
# Pool und Site samt Merker wie vorher); vor dem Update in 3 stehen Pool und
# Site auf den alten Werten (64M), danach wird über beide hochgeladen. Im
# Umstieg ab U2 – U1 ist ein Stand von vor den 100 MB.
#
# Aufruf:  tests/installation-debian.sh
#          tests/installation-debian.sh --umstieg=7586d1d
#          PM_BEHALTEN=1 tests/installation-debian.sh   Container bleibt stehen
#          PM_BASIS=<bild> tests/installation-debian.sh anderes Debian-13-Bild
#
# Braucht Docker und Netz (Debian-Spiegel, repos.influxdata.com, npm). Läuft
# in der CI (Jobs „Installation“ und „Umstieg“); auf dem eigenen Rechner nur
# mit Docker.

set -euo pipefail

if [[ "${1:-}" != "--im-container" ]]; then
    # ======================================================== außerhalb
    INNEN_ARGS=()
    for arg in "$@"; do
        case "$arg" in
            --umstieg=?*) INNEN_ARGS+=("$arg") ;;
            *) echo "Unbekannte Option: $arg" >&2; exit 1 ;;
        esac
    done
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
                         powermanagement-mqtt-zugaenge powermanagement-wartung powermanagement-update \
                         powermanagement-update-zeitplan pm-umstieg; do
                    echo "--- journalctl -u $u"; journalctl -a -u "$u" --no-pager -n 30 2>/dev/null
                done
                juengstes="$(ls -1t /var/log/powermanagement/update/*.log 2>/dev/null | head -n 1)"
                for f in /var/log/nginx/powermanagement.error.log /opt/powermanagement/storage/logs/*.log \
                         /var/lib/powermanagement/update/offen /var/lib/powermanagement/update/verlauf \
                         /opt/powermanagement/storage/update/ist/lauf /opt/powermanagement/storage/update/ist/helfer \
                         ${juengstes:+"$juengstes"}; do
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
    docker exec "$NAME" bash /src/tests/installation-debian.sh --im-container ${INNEN_ARGS[@]+"${INNEN_ARGS[@]}"}
    exit 0
fi

# ============================================================ im Container
shift
UMSTIEG=""
for arg in "$@"; do
    case "$arg" in
        --umstieg=?*) UMSTIEG="${arg#*=}" ;;
    esac
done
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
SOLL="$ZIEL/storage/update/soll"
IST="$ZIEL/storage/update/ist"
# Die Einheiten des Update-Dienstes (eingeschaltet: Pfad und Zeitgeber) und des Zeitplans
UPDATE_EINHEITEN=(powermanagement-update.service powermanagement-update.path powermanagement-update.timer
                  powermanagement-update-zeitplan.service powermanagement-update-zeitplan.timer)
WARTUNG="$ZIEL/storage/update/ist/wartung"
# Node-RED-Flows der Installation und die neue Fassung daneben (behaltene Flows)
NR_FLOWS=/var/lib/powermanagement/node-red/flows.json
NR_NEU=/var/lib/powermanagement/node-red/flows.powermanagement-neu.json
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
# Pool, Site, Mosquitto, Node-RED-Flows, die gemerkten Vorlagen, das Schema,
# der Zweig.
pruefsummen() {
    sha256sum /etc/systemd/system/powermanagement-* /etc/logrotate.d/powermanagement \
        /etc/php/*/fpm/pool.d/powermanagement.conf /etc/nginx/sites-available/powermanagement \
        /etc/mosquitto/powermanagement.acl /etc/mosquitto/conf.d/powermanagement.conf \
        "$NR_FLOWS" /var/lib/powermanagement/vorlagen/* 2>&1
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
# Update-Dienst: Aufträge so ablegen wie die Anwendung (als www-data,
# Temp-Datei außerhalb von soll/, dann umbenennen) und Rückmeldungen lesen.
als_www() { runuser -u www-data -- "$@"; }
# auftrag_legen <aktion> [<ziel>]: setzt AUFTRAG_KENNUNG
auftrag_legen() {
    AUFTRAG_KENNUNG="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
    {
        printf '# powermanagement update-auftrag 1\nkennung %s\naktion %s\nausloeser konsole\nvon pruefung\nzeit %s\n' \
            "$AUFTRAG_KENNUNG" "$1" "$(date +%s)"
        [[ -z "${2:-}" ]] || printf 'ziel %s\n' "$2"
    } | auftrag_roh
}
# auftrag_roh: Inhalt von stdin als Auftrag
auftrag_roh() {
    als_www bash -c 't="$(mktemp "$1/../.auftrag-XXXXXX")" && cat > "$t" && chmod 640 "$t" && mv -f "$t" "$1/auftrag"' _ "$SOLL"
}
ist_wert() { sed -n "s/^$2 //p" "$IST/$1" 2>/dev/null | head -n 1 || true; }
# lauf_abwarten <kennung> <sek>: bis ist/lauf diesen Auftrag fertig meldet
lauf_abwarten() {
    for _ in $(seq 1 "$2"); do
        if [[ "$(ist_wert lauf kennung)" == "$1" && "$(ist_wert lauf zustand)" =~ ^(fertig|fehler)$ ]] \
            && [[ "$(systemctl is-active powermanagement-update.service 2>/dev/null || true)" != "activating" ]]; then
            return 0
        fi
        sleep 1
    done
    return 1
}
# helfer_abwarten: bis kein Auftrag mehr liegt und der Dienst ruht (≤ 60 s)
helfer_abwarten() {
    for _ in $(seq 1 60); do
        if [[ ! -e "$SOLL/auftrag" && ! -L "$SOLL/auftrag" ]] \
            && [[ "$(systemctl is-active powermanagement-update.service 2>/dev/null || true)" != "activating" ]]; then
            return 0
        fi
        sleep 1
    done
    return 1
}
# warte_auf <sek> <befehl…>: bis der Befehl gelingt
warte_auf() {
    local n="$1"
    shift
    for _ in $(seq 1 "$n"); do
        "$@" && return 0
        sleep 1
    done
    "$@"
}

# ------------------------------------------------------------ Oberfläche und update:starten
# Ein eigenes Administrationskonto (nicht der Erst-Administrator – dessen
# Erstkennwort prüft pruefe_installation) und eine Sitzung wie im Browser,
# über nginx und PHP-FPM.
ADMIN_NAME=pruefung-update
ADMIN_KW="Pruefung-Update-$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
JAR=/root/pm-pruefung.cookies
ANTWORT=/root/pm-pruefung.antwort
LAUF_VORHER=""
ERGEBNIS_UI=""
admin_anlegen() {
    (cd "$ZIEL" && PM_KW="$ADMIN_KW" PM_NAME="$ADMIN_NAME" runuser -u www-data -- php -r '
        require "src/bootstrap.php";
        if ((int) \App\Support\Database::scalar("SELECT COUNT(*) FROM users WHERE username = ?", [getenv("PM_NAME")]) === 0) {
            \App\Services\BenutzerService::anlegen(getenv("PM_NAME"), getenv("PM_KW"), "admin", false);
        }')
}
# audit_zahl <aktion> <LIKE auf details>: Einträge im Audit (als App-Benutzer gelesen)
audit_zahl() {
    (cd "$ZIEL" && PM_A="$1" PM_L="$2" runuser -u www-data -- php -r 'require "src/bootstrap.php";
        echo (int) \App\Support\Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = ? AND details LIKE ?", [getenv("PM_A"), getenv("PM_L")]);')
}
# http_get <pfad> / http_post <pfad> <k=v …>: Antwort nach $ANTWORT, Status in
# HTTP_CODE, Weiterleitung in HTTP_ZIEL
http_get() {
    local w
    w="$(curl -s --max-time 30 -o "$ANTWORT" -w '%{http_code} %{redirect_url}' -c "$JAR" -b "$JAR" \
        -H 'Host: powermanagement.lan' "http://127.0.0.1$1" 2>/dev/null)" || w="000 "
    HTTP_CODE="${w%% *}"
    HTTP_ZIEL="${w#* }"
}
http_post() {
    local pfad="$1" w kv args=()
    shift
    for kv in "$@"; do args+=(--data-urlencode "$kv"); done
    w="$(curl -s --max-time 30 -o "$ANTWORT" -w '%{http_code} %{redirect_url}' -c "$JAR" -b "$JAR" \
        -H 'Host: powermanagement.lan' "${args[@]}" "http://127.0.0.1$pfad" 2>/dev/null)" || w="000 "
    HTTP_CODE="${w%% *}"
    HTTP_ZIEL="${w#* }"
}
seiten_token() { sed -n 's/.*name="_token" value="\([0-9a-f]*\)".*/\1/p' "$ANTWORT" | head -n 1; }
anmelden() {
    rm -f "$JAR"
    http_get /login
    http_post /login "_token=$(seiten_token)" "username=$ADMIN_NAME" "password=$ADMIN_KW"
    [[ "$HTTP_CODE" == 302 && "$HTTP_ZIEL" != *"/login"* ]]
}
# ueber_oberflaeche <commit>: wie ein Betreiber – „Jetzt suchen“, warten, bis
# die Seite genau diesen Stand anbietet, dann „Jetzt aktualisieren“
ueber_oberflaeche() {
    http_get /system/update
    [[ "$HTTP_CODE" == 200 ]] || { echo "            /system/update: HTTP $HTTP_CODE"; return 1; }
    http_post /system/update/suchen "_token=$(seiten_token)"
    [[ "$HTTP_CODE" == 302 ]] || { echo "            Suchen: HTTP $HTTP_CODE"; return 1; }
    warte_auf 180 grep -qE "^verfuegbar $1 [0-9]+\$" "$IST/stand" || { echo "            ist/stand nennt $1 nicht"; return 1; }
    helfer_abwarten || return 1
    http_get /system/update
    grep -qF "name=\"ziel\" value=\"$1\"" "$ANTWORT" || { echo "            die Seite bietet $1 nicht an"; return 1; }
    http_post /system/update/starten "_token=$(seiten_token)" "ziel=$1"
    [[ "$HTTP_CODE" == 302 && "$HTTP_ZIEL" == *"#fortschritt" ]] || { echo "            Starten: HTTP $HTTP_CODE $HTTP_ZIEL"; return 1; }
}
# oberflaeche_abwarten <sek>: /system/update/stand abfragen wie update.js –
# 000/502/503/504 (Dienste starten neu) sind erlaubt, am Ende kommt 200 JSON –,
# bis ein Lauf mit anderer Kennung als $LAUF_VORHER (keine Suche) zu Ende ist.
# ERGEBNIS_UI = "<aktion> <ergebnis> <grund>"
oberflaeche_abwarten() {
    local code werte a k ak e g
    ERGEBNIS_UI=""
    for _ in $(seq 1 "$1"); do
        code="$(curl -s --max-time 10 -o "$ANTWORT" -w '%{http_code}' -c "$JAR" -b "$JAR" -H 'Host: powermanagement.lan' \
            -H 'Accept: application/json' http://127.0.0.1/system/update/stand 2>/dev/null)" || code=000
        case "$code" in
            200)
                werte="$(php -r '$j = json_decode((string) file_get_contents($argv[1]), true);
                    if (!is_array($j)) { exit(1); }
                    $l = is_array($j["lauf"] ?? null) ? $j["lauf"] : [];
                    echo empty($j["aktiv"]) ? "ruhe" : "aktiv", " ", $l["kennung"] ?? "-", " ", $l["aktion"] ?? "-", " ",
                        $l["ergebnis"] ?? "-", " ", $l["grund"] ?? "-";' "$ANTWORT" 2>/dev/null)" || werte=""
                read -r a k ak e g <<< "$werte"
                if [[ "$a" == ruhe && -n "$k" && "$k" != "-" && "$k" != "$LAUF_VORHER" && "$ak" != pruefen ]]; then
                    ERGEBNIS_UI="$ak $e $g"
                    return 0
                fi ;;
            000|502|503|504) ;;
            *) echo "            /system/update/stand: HTTP $code (abgemeldet?)"; return 1 ;;
        esac
        sleep 2
    done
    return 1
}
# ist_schritt_abwarten <n>: bis ein neuer Lauf (Kennung ≠ $LAUF_VORHER) Schritt n erreicht (≤ 300 s)
ist_schritt_abwarten() {
    local n
    for _ in $(seq 1 300); do
        n="$(ist_wert lauf schritt)"
        n="${n%% *}"
        if [[ "$(ist_wert lauf kennung)" != "$LAUF_VORHER" && "$(ist_wert lauf zustand)" == laeuft \
              && "${n:-0}" =~ ^[0-9]+$ && "${n:-0}" -ge "$1" ]]; then
            return 0
        fi
        sleep 1
    done
    return 1
}
# update_starten: bin/power update:starten --warten als App-Benutzer, Ausgabe nach $LAUF, rc in RC
update_starten() {
    RC=0
    (cd "$ZIEL" && runuser -u www-data -- php bin/power update:starten --warten) > "$LAUF" 2>&1 < /dev/null || RC=$?
    sed 's/^/  | /' "$LAUF"
}
# abstand_abwarten [konsole]: der Update-Dienst nimmt ein Aktualisieren erst
# 120 s nach dem letzten an („zu_oft“) und holt bei einer Suche erst 60 s nach
# der letzten neu (sonst Stand aus den Refs, ohne Netz – auch das Ende jedes
# Updates zählt als Suche); update:starten sucht nur, wenn der letzte Abruf
# älter als 60 s ist – mit „konsole“ auch darauf warten.
abstand_abwarten() {
    local bis=0 t jetzt
    # Vor dem ersten Aktualisieren über den Dienst gibt es zeiten noch nicht –
    # sed endet dann mit 2, und set -o pipefail bräche das ganze Skript ab.
    t="$(sed -n 's/^aktualisieren \([0-9][0-9]*\).*/\1/p' /var/lib/powermanagement/update/zeiten 2>/dev/null | head -n 1 || true)"
    [[ -n "$t" ]] && bis=$((t + 125))
    t="$(sed -n 's/^pruefen \([0-9][0-9]*\).*/\1/p' /var/lib/powermanagement/update/zeiten 2>/dev/null | head -n 1 || true)"
    [[ -n "$t" ]] && (( t + 65 > bis )) && bis=$((t + 65))
    if [[ "${1:-}" == konsole ]]; then
        t="$(stat -c %Y "$ZIEL/.git/FETCH_HEAD" 2>/dev/null || echo 0)"
        (( t + 65 > bis )) && bis=$((t + 65))
    fi
    jetzt="$(date +%s)"
    if (( bis > jetzt )); then
        echo "    (warte $((bis - jetzt)) s – Abstand zwischen zwei Updates bzw. Suchen)"
        sleep $((bis - jetzt))
    fi
    return 0
}

# ------------------------------------------------------------ Automatik
# power_app <befehl …>: bin/power als Anwendungsbenutzer
power_app() { (cd "$ZIEL" && runuser -u www-data -- php bin/power "$@"); }
# app_php <code>: PHP mit der Anwendung als Anwendungsbenutzer (Werte über die Umgebung)
app_php() { (cd "$ZIEL" && runuser -u www-data -- php -r 'require "src/bootstrap.php"; '"$1"); }
# ruhe_abwarten [sek]: kein Auftrag liegt, der Update-Dienst läuft nicht – dreimal hintereinander
ruhe_abwarten() {
    local ruhig=0
    for _ in $(seq 1 "${1:-900}"); do
        if [[ ! -e "$SOLL/auftrag" && ! -L "$SOLL/auftrag" ]] \
            && [[ "$(systemctl is-active powermanagement-update.service 2>/dev/null || true)" != "activating" ]]; then
            ruhig=$((ruhig + 1))
            (( ruhig >= 3 )) && return 0
        else
            ruhig=0
        fi
        sleep 1
    done
    return 1
}
# zeitplan_bis <commit>: den Zeitplan starten wie sein Zeitgeber (bis zu 3× – erst
# suchen, dann einspielen), dazwischen auf einen ruhenden Update-Dienst warten
zeitplan_bis() {
    local i
    for i in 1 2 3; do
        systemctl start powermanagement-update-zeitplan.service || return 1
        ruhe_abwarten 900 || return 1
        if [[ "$(kopf)" == "$1" ]]; then
            echo "            (Zeitplan-Lauf $i)"
            return 0
        fi
    done
    return 1
}
# auto_letzter: „<ergebnis> <grund>“ aus update.auto_letzter
auto_letzter() {
    app_php '$l = \App\Services\UpdateAutomatik::letzter(); echo $l["ergebnis"] ?? "-", " ", ($l["grund"] ?? "") !== "" ? $l["grund"] : "-";'
}
# aufbau_pruefung <status>: ein Standort mit Aufbau „Prüfung Automatik“ (anlegen bzw. Status setzen)
aufbau_pruefung() {
    PM_STATUS="$1" app_php '$db = "\App\Support\Database"; $n = "Prüfung Automatik";
        $id = (int) $db::scalar("SELECT id FROM aufbauten WHERE name = ?", [$n]);
        if ($id === 0) {
            $site = $db::insert("sites", ["name" => $n, "created_at" => gmdate("c")]);
            $db::insert("aufbauten", ["site_id" => $site, "name" => $n, "status" => getenv("PM_STATUS"), "gestartet_am" => gmdate("c"), "created_at" => gmdate("c")]);
        } else {
            $db::execute("UPDATE aufbauten SET status = ? WHERE id = ?", [getenv("PM_STATUS"), $id]);
        }'
}

# Erst den Stand der Anwendung abwarten, dann prüfen, was ein Betreiber
# nach der Installation vorfinden muss. „ohne_update“: eine Fassung ohne
# Update-Funktion (Umstieg, U1).
pruefe_installation() {
    local einheit fpm code d antwort jar seite token kennwort i ausgabe pruef_ok mit_update="ja"
    [[ "${1:-}" == "ohne_update" ]] && mit_update="nein"

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
        [[ "$mit_update" == "nein" && "$d" == storage/update* ]] && continue
        pruefe "$d: 750" test "$(stat -c %a "$ZIEL/$d" 2>/dev/null)" = 750
    done
    pruefe "config/config.php: root:www-data 640" \
        test "$(stat -c '%U:%G %a' "$ZIEL/config/config.php")" = "root:www-data 640"
    pruefe "nichts für alle beschreibbar" leer "$(find "$ZIEL" ! -type l -perm -o+w -print)"
    pruefe "zugaenge.txt nur für root" test "$(stat -c '%U %a' "$ZUGAENGE")" = "root 600"
    # Zustand und Protokolle der Updates nur für root; kein Update offen
    if [[ "$mit_update" == "ja" ]]; then
        for d in /var/lib/powermanagement/update /var/log/powermanagement/update; do
            pruefe "$d: root 700" test "$(stat -c '%U %a' "$d" 2>/dev/null)" = "root 700"
        done
    fi
    pruefe "keine Marke eines unterbrochenen Updates" test ! -e "$MARKE_OFFEN"
    pruefe "keine Schreibsperre der Oberfläche" test ! -e "$WARTUNG"

    # Update-Dienst: Pfad-Einheit und Zeitgeber laufen, der Dienst ist nicht
    # gescheitert und meldet sich (Lebenszeichen, Stand aus git). Einmal
    # wecken wie der Zeitgeber – sonst stünde womöglich noch ein Lebenszeichen
    # von mitten aus dem letzten Update da.
    if [[ "$mit_update" == "ja" ]]; then
        for einheit in powermanagement-update.path powermanagement-update.timer powermanagement-update-zeitplan.timer; do
            pruefe "$einheit läuft" systemctl is-active --quiet "$einheit"
        done
        pruefe "powermanagement-update.service nicht gescheitert" \
            bash -c '! systemctl is-failed --quiet powermanagement-update.service'
        # Diese Prüfung drängt Stunden von Updates in Minuten: jede Phase weckt den
        # Dienst mehrmals (Pfad-Einheit, update-debian.sh, diese Zeile). Die
        # Schleifenbremse der Einheit (20 Starts in 5 min) gilt dem Betrieb – ihr
        # Zähler beginnt hier von vorn; gescheitert ist der Dienst ja nicht (oben).
        systemctl reset-failed powermanagement-update.service 2>/dev/null || true
        pruefe "Update-Dienst läuft ohne Fehler durch" systemctl start powermanagement-update.service
        pruefe "Update-Dienst meldet sich (ist/helfer)" warte_auf 30 test -s "$IST/helfer"
        pruefe "ist/helfer: Kopf, Oberfläche an, nichts offen" bash -c \
            'head -n 1 "$1/helfer" | grep -qx "# powermanagement update-helfer 1" && grep -qx "oberflaeche ja" "$1/helfer" && grep -qx "offen nein" "$1/helfer" && grep -qx "pfad ok" "$1/helfer"' _ "$IST"
        pruefe "ist/stand vorhanden (installiert, Zweig)" bash -c \
            'grep -qE "^installiert [0-9a-f]{40} [0-9]+$" "$1/stand" && grep -qx "zweig main" "$1/stand"' _ "$IST"
        pruefe "ist/ gehört www-data, Dateien 640" \
            leer "$(find "$IST" -mindepth 1 \( ! -user www-data -o -type f ! -perm 640 \) -print)"
        # Der Zeitplan (automatische Updates) läuft als Anwendungsbenutzer in der
        # Sandbox – einmal wie sein Zeitgeber starten: er endet ohne Fehler und
        # hinterlässt sein Lebenszeichen.
        pruefe "Update-Zeitplan läuft ohne Fehler durch" systemctl start powermanagement-update-zeitplan.service
        pruefe "Update-Zeitplan meldet sich (storage/update/zeitplan)" test -e "$ZIEL/storage/update/zeitplan"
        pruefe "bin/power update:stand: eingerichtet" bash -c \
            'cd "$1" && runuser -u www-data -- php bin/power update:stand | grep "^Update-Funktion: eingerichtet" > /dev/null' _ "$ZIEL"
        # bin/power als root: bricht vor dem require ab (Befund 10) – mit Hinweis,
        # und in storage/ entsteht nichts, das root gehört.
        pruefe "bin/power als root abgelehnt (runuser-Hinweis)" bash -c \
            'cd "$1" && ! php bin/power pruefen > /dev/null 2> /tmp/pm-waechter.txt && grep -q "runuser -u www-data -- php bin/power pruefen" /tmp/pm-waechter.txt' _ "$ZIEL"
        pruefe "storage/ danach weiter ohne root-eigene Dateien" leer "$(find "$ZIEL/storage" -user root -print)"
    fi

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

    # Die Grenzen für Grundrisse bis 100 MB gibt es erst mit diesen Vorlagen –
    # ein älterer Stand (Umstieg, U1) läuft noch mit 64M bzw. 64m.
    if grep -q 'location = /standorte/grundriss' "$ZIEL/deploy/nginx-powermanagement.conf"; then
        pruefe_grenzen
    else
        echo "    (Grenzen für Grundrisse: Stand vor den 100 MB – übersprungen)"
    fi
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
    # In Stücken (Transfer-Encoding: chunked) zählt nur die location, in der
    # nginx den Körper liest – jede Adresse muss ihre eigene Grenze haben.
    code="$(head -c $((17 * 1024 * 1024)) /dev/zero | curl -s -o /dev/null -w '%{http_code}' --max-time 60 -H 'Host: powermanagement.lan' \
        -H 'Expect:' -H 'Transfer-Encoding: chunked' -H 'Content-Type: application/octet-stream' --data-binary @- http://127.0.0.1/login 2>/dev/null)" || true
    pruefe "nginx: 17 MiB in Stücken an /login → 413 (kam: ${code:-000})" test "${code:-000}" = 413
    seite="$(head -c $((51 * 1024 * 1024)) /dev/zero | curl -s --max-time 60 -H 'Host: powermanagement.lan' -H 'Expect:' \
        -H 'Transfer-Encoding: chunked' -H 'Content-Type: application/json' --data-binary @- http://127.0.0.1/api/v1/locations/x/vorschlaege 2>/dev/null)" || true
    pruefe "nginx: 51 MiB in Stücken an /api/ → 413 als JSON" grep -q 'Der Körper ist zu groß' <<<"$seite"
    code="$(head -c $((100 * 1024 * 1024)) /dev/zero | curl -s -o /dev/null -w '%{http_code}' --max-time 60 -H 'Host: powermanagement.lan' \
        -H 'Expect:' -H 'Transfer-Encoding: chunked' -H 'Content-Type: application/octet-stream' --data-binary @- http://127.0.0.1/standorte/grundriss 2>/dev/null)" || true
    pruefe "nginx und PHP: 100 MiB in Stücken an /standorte/grundriss angenommen (→ 302, kam: ${code:-000})" test "${code:-000}" = 302

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

# Die verfolgten Dateien des Arbeitsstands aus /src nach $QUELLE – auf dem
# eigenen Rechner also auch noch nicht eingecheckte Änderungen. Laufzeitdaten
# (config.php, Datenbank) bleiben draußen.
arbeitsstand_kopieren() {
    git -c safe.directory=/src -C /src ls-files -z \
        | tar -C /src --null --ignore-failed-read -T - -cf - 2>/dev/null \
        | tar -C "$QUELLE" --no-same-owner -xf -
}

# ------------------------------------------------------------ Umstieg
if [[ -n "$UMSTIEG" ]]; then
    schritt "Repository vorbereiten (Umstieg: erst $UMSTIEG, dann der Arbeitsstand)"
    if ! git -c safe.directory=/src -C /src cat-file -e "$UMSTIEG^{commit}" 2>/dev/null; then
        echo "    Commit $UMSTIEG fehlt in /src (flacher Klon? actions/checkout mit fetch-depth: 0) – Umstieg übersprungen."
        exit 0
    fi
    rm -rf "$QUELLE" "$REMOTE" "$ZIEL"
    mkdir -p "$QUELLE"
    git -c safe.directory=/src -C /src archive "$UMSTIEG" | tar -C "$QUELLE" --no-same-owner -xf -
    git -C "$QUELLE" init -q -b main
    git -C "$QUELLE" add -A -f
    einchecken "Stand $UMSTIEG (ohne Update-Funktion)"
    git clone -q --bare "$QUELLE" "$REMOTE"
    git -C "$QUELLE" remote add origin "$REMOTE"

    schritt "U1 · Erstinstallation von $UMSTIEG"
    git clone -q "$REMOTE" "$ZIEL"
    if ! "$ZIEL/deploy/install-debian.sh" --name="Prüfung Umstieg" > "$LAUF" 2>&1; then
        cat "$LAUF"
        fehler "deploy/install-debian.sh ($UMSTIEG) bricht ab"
        abschluss "Umstieg U1"
    fi
    sed 's/^/  | /' "$LAUF"
    ok "deploy/install-debian.sh ($UMSTIEG) läuft durch"
    pruefe "Administrator angelegt" grep -q 'Erstkennwort – bitte bei der ersten Anmeldung ändern' "$LAUF"
    pruefe "noch ohne Update-Dienst" test ! -e /etc/systemd/system/powermanagement-update.service
    pruefe_installation ohne_update
    abschluss "Umstieg U1"

    schritt "U2 · Umstieg genau wie in der Anleitung (zwei Läufe in einer Einheit)"
    find "$QUELLE" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
    arbeitsstand_kopieren
    git -C "$QUELLE" add -A -f
    einchecken "Arbeitsstand mit Update-Funktion"
    git -C "$QUELLE" push -q origin main
    NEU="$(git -C "$QUELLE" rev-parse HEAD)"
    # Der Befehl aus docs/installation-debian-lxc.md (Umstieg), Schritt 3 – wörtlich.
    systemd-run --unit=pm-umstieg --collect --setenv=HOME=/root \
      /bin/bash -c '/opt/powermanagement/deploy/update-debian.sh && /opt/powermanagement/deploy/update-debian.sh'
    # systemd-run legt einen einfachen Dienst an: solange er läuft, ist er
    # „active“ (nicht „activating“ wie die oneshot-Dienste oben). Mit --collect
    # ist er danach weg – „inactive“, auch wenn er gescheitert ist.
    for _ in $(seq 1 900); do
        [[ "$(systemctl is-active pm-umstieg 2>/dev/null || true)" =~ ^(active|activating|deactivating|reloading)$ ]] || break
        sleep 1
    done
    journalctl --sync 2>/dev/null || true
    journalctl -a -u pm-umstieg --no-pager > "$LAUF" 2>&1 || true
    sed 's/^/  | /' "$LAUF"
    pruefe "pm-umstieg endet erfolgreich (Deactivated successfully)" enthaelt "$LAUF" "Deactivated successfully"
    pruefe "Lauf 2: Ergebnis aktuell – Update-Funktion eingerichtet" \
        enthaelt "$LAUF" "==> Ergebnis: aktuell – Update-Funktion eingerichtet"
    pruefe "Lauf 2: holt die Einrichtung nach" \
        enthaelt "$LAUF" "Einrichtung unvollständig (Update-Funktion, Einheiten oder storage/-Ordner) – wird nachgeholt."
    # Lauf 2 startet nur die neuen Update-Einheiten, keinen bestehenden Dienst neu.
    LAUF2="$(sed -n '/Bereits auf dem neuesten Stand/,$p' "$LAUF")"
    pruefe "Lauf 2: kein bestehender Dienst neu gestartet" \
        bash -c '! grep -qE "PHP-FPM neu geladen|Sammler läuft wieder|mosquitto neu gestartet|Node-RED neu gestartet" <<< "$1"' _ "$LAUF2"
    pruefe "auf dem neuen Stand" test "$(kopf)" = "$NEU"
    pruefe "Zweig bleibt main" test "$(zweig)" = main
    for einheit in "${UPDATE_EINHEITEN[@]}"; do
        pruefe "$einheit eingerichtet" test -f "/etc/systemd/system/$einheit"
    done
    pruefe "ist/stand: installiert = neuer Stand" warte_auf 30 grep -qE "^installiert $NEU [0-9]+$" "$IST/stand"
    # Neuere Flows (etwa die Analogeingänge der Tanksensoren) bringt schon Lauf 1
    # mit dem alten Skript – niemand hat sie im Editor geändert.
    pruefe "Node-RED-Flows auf dem neuen Stand" cmp -s "$ZIEL/deploy/node-red/flows.json" "$NR_FLOWS"
    pruefe "keine behaltenen Flows" bash -c '[[ ! -e "$1" ]] && ! grep -qF "im Editor verändert" "$2"' _ "$NR_NEU" "$LAUF"
    pruefe "alle Migrationen eingespielt (auch 016 Kundenbereich und 018 Tanks)" app_php '$da = array_column(\App\Support\Database::select("SELECT name FROM migrations"), "name");
        $fehlt = array_diff(array_map("basename", glob("database/migrations/*.sql")), $da);
        exit($fehlt === [] && in_array("016_kundenbereich.sql", $da, true) && in_array("018_tanks.sql", $da, true) ? 0 : 1);'
    pruefe_installation
    abschluss "Umstieg U2"

    schritt "U3 · Das erste Update über die Oberfläche – ab jetzt übernimmt Stufe 2"
    NEU="$(neuer_stand "Erstes Update über die Oberfläche" "printf '\n' >> README.md")"
    pruefe "Administrationskonto für die Prüfung angelegt" admin_anlegen
    pruefe "Anmeldung über nginx" anmelden
    abstand_abwarten
    LAUF_VORHER="$(ist_wert lauf kennung)"
    pruefe "Suchen und Aktualisieren über die Oberfläche" ueber_oberflaeche "$NEU"
    pruefe "Status-Abfrage bis zum Ende" oberflaeche_abwarten 900
    pruefe "Oberfläche: aktualisieren eingespielt (kam: $ERGEBNIS_UI)" test "$ERGEBNIS_UI" = "aktualisieren eingespielt -"
    pruefe "auf dem neuen Stand" test "$(kopf)" = "$NEU"
    pruefe "Stufe 2 der neuen Fassung" enthaelt "$IST/protokoll" "Die neue Fassung des Update-Skripts übernimmt."
    pruefe_installation
    abschluss "Umstieg U3"

    echo
    echo "Umstieg von $UMSTIEG auf den Arbeitsstand in Ordnung."
    exit 0
fi

schritt "Repository vorbereiten (Arbeitsstand aus /src)"
rm -rf "$QUELLE" "$REMOTE" "$ZIEL"
mkdir -p "$QUELLE"
arbeitsstand_kopieren
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
# Den Platzbedarf misst die Anleitung (Abschnitt 1) mit genau diesem Befehl
# nach – das Debian-Paket speichert unter /var/lib/influxdb (Befund 15).
pruefe "du -sh /var/lib/influxdb/engine wie in der Anleitung" bash -c 'du -sh /var/lib/influxdb/engine >/dev/null'
pruefe "Installation: erstes Lebenszeichen des Update-Dienstes" warte_auf 30 test -s "$IST/helfer"
# Die gerenderten Einheiten des Update-Dienstes: systemd liest sie ohne Einwand.
# (Nur Zeilen zu diesen Einheiten – Abhängigkeiten wie nginx.service melden
# womöglich eigene Hinweise.)
VERIFY="$(systemd-analyze verify "${UPDATE_EINHEITEN[@]/#//etc/systemd/system/}" 2>&1 | grep -F powermanagement-update || true)"
pruefe "systemd-analyze verify: Einheiten des Update-Dienstes ohne Befund" leer "$VERIFY"
pruefe_installation
abschluss "Erstinstallation"

# ------------------------------------------------------------ 2
schritt "2 · Derselbe Aufruf noch einmal (das Repository hat inzwischen ein neues Installationsskript)"
KONFIG_VORHER="$(sha256sum "$ZIEL/config/config.php")"
ZUGAENGE_VORHER="$(grep -E '^[A-Z0-9_]+=' "$ZUGAENGE" | sort)"
# In der Oberfläche geändert (so speichert System → Einstellungen): Titel und
# InfluxDB-Organisation bleiben beim zweiten Lauf (Befund 4). Den Zugang zum
# Broker im Container schreibt jeder Lauf neu – ein falsches Kennwort in der
# Anwendung passt danach wieder zur Kennwortdatei.
einstellung_wert() { PM_K="$1" app_php 'echo \App\Support\Settings::get(getenv("PM_K"), "-");'; }
mqtt_zugang_passt() {
    PM_KW="$(zugang MQTT_POWERMANAGEMENT_KENNWORT)" app_php 'echo hash_equals(getenv("PM_KW"),
        \App\Support\Crypto::decrypt(\App\Support\Settings::get(\App\Support\Settings::GEHEIM_MQTT_KENNWORT, ""))) ? "ja" : "nein";'
}
pruefe "Einstellungen wie in der Oberfläche geändert" app_php '$s = "\App\Support\Settings";
    $s::put("app.name", "Titel aus der Oberfläche"); $s::put("influx.org", "org-aus-der-oberflaeche");
    $s::geheimnisSetzen($s::GEHEIM_MQTT_KENNWORT, "falsch-aus-der-oberflaeche");'
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
pruefe "Titel aus der Oberfläche bleibt (kam: $(einstellung_wert app.name))" \
    test "$(einstellung_wert app.name)" = "Titel aus der Oberfläche"
pruefe "influx.org aus der Oberfläche bleibt" test "$(einstellung_wert influx.org)" = "org-aus-der-oberflaeche"
pruefe "Zusammenfassung nennt, was bleibt" grep -qx '  Beibehalten:  app.name influx.org' "$LAUF"
pruefe "Zugang zum Broker im Container passt wieder zur Kennwortdatei" test "$(mqtt_zugang_passt)" = ja
# Sitzungsdateien (Befund 9): Debians php.ini schaltet die GC ab, für
# storage/sessions schaltet Session::start() sie ein – Lebensdauer aus
# idle_timeout/max_session (Vorgabe 43200 s). Wie im Pool: als www-data, mit
# dessen Verzeichnis. Die GC selbst läuft nur bei jedem tausendsten Start,
# hier einmal ausdrücklich (session_gc()).
SITZUNGEN="$ZIEL/storage/sessions"
runuser -u www-data -- touch -d '13 hours ago' "$SITZUNGEN/sess_pruefungalt"
runuser -u www-data -- touch -d '1 hour ago' "$SITZUNGEN/sess_pruefungjung"
GC="$(cd "$ZIEL" && runuser -u www-data -- php -d "session.save_path=$SITZUNGEN" -r 'require "src/bootstrap.php";
    \App\Support\Session::start();
    echo ini_get("session.gc_probability"), "/", ini_get("session.gc_divisor"), " ", ini_get("session.gc_maxlifetime"), " ", var_export(session_gc(), true);' 2>&1)" || true
pruefe "Sitzungen: GC für storage/sessions eingeschaltet, Lebensdauer 43200 s (kam: $GC)" \
    bash -c '[[ "$1" =~ ^1/1000\ 43200\ [1-9][0-9]*$ ]]' _ "$GC"
pruefe "Sitzungsdatei älter als 12 h weggeräumt" test ! -e "$SITZUNGEN/sess_pruefungalt"
pruefe "Sitzungsdatei von vor 1 h bleibt" test -e "$SITZUNGEN/sess_pruefungjung"
rm -f "$SITZUNGEN/sess_pruefungjung"
pruefe_installation
abschluss "zweiter Lauf"

# ------------------------------------------------------------ 2b
schritt "2b · Datenbank ohne Benutzer (wie nach einem abgebrochenen ersten Lauf): derselbe Aufruf legt den Administrator an"
# Datei und Tabellen gibt es, nur keinen Benutzer (Befund 2). Früher hieß das
# „Datenbank vorhanden“ – es kam nie ein Administrator dazu, und niemand
# konnte sich anmelden.
KENNWORT_VORHER="$(zugang OBERFLAECHE_KENNWORT)"
pruefe "alle Benutzer entfernt" app_php '\App\Support\Database::execute("DELETE FROM user_roles"); \App\Support\Database::execute("DELETE FROM users");'
if ! "$ZIEL/deploy/install-debian.sh" --name="Prüfung Installation" > "$LAUF" 2>&1; then
    cat "$LAUF"
    fehler "deploy/install-debian.sh bricht bei einer Datenbank ohne Benutzer ab"
    abschluss "Datenbank ohne Benutzer"
fi
sed 's/^/  | /' "$LAUF"
ok "deploy/install-debian.sh läuft durch"
pruefe "meldet die Datenbank ohne Benutzer" grep -q 'Die Datenbank hat noch keine Benutzer' "$LAUF"
pruefe "Administrator angelegt" grep -q 'Erstkennwort – bitte bei der ersten Anmeldung ändern' "$LAUF"
pruefe "neues Erstkennwort in zugaenge.txt" test "$(zugang OBERFLAECHE_KENNWORT)" != "$KENNWORT_VORHER"
pruefe "genau ein Benutzer: admin" \
    test "$(app_php 'echo implode(",", array_column(\App\Support\Database::select("SELECT username FROM users"), "username"));')" = admin
# Ohne Benutzer gilt die Datenbank als neu: Titel und Einstellungen aus diesem Aufruf.
pruefe "Titel und influx.org aus diesem Aufruf (kam: $(einstellung_wert app.name), $(einstellung_wert influx.org))" \
    bash -c '[[ "$1" == "Prüfung Installation" && "$2" == powermanagement ]]' _ "$(einstellung_wert app.name)" "$(einstellung_wert influx.org)"
# pruefe_installation meldet sich mit dem neuen Erstkennwort an.
pruefe_installation
abschluss "Datenbank ohne Benutzer"

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
# Ein Grundriss ohne Kacheln, dazu der Rest eines getöteten Baus (Zoomstufe
# ohne kacheln.json – wie nach einem Zurückrollen, das PHP-FPM anhielt): Das
# Update rechnet sie nicht selbst unter der Schreibsperre, sondern stößt nach
# der Prüfung eine eigene Einheit als www-data an (powermanagement-kacheln).
KACHEL_ETAGE="$(cd "$ZIEL" && runuser -u www-data -- php -r '
    require "src/bootstrap.php";
    require "tests/hilfen/grundriss-dateien.php";
    $site = App\Services\SiteService::saveSite(null, ["name" => "Prüfung Kacheln nach dem Update"]);
    $etage = App\Services\SiteService::saveFloor(null, ["site_id" => $site, "name" => "EG"]);
    $name = "site" . $site . "_" . bin2hex(random_bytes(8)) . ".pdf";
    pruefPdf(App\Services\SiteService::planDir() . "/" . $name, 1);
    App\Support\Database::update("floors", $etage, ["plan_file" => $name, "plan_type" => "pdf", "plan_page" => 1, "plan_original_name" => "eg.pdf"]);
    $o = App\Services\PlanTileService::planDir(App\Services\SiteService::floor($etage));
    mkdir($o . "/9/77", 0775, true);
    file_put_contents($o . "/9/77/88.png", "Rest eines getöteten Baus");
    echo $etage;')"
kacheln_fertig() {
    PM_ETAGE="$1" app_php '$e = App\Services\SiteService::floor((int) getenv("PM_ETAGE"));
        exit($e !== null && App\Services\PlanTileService::meta($e) !== null && !is_dir(App\Services\PlanTileService::planDir($e) . "/9") ? 0 : 1);'
}
pruefe "vorher: Grundriss ohne Kacheln, mit dem Rest eines getöteten Baus (Etage ${KACHEL_ETAGE:-?})" \
    bash -c '[[ "$1" =~ ^[0-9]+$ ]]' _ "$KACHEL_ETAGE"
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
pruefe "Update: fehlende Kacheln an powermanagement-kacheln übergeben" enthaelt "$LAUF" "Fehlende Kacheln rechnet jetzt powermanagement-kacheln nach"
pruefe "Update: erst nach der Schreibsperre (nicht unter ihr)" bash -c \
    'aus="$(grep -n "Schreibsperre der Oberfläche aus" "$1" | tail -1 | cut -d: -f1)"; k="$(grep -n "powermanagement-kacheln nach" "$1" | tail -1 | cut -d: -f1)"
     [[ -n "$aus" && -n "$k" && "$k" -gt "$aus" ]]' _ "$LAUF"
# --no-block: Die Einheit läuft womöglich erst einen Augenblick später an.
pruefe "nach dem Update: Kacheln nachgerechnet, der Rest des getöteten Baus weg" warte_auf 300 kacheln_fertig "$KACHEL_ETAGE"
pruefe "powermanagement-kacheln endet" einheit_abwarten powermanagement-kacheln.service
pruefe "powermanagement-kacheln lief als www-data (Kacheln gehören www-data)" \
    leer "$(find "$ZIEL/storage/plans" ! -user www-data -print)"
PM_ETAGE="$KACHEL_ETAGE" app_php 'App\Services\SiteService::deleteSite((int) App\Services\SiteService::floor((int) getenv("PM_ETAGE"))["site_id"]);' || true
pruefe_installation
pruefe_grenzen hochladen

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

# ------------------------------------------------------------ 3c
schritt "3c · Node-RED-Flows: unverändert ersetzt, im Editor verändert behalten (mit Hinweis), --flows-ersetzen"
# Neue Flows (wie die Analogeingänge der Tanksensoren) – an der Installation hat niemand etwas geändert.
NEU="$(neuer_stand "Neue Fassung der Flows" \
    "sed -i 's/\"name\": \"Shelly Gen1\",/\"name\": \"Shelly Gen1 · 3c\",/' deploy/node-red/flows.json; grep -qF 'Shelly Gen1 · 3c' deploy/node-red/flows.json")"
update_lauf
pruefe "unveränderte Flows: Update endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "unveränderte Flows: durch die neue Fassung ersetzt" cmp -s "$ZIEL/deploy/node-red/flows.json" "$NR_FLOWS"
pruefe "unveränderte Flows: Meldung im Protokoll" enthaelt "$LAUF" "Node-RED-Flows auf die mitgelieferte Fassung gebracht"
pruefe "unveränderte Flows: keine Datei daneben, kein Hinweis" \
    bash -c '[[ ! -e "$1" ]] && ! grep -qF flows_behalten "$2" && grep -qF "==> Ergebnis: eingespielt –" "$2"' _ "$NR_NEU" "$LAUF"
pruefe "Flows gehören nodered (640)" test "$(stat -c %U:%G:%a "$NR_FLOWS")" = nodered:nodered:640
pruefe "Node-RED läuft mit den neuen Flows" warte_auf 60 systemctl is-active --quiet powermanagement-nodered.service
# Im Editor verändert und bereitgestellt – hier: eine Zeile in flows.json.
sed -i 's/"name": "Shelly Gen1 · 3c",/"name": "Shelly Gen1 · eigene Fassung",/' "$NR_FLOWS"
chown nodered:nodered "$NR_FLOWS"
chmod 640 "$NR_FLOWS"
EIGENE="$(sha256sum < "$NR_FLOWS")"
NEU="$(neuer_stand "Noch eine Fassung der Flows" \
    "sed -i 's/Shelly Gen1 · 3c/Shelly Gen1 · 3c, zweite/' deploy/node-red/flows.json; grep -qF 'Shelly Gen1 · 3c, zweite' deploy/node-red/flows.json")"
update_lauf
pruefe "veränderte Flows: Update endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "veränderte Flows: bleiben, wie sie sind" test "$(sha256sum < "$NR_FLOWS")" = "$EIGENE"
pruefe "veränderte Flows: die neue Fassung liegt daneben" cmp -s "$ZIEL/deploy/node-red/flows.json" "$NR_NEU"
pruefe "veränderte Flows: Hinweis nennt die Tanksensoren" enthaelt "$LAUF" "Tanksensoren brauchen die neuen Flows"
# Die alten Flows übersetzen den Analog-Eingang (input:N, percent) schon – nur ohne x und sensorfehler.
pruefe "veränderte Flows: Hinweis sagt, was als Analog-Eingang noch ankommt" \
    enthaelt "$LAUF" "als Analog-Eingang nur eingang, ohne x und ohne Erkennung von Sensorfehlern"
pruefe "veränderte Flows: Ergebnis mit Hinweis flows_behalten" \
    bash -c 'grep -qF "==> Ergebnis: eingespielt_mit_warnung" "$1" && grep -qF "Hinweise:  flows_behalten" "$1"' _ "$LAUF"
pruefe "veränderte Flows: die Zusammenfassung sagt es noch einmal" enthaelt "$LAUF" "Node-RED:  Flows im Editor verändert und behalten"
update_lauf --flows-ersetzen
pruefe "--flows-ersetzen endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "--flows-ersetzen: die mitgelieferte Fassung" cmp -s "$ZIEL/deploy/node-red/flows.json" "$NR_FLOWS"
pruefe "--flows-ersetzen: die eigene Fassung als flows.json.vor-<zeit>" \
    bash -c 'for f in "$1".vor-*; do [[ -f "$f" && "$(sha256sum < "$f")" == "$2" ]] && exit 0; done; exit 1' _ "$NR_FLOWS" "$EIGENE"
pruefe "--flows-ersetzen: keine Datei mehr daneben" test ! -e "$NR_NEU"
pruefe "--flows-ersetzen: Ergebnis aktuell" enthaelt "$LAUF" "==> Ergebnis: aktuell – Node-RED-Flows neu eingespielt"
pruefe_installation
abschluss "Node-RED-Flows"

# ------------------------------------------------------------ 3d
schritt "3d · Von Hand veränderte nginx-Site ohne den Block für Grundrisse: bleibt, das Update sagt es"
# Wie eine Site, die jemand vor den 100 MB angepasst hat: ohne
# location = /standorte/grundriss. Das Update behält sie, legt die neue
# Fassung daneben und nennt den fehlenden Block; übernimmt man die neue
# Fassung, gelten die Grenzen wieder.
sed -i '/^    location = \/standorte\/grundriss {$/,/^    }$/d' "$SITE_DATEI"
pruefe "vorher: Site ohne den Block für Grundrisse" bash -c '! grep -q "location = /standorte/grundriss" "$1"' _ "$SITE_DATEI"
pruefe "vorher: nginx -t mit der veränderten Site" nginx -t -q
systemctl reload nginx
SITE_VORHER="$(sha256sum < "$SITE_DATEI")"
NEU="$(neuer_stand "Stand für die veränderte Site" "printf '\n' >> README.md")"
update_lauf
pruefe "veränderte Site: Update endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "veränderte Site: bleibt, wie sie ist" test "$(sha256sum < "$SITE_DATEI")" = "$SITE_VORHER"
pruefe "veränderte Site: Hinweis und neue Fassung daneben" \
    bash -c 'grep -qF "Die nginx-Site ist von Hand verändert und bleibt so" "$1" && grep -q "location = /standorte/grundriss" /etc/nginx/powermanagement.powermanagement-neu' _ "$LAUF"
pruefe "veränderte Site: das Update nennt den fehlenden Block für Grundrisse" enthaelt "$LAUF" "Darin fehlt der Block für Grundrisse bis 100 MB"
pruefe "veränderte Site: Ergebnis mit Hinweis site_behalten" enthaelt "$LAUF" "Hinweise:  site_behalten"
code="$(head -c $((17 * 1024 * 1024)) /dev/zero | curl -s -o /dev/null -w '%{http_code}' --max-time 60 -H 'Host: powermanagement.lan' \
    -H 'Expect:' -H 'Content-Type: application/octet-stream' --data-binary @- http://127.0.0.1/standorte/grundriss 2>/dev/null)" || true
pruefe "veränderte Site: ohne den Block gilt die Grenze der Site (17 MiB → 413, kam: ${code:-000})" test "${code:-000}" = 413
cp /etc/nginx/powermanagement.powermanagement-neu "$SITE_DATEI"
pruefe "neue Fassung übernommen: nginx -t" nginx -t -q
systemctl reload nginx
pruefe_installation
abschluss "veränderte Site"

# ------------------------------------------------------------ 4
schritt "4 · Update über die Oberfläche mit Selbst-Update: die neue Fassung übernimmt im selben Lauf"
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
pruefe "Administrationskonto für die Prüfung angelegt" admin_anlegen
pruefe "Anmeldung über nginx" anmelden
http_get /system/update
pruefe "System → Update: 200 mit dem installierten Stand (kam: $HTTP_CODE)" \
    bash -c '[[ "$1" == 200 ]] && grep -qF "$2" "$3"' _ "$HTTP_CODE" "${ALT:0:7}" "$ANTWORT"
abstand_abwarten
LAUF_VORHER="$(ist_wert lauf kennung)"
pruefe "Suchen und Aktualisieren über die Oberfläche" ueber_oberflaeche "$NEU"
pruefe "Status-Abfrage bis zum Ende (Neustarts der Dienste dazwischen erlaubt)" oberflaeche_abwarten 600
pruefe "Oberfläche: aktualisieren eingespielt (kam: $ERGEBNIS_UI)" test "$ERGEBNIS_UI" = "aktualisieren eingespielt -"
journalctl --sync 2>/dev/null || true
pruefe "auf dem neuen Stand" test "$(kopf)" = "$NEU"
pruefe "ist/protokoll gehört zu diesem Lauf" test "$(ist_wert protokoll kennung)" = "$(ist_wert lauf kennung)"
pruefe "die neue Fassung übernimmt" enthaelt "$IST/protokoll" "Die neue Fassung des Update-Skripts übernimmt."
pruefe "Prüfzeile der neuen Fassung im selben Lauf, genau einmal" test "$(grep -cF "$PRUEFZEILE" "$IST/protokoll")" = 1
pruefe "ist/protokoll ohne ESC" ohne_esc "$IST/protokoll"
pruefe "ist/protokoll ohne Zugangsdaten" bash -c '! grep -qE "x-access-token|gh[pousr]_[A-Za-z0-9]{10}" "$1"' _ "$IST/protokoll"
pruefe "Journal des Update-Dienstes: Ergebnis eingespielt" \
    bash -c 'journalctl -a -u powermanagement-update --no-pager -o cat | grep -qF "==> Ergebnis: eingespielt"'
pruefe "Verlauf: eingespielt" bash -c "tail -n 1 /var/lib/powermanagement/update/verlauf | grep -q ' hand $ADMIN_NAME aktualisieren eingespielt '"
pruefe "Kopie der alten Fassung aufgeräumt" test ! -e /var/lib/powermanagement/update/alt/update-debian.sh
pruefe "kein Schnappschuss übrig" leer "$(find "$ZIEL/storage/backups" -maxdepth 1 -name '.schnappschuss-*' -print)"
http_get /system/update
pruefe "System → Update zeigt den neuen Stand" grep -qF "${NEU:0:7}" "$ANTWORT"
pruefe "Audit: system.update.ergebnis (beim Seitenaufruf vermerkt)" \
    test "$(audit_zahl system.update.ergebnis "%\"ergebnis\":\"eingespielt\"%\"neu7\":\"${NEU:0:7}\"%")" = 1
pruefe "Audit: system.update.anfordern (Suchen und Aktualisieren)" \
    test "$(audit_zahl system.update.anfordern "%\"ziel7\":\"${NEU:0:7}\"%")" = 1
pruefe_installation
abschluss "Update über die Oberfläche"

# ------------------------------------------------------------ 5a
schritt "5a · Kaputte Migration (update:starten --warten): vollständig zurück"
ALT="$(kopf)"
SUMMEN="$(pruefsummen)"
NEU="$(neuer_stand "Kaputte Migration" "printf 'SELEKT kaputt;\n' > database/migrations/099_pruefung_kaputt.sql")"
abstand_abwarten konsole
update_starten
pruefe "update:starten --warten endet mit 2 – zurückgerollt (kam: $RC)" test "$RC" = 2
pruefe "Ergebnis zurueckgerollt (migration) (kam: $(ist_wert lauf ergebnis) $(ist_wert lauf grund))" \
    test "$(ist_wert lauf ergebnis) $(ist_wert lauf grund)" = "zurueckgerollt migration"
pruefe "update:starten zeigt Schritte mit Uhrzeit und das Ergebnis" \
    bash -c 'grep -qE "^[0-9]{2}:[0-9]{2}:[0-9]{2}  \[[0-9]+/[0-9]+\] " "$1" && grep -qF "Ergebnis: Zurückgerollt" "$1"' _ "$LAUF"
pruefe "Protokoll: Ergebnis zurueckgerollt (migration)" enthaelt "$IST/protokoll" "==> Ergebnis: zurueckgerollt (migration)"
pruefe "Stand wie vorher" test "$(kopf)" = "$ALT"
pruefe "Zweig bleibt main" test "$(zweig)" = main
pruefe "Einheiten, Vorlagen und Schema wie vorher" test "$(pruefsummen)" = "$SUMMEN"
# diff endet bei Unterschieden mit 1 – mit pipefail bräche das das Skript ab.
[[ "$(pruefsummen)" == "$SUMMEN" ]] || diff <(printf '%s\n' "$SUMMEN") <(pruefsummen) | sed 's/^/            /' || true
pruefe "der Stand gilt als gescheitert" grep -q "^$NEU [0-9]* migration\$" "$GESCHEITERT"
pruefe_installation
abschluss "kaputte Migration"
NEU="$(neuer_stand "Kaputte Migration entfernt" "git rm -q database/migrations/099_pruefung_kaputt.sql")"
update_lauf
pruefe "danach: Update endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "danach: auf dem neuen Stand" test "$(kopf)" = "$NEU"
abschluss "kaputte Migration"

# ------------------------------------------------------------ 5b
schritt "5b · Prüfung scheitert nach Abgleich und Neustart (update:starten --warten): auch Einheiten und Vorlagen zurück"
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
    sed -i "s/\"name\": \"Line Protocol und InfluxDB\",/\"name\": \"Line Protocol und InfluxDB · 5b\",/" deploy/node-red/flows.json
    grep -q "InfluxDB · 5b" deploy/node-red/flows.json
    printf "[Unit]\nDescription=Prüfung\n\n[Timer]\nOnCalendar=yearly\nUnit=powermanagement-wartung.service\n" \
        > deploy/powermanagement-pruefung.timer
    sed -i "/^        powermanagement-wartung.timer\$/a\\        powermanagement-pruefung.timer" deploy/update-debian.sh
    grep -q "powermanagement-pruefung.timer" deploy/update-debian.sh
    sed -i "/^declare(strict_types=1);\$/a if ((\$_SERVER[\"REQUEST_URI\"] ?? \"\") === \"/login\") { http_response_code(500); exit; }" public/index.php
    grep -q "http_response_code(500)" public/index.php')"
abstand_abwarten konsole
update_starten
pruefe "update:starten --warten endet mit 2 – zurückgerollt (kam: $RC)" test "$RC" = 2
pruefe "Ergebnis zurueckgerollt (pruefung) (kam: $(ist_wert lauf ergebnis) $(ist_wert lauf grund))" \
    test "$(ist_wert lauf ergebnis) $(ist_wert lauf grund)" = "zurueckgerollt pruefung"
pruefe "Protokoll: Ergebnis zurueckgerollt (pruefung)" enthaelt "$IST/protokoll" "==> Ergebnis: zurueckgerollt (pruefung)"
pruefe "Anmeldeseite scheiterte erst mit dem neuen Code" enthaelt "$IST/protokoll" "antwortet mit HTTP 500 statt 200"
pruefe "Abgleich mit der alten Fassung" enthaelt "$IST/protokoll" "Abgleich mit der alten Fassung:"
pruefe "Stand wie vorher" test "$(kopf)" = "$ALT"
pruefe "Zweig bleibt main" test "$(zweig)" = main
pruefe "Einheiten, Logrotate, Pool, Site, Flows samt Merker und Schema wie vorher" test "$(pruefsummen)" = "$SUMMEN"
[[ "$(pruefsummen)" == "$SUMMEN" ]] || diff <(printf '%s\n' "$SUMMEN") <(pruefsummen) | sed 's/^/            /' || true
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
schritt "6 · Abbruch (systemctl stop) und Absturz (SIGKILL) mitten in einem Update aus der Oberfläche"
ALT="$(kopf)"
GESCHEITERT_VORHER="$(cat "$GESCHEITERT" 2>/dev/null || true)"
# Stufe 2 wartet nach dem Abgleich 30 s – Zeit, um dazwischenzugehen.
NEU="$(neuer_stand "Langsames Update" \
    "sed -i '/^stufe2() {\$/,/^}\$/ s/^    schritt 9 \"Dienste starten\" dienste\$/    sleep 30\n&/' deploy/update-debian.sh; grep -q '^    sleep 30\$' deploy/update-debian.sh")"
abstand_abwarten
LAUF_VORHER="$(ist_wert lauf kennung)"
pruefe "Update über die Oberfläche angefordert" ueber_oberflaeche "$NEU"
pruefe "Fortschritt erreicht den Abgleich" ist_schritt_abwarten 8
pruefe "Schreibsperre der Oberfläche steht" test -e "$WARTUNG"
# Während der Schreibsperre: Lesen geht, ein POST wird mit Meldung abgewiesen.
http_get /system/update
pruefe "Schreibsperre: System → Update lässt sich lesen (kam: $HTTP_CODE)" test "$HTTP_CODE" = 200
http_post /system/update/suchen "_token=$(seiten_token)"
pruefe "Schreibsperre: ein POST der Oberfläche wird abgewiesen (kam: $HTTP_CODE)" \
    bash -c '[[ "$1" == 302 ]] && test ! -e "$2/auftrag"' _ "$HTTP_CODE" "$SOLL"
http_get /system/update
pruefe "Schreibsperre: die Seite nennt den Grund" enthaelt "$ANTWORT" "Gerade läuft ein Update – Änderungen sind in etwa einer Minute wieder möglich."
update_lauf
pruefe "ein zweiter Lauf endet sofort mit 1 (kam: $RC)" test "$RC" = 1
pruefe "ein zweiter Lauf meldet „Ein anderes Update läuft gerade.“" enthaelt "$LAUF" "Ein anderes Update läuft gerade."
# KillMode=mixed: TERM nur an den Helfer, er reicht es weiter; das Update
# beendet den laufenden Schritt und rollt zurück. systemctl stop kehrt erst
# danach zurück – wartete das Zurückrollen auf einen Auftrag, den systemd bis
# zum Ende des Stopps zurückhält (etwa an der Pfad-Einheit), nie.
pruefe "Abbruch: systemctl stop kehrt nach dem Zurückrollen zurück" timeout 300 systemctl stop powermanagement-update.service
pruefe "Oberfläche: zurueckgerollt (abgebrochen)" oberflaeche_abwarten 120
pruefe "Oberfläche: aktualisieren zurueckgerollt abgebrochen (kam: $ERGEBNIS_UI)" \
    test "$ERGEBNIS_UI" = "aktualisieren zurueckgerollt abgebrochen"
pruefe "Stand wie vorher" test "$(kopf)" = "$ALT"
pruefe "Zweig bleibt main" test "$(zweig)" = main
pruefe "ein Abbruch zählt nicht als gescheitert" test "$(cat "$GESCHEITERT" 2>/dev/null || true)" = "$GESCHEITERT_VORHER"
pruefe_installation
abschluss "Abbruch"

# Der Zeitgeber des Update-Dienstes holte das Zurückrollen sonst womöglich
# vor den Prüfungen unten nach – für diesen Teil anhalten.
systemctl stop powermanagement-update.timer
abstand_abwarten
LAUF_VORHER="$(ist_wert lauf kennung)"
pruefe "Update über die Oberfläche angefordert" ueber_oberflaeche "$NEU"
pruefe "Fortschritt erreicht den Abgleich" ist_schritt_abwarten 8
LAUF_VORHER="$(ist_wert lauf kennung)"
systemctl kill -s KILL powermanagement-update.service
pruefe "Update-Dienst nach SIGKILL beendet" einheit_abwarten powermanagement-update.service
pruefe "Marke „offen“ bleibt" test -e "$MARKE_OFFEN"
pruefe "Schreibsperre bleibt" test -e "$WARTUNG"
pruefe "Nachlauf: ist/lauf fehler abgebrochen helfer (kam: $(ist_wert lauf zustand) $(ist_wert lauf ergebnis) $(ist_wert lauf grund))" \
    test "$(ist_wert lauf kennung) $(ist_wert lauf zustand) $(ist_wert lauf ergebnis) $(ist_wert lauf grund)" = "$LAUF_VORHER fehler abgebrochen helfer"
update_lauf
pruefe "ein neues Update lehnt ab (kam: $RC)" test "$RC" = 1
pruefe "ein neues Update nennt --wiederaufnehmen" enthaelt "$LAUF" "--wiederaufnehmen"
# Wie nach einem Neustart: der Update-Dienst sieht die Marke bei freier
# update.lock und rollt mit der gesicherten alten Fassung zurück.
systemctl start powermanagement-update.service || true
journalctl --sync 2>/dev/null || true
pruefe "Oberfläche: der Wiederanlauf ist zu sehen" oberflaeche_abwarten 60
pruefe "Oberfläche: wiederaufnehmen zurueckgerollt unterbrochen (kam: $ERGEBNIS_UI)" \
    test "$ERGEBNIS_UI" = "wiederaufnehmen zurueckgerollt unterbrochen"
pruefe "Update-Dienst: Wiederanlauf mit Auslöser system" test "$(ist_wert lauf ausloeser)" = system
pruefe "Journal des Update-Dienstes: Ergebnis zurueckgerollt (unterbrochen)" \
    bash -c 'journalctl -a -u powermanagement-update --no-pager -o cat | grep -qF "==> Ergebnis: zurueckgerollt (unterbrochen)"'
pruefe "Stand wie vorher" test "$(kopf)" = "$ALT"
pruefe "Zweig bleibt main" test "$(zweig)" = main
pruefe "ein Absturz zählt nicht als gescheitert" test "$(cat "$GESCHEITERT" 2>/dev/null || true)" = "$GESCHEITERT_VORHER"
update_lauf --wiederaufnehmen
pruefe "danach: --wiederaufnehmen von Hand hat nichts mehr zu tun (kam: $RC)" \
    bash -c '[[ "$1" == 0 ]] && grep -qF "Kein unterbrochenes Update" "$2"' _ "$RC" "$LAUF"
systemctl start powermanagement-update.timer
pruefe_installation
abschluss "Absturz"

NEU="$(neuer_stand "Wieder schnell" "sed -i '/^    sleep 30\$/d' deploy/update-debian.sh")"
update_lauf
pruefe "danach: Update endet mit 0 (kam: $RC)" test "$RC" = 0
pruefe "danach: auf dem neuen Stand" test "$(kopf)" = "$NEU"
pruefe_installation
abschluss "Absturz"

# ------------------------------------------------------------ 7
schritt "7 · Automatik: im Zeitfenster von selbst einspielen, nie während eines Aufbaus"
# Den Zeitplan treiben hier die Prüfungen selbst (wie sein Zeitgeber) – sein
# Zeitgeber käme sonst dazwischen (etwa mit „nichts Neues“ für heute).
systemctl stop powermanagement-update-zeitplan.timer
# Das Fenster beginnt jetzt (Wanduhr der Installation), ohne Karenz und Versatz.
BEGINN="$(app_php 'echo (new DateTimeImmutable("now", \App\Support\Zeit::zone()))->format("H:i");')"
pruefe "update:automatik einspielen ab $BEGINN" \
    power_app update:automatik einspielen --tage=1,2,3,4,5,6,7 "--beginn=$BEGINN" --dauer=60 --karenz=0 --versatz=0
pruefe "update:stand: Automatik automatisch einspielen" bash -c 'cd "$1" && runuser -u www-data -- php bin/power update:stand | grep "^Automatik:       automatisch einspielen" > /dev/null' _ "$ZIEL"
NEU="$(neuer_stand "Stand für die Automatik" "printf '\n' >> README.md")"
abstand_abwarten konsole
pruefe "update:suchen --warten" power_app update:suchen --warten
pruefe "ist/stand nennt den neuen Stand" grep -qE "^verfuegbar $NEU [0-9]+\$" "$IST/stand"
pruefe "Zeitplan spielt im Fenster ein" zeitplan_bis "$NEU"
journalctl --sync 2>/dev/null || true
pruefe "Automatik: auf dem neuen Stand" test "$(kopf)" = "$NEU"
pruefe "Automatik: Lauf automatik/zeitplan eingespielt (kam: $(ist_wert lauf ausloeser) $(ist_wert lauf von) $(ist_wert lauf ergebnis))" \
    test "$(ist_wert lauf ausloeser) $(ist_wert lauf von) $(ist_wert lauf ergebnis)" = "automatik zeitplan eingespielt"
pruefe "Automatik: Audit system.update.automatisch" test "$(audit_zahl system.update.automatisch "%\"ziel7\":\"${NEU:0:7}\"%")" = 1
pruefe "Automatik: auto_letzter angefordert" test "$(auto_letzter)" = "angefordert -"
pruefe "Automatik: Verlauf automatik zeitplan eingespielt" \
    bash -c "tail -n 1 /var/lib/powermanagement/update/verlauf | grep -q ' automatik zeitplan aktualisieren eingespielt '"
LAUF_VORHER="$(ist_wert lauf kennung)"
pruefe "ein weiterer Zeitplan-Lauf endet ohne Fehler" systemctl start powermanagement-update-zeitplan.service
pruefe "ein weiterer Zeitplan-Lauf stellt keinen Auftrag" ruhe_abwarten 60
pruefe "ein weiterer Zeitplan-Lauf: kein neuer Lauf" test "$(ist_wert lauf kennung)" = "$LAUF_VORHER"
abschluss "Automatik"

# Das Update eben hat beim Abgleich auch den Zeitgeber des Zeitplans wieder
# gestartet (enable --now). Käme er vor der Suche unten, hielte er für heute
# „nichts Neues“ fest (der neue Stand ist noch nicht geholt) – danach wäre der
# Tag erledigt, nichts mehr verschoben oder eingespielt. Bis zum Ende von 7 anhalten.
systemctl stop powermanagement-update-zeitplan.timer
# Ein späterer Tag: das Update eben gilt als früher (zwei Tage – auch wenn das
# Fenster über Mitternacht reicht), ebenso für root (dessen 20-Stunden-Abstand).
# Und ein Aufbau läuft.
app_php '$l = \App\Services\UpdateAutomatik::letzter(); $l["tag"] = (new DateTimeImmutable("-2 days", \App\Support\Zeit::zone()))->format("Y-m-d");
    \App\Support\Settings::put(\App\Services\UpdateAutomatik::LETZTER, json_encode($l));'
if [[ -f /var/lib/powermanagement/update/zeiten ]]; then
    sed -i '/^automatik /d' /var/lib/powermanagement/update/zeiten
fi
pruefe "Aufbau „Prüfung Automatik“ läuft" aufbau_pruefung aufbau
ALT="$(kopf)"
NEU="$(neuer_stand "Stand während eines Aufbaus" "printf '\n' >> README.md")"
abstand_abwarten konsole
pruefe "update:suchen --warten" power_app update:suchen --warten
for i in 1 2; do
    pruefe "Aufbau: Zeitplan-Lauf $i endet ohne Fehler" systemctl start powermanagement-update-zeitplan.service
    ruhe_abwarten 60 || true
done
pruefe "Aufbau: verschoben (kam: $(auto_letzter))" test "$(auto_letzter)" = "verschoben aufbau"
pruefe "Aufbau: Stand unverändert" test "$(kopf)" = "$ALT"
pruefe "Aufbau: kein Auftrag" test ! -e "$SOLL/auftrag"
pruefe "Aufbau: Audit system.update.verschoben einmal (zwei Läufe)" test "$(audit_zahl system.update.verschoben '%"grund":"aufbau"%')" = 1
pruefe "Aufbau beendet" aufbau_pruefung beendet
abstand_abwarten
pruefe "nach dem Aufbau spielt der Zeitplan ein" zeitplan_bis "$NEU"
pruefe "nach dem Aufbau: auf dem neuen Stand" test "$(kopf)" = "$NEU"
app_php '$db = "\App\Support\Database"; $db::execute("DELETE FROM aufbauten WHERE name = ?", ["Prüfung Automatik"]);
    $db::execute("DELETE FROM sites WHERE name = ?", ["Prüfung Automatik"]);'
pruefe "update:automatik suchen (Vorgabe)" power_app update:automatik suchen
systemctl start powermanagement-update-zeitplan.timer
pruefe_installation
abschluss "Automatik"

# ------------------------------------------------------------ 8a
schritt "8a · Update-Dienst: Aufträge als App-Benutzer (Suchen, Aktualisieren)"
ALT="$(kopf)"
NEU="$(neuer_stand "Stand für den Update-Dienst" "printf '\n' >> README.md")"
# Das automatische Update aus 7 zählt eben erst als Suche – ohne Abstand holte
# der Dienst nichts, sondern meldete den Stand aus den Refs. Das Aktualisieren
# danach muss nicht mehr warten: eine Suche zählt nicht als Update.
abstand_abwarten
auftrag_legen pruefen
pruefe "Suchen: der Dienst meldet den Auftrag fertig" lauf_abwarten "$AUFTRAG_KENNUNG" 120
pruefe "Suchen: fertig geprueft (kam: $(ist_wert lauf zustand) $(ist_wert lauf ergebnis))" \
    test "$(ist_wert lauf zustand) $(ist_wert lauf ergebnis)" = "fertig geprueft"
pruefe "Suchen: Stand unverändert" test "$(kopf)" = "$ALT"
pruefe "Suchen: ist/stand nennt den neuen Stand als verfügbar und als Kandidaten" \
    bash -c 'grep -qE "^verfuegbar $2 [0-9]+$" "$1/stand" && grep -qE "^ziel_kandidat $2 [0-9]+$" "$1/stand" && grep -qx "neu 1" "$1/stand" && grep -qx "linear ja" "$1/stand"' _ "$IST" "$NEU"
auftrag_legen aktualisieren "$NEU"
pruefe "Aktualisieren: der Dienst meldet den Auftrag fertig" lauf_abwarten "$AUFTRAG_KENNUNG" 900
journalctl --sync 2>/dev/null || true
pruefe "Aktualisieren: fertig eingespielt (kam: $(ist_wert lauf zustand) $(ist_wert lauf ergebnis) $(ist_wert lauf grund))" \
    test "$(ist_wert lauf zustand) $(ist_wert lauf ergebnis)" = "fertig eingespielt"
pruefe "Aktualisieren: auf dem neuen Stand" test "$(kopf)" = "$NEU"
pruefe "Aktualisieren: ist/lauf nennt alt, neu, Sicherung und die Einheit" \
    bash -c 'grep -qx "alt $2" "$1/lauf" && grep -qx "neu $3" "$1/lauf" && grep -qE "^sicherung vor-update-[0-9-]+\.tar\.gz$" "$1/lauf" && grep -qE "^invocation [0-9a-f]{32}$" "$1/lauf"' _ "$IST" "$ALT" "$NEU"
pruefe "Aktualisieren: Stufe 2 der neuen Fassung im Protokoll" enthaelt "$IST/protokoll" "Die neue Fassung des Update-Skripts übernimmt."
pruefe "Aktualisieren: ist/protokoll endet mit dem Ergebnis" enthaelt "$IST/protokoll" "==> Ergebnis: eingespielt"
pruefe "Aktualisieren: ist/protokoll ohne ESC" ohne_esc "$IST/protokoll"
pruefe "Aktualisieren: ist/protokoll ohne Zugangsdaten" bash -c '! grep -qE "x-access-token|gh[pousr]_[A-Za-z0-9]{10}" "$1"' _ "$IST/protokoll"
pruefe "Aktualisieren: das Journal des Dienstes zeigt das Update" \
    bash -c 'journalctl -a -u powermanagement-update --no-pager -o cat | grep -qF "==> Ergebnis: eingespielt"'
pruefe "Aktualisieren: Verlauf mit Kennung und Auslöser" \
    bash -c 'grep -qE "^eintrag [0-9]+ [0-9]+ $2 konsole pruefung aktualisieren eingespielt " "$1/verlauf"' _ "$IST" "$AUFTRAG_KENNUNG"
pruefe "Aktualisieren: Protokoll unter /var/log/powermanagement/update (600)" \
    test "$(stat -c %a "/var/log/powermanagement/update/$(ist_wert lauf protokoll)" 2>/dev/null)" = 600
pruefe "Aktualisieren: ist/helfer nennt den neuen Stand" warte_auf 30 grep -qx "fassung $NEU" "$IST/helfer"
pruefe_installation
abschluss "Update-Dienst"

# ------------------------------------------------------------ 8b
schritt "8b · Untergeschobene Aufträge: abgelehnt, nichts als root angefasst"
ALT="$(kopf)"
SHADOW_VORHER="$(sha256sum /etc/shadow)"
SYSTEMD_VORHER="$(ls -l /etc/systemd/system)"
# abgelehnt_mit <fall> <code>
abgelehnt_mit() {
    pruefe "$1: abgeholt" helfer_abwarten
    pruefe "$1: abgelehnt $2 (kam: $(ist_wert lauf ergebnis) $(ist_wert lauf grund))" \
        test "$(ist_wert lauf zustand) $(ist_wert lauf ergebnis) $(ist_wert lauf grund)" = "fehler abgelehnt $2"
    pruefe "$1: soll/auftrag weg" bash -c '[[ ! -e "$1/auftrag" && ! -L "$1/auftrag" ]]' _ "$SOLL"
}
printf 'kaputt\n' | auftrag_roh
abgelehnt_mit "Müll" kopf
als_www ln -s /etc/shadow "$SOLL/auftrag"
abgelehnt_mit "Verweis auf /etc/shadow" keine_datei
als_www mkfifo "$SOLL/auftrag"
abgelehnt_mit "FIFO" keine_datei
als_www mkdir "$SOLL/auftrag"
abgelehnt_mit "Ordner" keine_datei
printf 'OBERFLAECHE=nein\n' > /etc/powermanagement/update.conf
auftrag_legen pruefen
abgelehnt_mit "gültiger Auftrag bei OBERFLAECHE=nein" abgeschaltet
pruefe "OBERFLAECHE=nein: ist/helfer meldet es" grep -qx "oberflaeche nein" "$IST/helfer"
rm -f /etc/powermanagement/update.conf
# soll/ durch einen Verweis ersetzt: root arbeitet dort nie selbst
als_www mv "$SOLL" "$SOLL.weg"
als_www ln -s /etc/systemd/system "$SOLL"
systemctl start powermanagement-update.service || true
pruefe "soll/ als Verweis: ist/helfer pfad soll_ungueltig" grep -qx "pfad soll_ungueltig" "$IST/helfer"
pruefe "soll/ als Verweis: /etc/systemd/system unverändert" test "$(ls -l /etc/systemd/system)" = "$SYSTEMD_VORHER"
als_www rm "$SOLL"
als_www mv "$SOLL.weg" "$SOLL"
systemctl reset-failed powermanagement-update.path powermanagement-update.service 2>/dev/null || true
systemctl restart powermanagement-update.path
systemctl start powermanagement-update.service || true
pruefe "/etc/shadow unverändert" test "$(sha256sum /etc/shadow)" = "$SHADOW_VORHER"
pruefe "/etc/systemd/system unverändert" test "$(ls -l /etc/systemd/system)" = "$SYSTEMD_VORHER"
pruefe "Stand unverändert" test "$(kopf)" = "$ALT"
pruefe "powermanagement-update.path läuft" systemctl is-active --quiet powermanagement-update.path
pruefe_installation
abschluss "untergeschobene Aufträge"

echo
echo "Installation, zweiter Lauf, Updates (ohne HOME, über die Oberfläche mit Selbst-Update, update:starten),"
echo "Zurückrollen, Abbruch, Absturz mit Wiederanlauf, automatische Updates und der Update-Dienst in Ordnung."
