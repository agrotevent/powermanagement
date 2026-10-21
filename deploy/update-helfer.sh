#!/bin/bash
#
# Update-Dienst: Aufträge der Anwendung übernehmen, prüfen und ausführen.
#
# Die Anwendung (www-data) startet nie selbst ein Update. Sie legt einen
# Auftrag nach storage/update/soll/auftrag (Kennung, Aktion, Auslöser, Ziel –
# keine Optionen, Zweige oder Pfade). powermanagement-update.path startet
# daraufhin powermanagement-update.service (root) mit diesem Helfer:
#
#   1. Code root-eigen? (Helfer, deploy/, bin/power, .git, .git/config, .git/hooks)
#   2. eigene Sperre (fd 8) – nie update.lock, die hält deploy/update-debian.sh
#   3. /etc/powermanagement/update.conf lesen, Pfad-Einheit heilen, Lebenszeichen
#   4. ein unterbrochenes Update (Marke „offen“, update.lock frei) zurückrollen –
#      mit der gesicherten alten Fassung, höchstens zweimal
#   5. Auftrag als App-Benutzer übernehmen (umbenennen, ≤ 1025 Byte lesen,
#      löschen) und streng prüfen; abgelehnt → storage/update/ist/lauf
#   6. deploy/update-debian.sh [--pruefen | --bis=<commit>] mit selbst gebautem
#      argv und env -i; Ausgabe in eine root-Datei (nie in ein Rohr) und
#      bereinigt ins Journal; TERM/INT/HUP gehen als TERM an das Update
#   7. Stand aus git (nur lesend), Lauf, Protokoll und Verlauf als App-Benutzer
#      nach storage/update/ist/
#
# Mit --nachlauf (ExecStopPost) trägt er nach, wenn der Helfer selbst starb
# oder das Zeitlimit des Dienstes griff. powermanagement-update.timer weckt ihn
# regelmäßig (Lebenszeichen, Stand, liegengebliebene Aufträge, Wiederanlauf).
#
# root arbeitet in storage/ nie selbst – nur über setpriv als App-Benutzer,
# so lenkt ihn dort kein Verweis. Eingaben werden nie ausgeführt: nur
# „while IFS= read -r“, kein eval, kein source, keine aus Eingaben gebauten
# sed- oder awk-Programme.
#
# Aufruf:
#   update-helfer.sh --ziel=DIR --app-benutzer=USER [--nachlauf]
#   update-helfer.sh --auftrag-pruefen [--jetzt=UNIX]
#       liest einen Auftrag von der Standardeingabe, prüft nur Form und Zeit
#       und schreibt „ok <aktion>“ oder „abgelehnt <code>“ (Abgleich mit PHP)
#   Prüf-/Testoptionen: --soll=DIR --ist=DIR --zustand=DIR --protokolle=DIR
#       --sperre=F --update-sperre=F --update-skript=F --update-conf=F
#       --arbeit-basis=DIR --jetzt=UNIX --ohne-eigentuemerpruefung --ohne-systemd
#       --app-benutzer=   (leer: Dateien in storage/ direkt, ohne setpriv)
#
# Exit 0 für jeden bearbeiteten Lauf – auch einen abgelehnten (fremde Eingaben
# machen den Dienst nicht „failed“). 1 nur bei eigenem Fehler (Code nicht
# root-eigen, setpriv fehlt, Aufruf falsch).

set -Eeuo pipefail
export LC_ALL=C
umask 077

trap 'echo "update-helfer.sh: unerwarteter Fehler (Zeile $LINENO)" >&2; exit 1' ERR

# ------------------------------------------------------------- Festwerte
KOPF_AUFTRAG='# powermanagement update-auftrag 1'
KOPF_LAUF='# powermanagement update-lauf 1'
KOPF_STAND='# powermanagement update-stand 1'
KOPF_PROTOKOLL='# powermanagement update-protokoll 1'
KOPF_VERLAUF='# powermanagement update-verlauf 1'
KOPF_HELFER='# powermanagement update-helfer 1'
KOPF_ERGEBNIS='# powermanagement update-ergebnis 1'
KOPF_OFFEN='# powermanagement update-offen 1'
SCHNITTSTELLE_MUSTER='^# update-debian-schnittstelle:.*(^| )1( |$)'

AUFTRAG_MAX=1024
ALTER_HAND=1800          # hand, konsole
ALTER_AUTOMATIK=600
ZUKUNFT_MAX=300
ABSTAND_AKTUALISIEREN=120
ABSTAND_AUTOMATIK=72000  # 20 h
ABSTAND_PRUEFEN=60
WIEDERAUFNAHMEN_MAX=2
WARTUNG_VERWAIST=600
KENNUNGEN_MAX=200
VERLAUF_ZEIGEN=100
PROTOKOLLE_MAX=30
PROTOKOLL_BYTES=65536
STAND_COMMITS=50
STAND_KANDIDATEN=100
STAND_HINWEISE=20
BETREFF_MAX=200

RE_KENNUNG='^[0-9a-f]{32}$'
RE_SHA='^[0-9a-f]{40}$'
RE_VON='^[A-Za-z0-9._@-]{1,64}$'
RE_ZEIT='^[0-9]{10}$'
RE_ZWEIG='^[A-Za-z0-9._/+-]{1,200}$'
RE_SICHERUNG='^vor-update-[0-9-]+\.tar\.gz$'
RE_PROTOKOLL='^[0-9]{8}-[0-9]{6}-[A-Za-z0-9-]+\.log$'

# Codes aus dem Vertrag (urteil §4.7) – Sätze dazu stehen nur in PHP.
ERGEBNISSE=" geprueft aktuell eingespielt eingespielt_mit_warnung nicht_begonnen zurueckgerollt unvollstaendig "
ERGEBNISSE_OK=" geprueft aktuell eingespielt eingespielt_mit_warnung "
GRUENDE=" besetzt offen lokal_geaendert abruf kein_zugang kein_zweig nicht_linear ziel_ungueltig platz sicherung
          abgebrochen checkout neue_fassung migration pakete einrichtung nginx dienste pruefung
          zeitueberschreitung unterbrochen intern "
GRUENDE="${GRUENDE//$'\n'/ }"
WARNUNGEN_BEKANNT=" kacheln pruefung flows_behalten site_behalten acl_behalten mqtt_helfer nachgeholt treiber "
# Gründe, die einen Stand als gescheitert vermerken (ein Signal ist kein Urteil über den Commit)
GESCHEITERT_GRUENDE=" checkout neue_fassung migration pakete einrichtung nginx dienste pruefung zeitueberschreitung "

# ------------------------------------------------------------- Vorgaben
ZIEL=""
APP_BENUTZER=""
APP_GESETZT="nein"
APP_UID=""
APP_GID=""
MODUS="lauf"
SOLL=""
IST=""
ZUSTAND="/var/lib/powermanagement/update"
PROTOKOLLE="/var/log/powermanagement/update"
SPERRE="/etc/powermanagement/update-helfer.lock"
UPDATE_SPERRE="/etc/powermanagement/update.lock"
UPDATE_SKRIPT=""
UPDATE_CONF="/etc/powermanagement/update.conf"
ARBEIT_BASIS="/run"
JETZT_FEST=""
EIGENTUEMER="ja"
OHNE_SYSTEMD="nein"

ARBEIT=""
OBERFLAECHE="ja"
AUTOMATIK="ja"
PFAD_ZUSTAND="ok"
OFFEN_ZUSTAND="nein"
WIEDER="nein"
ABGEBROCHEN="nein"
KIND=""
KIND_RC=0
LOG=""
GRUND=""
UEBERNAHME_GRUND=""

# Felder des laufenden Laufs (ist/lauf)
L_KENNUNG="-"; L_AKTION="-"; L_AUSLOESER="-"; L_VON="-"; L_ZUSTAND="laeuft"
L_ERGEBNIS="-"; L_GRUND="-"; L_SCHRITT="0 12 -"; L_BEGINN="0"; L_PULS="0"; L_ENDE="-"
L_ALT="-"; L_NEU="-"; L_ZIEL="-"; L_SICHERUNG="-"; L_PRUEFUNG="-"; L_WARNUNGEN=()
L_INVOCATION="-"; L_PROTOKOLL="-"

# Felder des Auftrags (nur gesetzt, was geprüft ist)
A_KENNUNG=""; A_AKTION=""; A_AUSLOESER=""; A_VON=""; A_ZEIT=""; A_ZIEL=""

aufruf_fehler() {
    echo "update-helfer.sh: $1" >&2
    exit 1
}

# ------------------------------------------------------------- Optionen
pfad_setzen() {   # pfad_setzen <variable> <wert> <option>
    if [[ "$2" != /* || "$2" == *$'\n'* ]]; then
        aufruf_fehler "$3 erwartet einen absoluten Pfad"
    fi
    printf -v "$1" '%s' "$2"
}

declare -A GESEHEN=()
einmal() {
    if [[ -n "${GESEHEN[$1]+x}" ]]; then
        aufruf_fehler "$1 mehrfach angegeben"
    fi
    GESEHEN[$1]=1
}

optionen() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            --ziel=*)           einmal --ziel; pfad_setzen ZIEL "${arg#*=}" --ziel; [[ "$ZIEL" == / ]] || ZIEL="${ZIEL%/}" ;;
            --app-benutzer=*)   einmal --app-benutzer; APP_BENUTZER="${arg#*=}"; APP_GESETZT="ja" ;;
            --nachlauf|--auftrag-pruefen)
                einmal "$arg"
                [[ "$MODUS" == "lauf" ]] || aufruf_fehler "--nachlauf und --auftrag-pruefen schließen sich aus"
                MODUS="${arg#--}" ;;
            --soll=*)           einmal --soll; pfad_setzen SOLL "${arg#*=}" --soll ;;
            --ist=*)            einmal --ist; pfad_setzen IST "${arg#*=}" --ist ;;
            --zustand=*)        einmal --zustand; pfad_setzen ZUSTAND "${arg#*=}" --zustand ;;
            --protokolle=*)     einmal --protokolle; pfad_setzen PROTOKOLLE "${arg#*=}" --protokolle ;;
            --sperre=*)         einmal --sperre; pfad_setzen SPERRE "${arg#*=}" --sperre ;;
            --update-sperre=*)  einmal --update-sperre; pfad_setzen UPDATE_SPERRE "${arg#*=}" --update-sperre ;;
            --update-skript=*)  einmal --update-skript; pfad_setzen UPDATE_SKRIPT "${arg#*=}" --update-skript ;;
            --update-conf=*)    einmal --update-conf; pfad_setzen UPDATE_CONF "${arg#*=}" --update-conf ;;
            --arbeit-basis=*)   einmal --arbeit-basis; pfad_setzen ARBEIT_BASIS "${arg#*=}" --arbeit-basis ;;
            --jetzt=*)
                einmal --jetzt
                JETZT_FEST="${arg#*=}"
                [[ "$JETZT_FEST" =~ ^[0-9]{1,12}$ ]] || aufruf_fehler "--jetzt erwartet Unix-Sekunden" ;;
            --ohne-eigentuemerpruefung) einmal "$arg"; EIGENTUEMER="nein" ;;
            --ohne-systemd)     einmal "$arg"; OHNE_SYSTEMD="ja" ;;
            *)                  aufruf_fehler "unbekannte Option: ${arg%%=*}" ;;
        esac
    done
    [[ "$MODUS" == "auftrag-pruefen" ]] && return 0
    [[ -n "$ZIEL" ]] || aufruf_fehler "--ziel=DIR fehlt"
    [[ "$APP_GESETZT" == "ja" ]] || aufruf_fehler "--app-benutzer=USER fehlt (leer erlaubt)"
    [[ -n "$SOLL" ]] || SOLL="$ZIEL/storage/update/soll"
    [[ -n "$IST" ]] || IST="$ZIEL/storage/update/ist"
    [[ -n "$UPDATE_SKRIPT" ]] || UPDATE_SKRIPT="$ZIEL/deploy/update-debian.sh"
    return 0
}

benutzer_aufloesen() {
    [[ -n "$APP_BENUTZER" ]] || return 0
    [[ "$APP_BENUTZER" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]*$ ]] || aufruf_fehler "--app-benutzer: ungültiger Name"
    APP_UID="$(id -u -- "$APP_BENUTZER" 2>/dev/null || true)"
    APP_GID="$(id -g -- "$APP_BENUTZER" 2>/dev/null || true)"
    [[ "$APP_UID" =~ ^[0-9]+$ && "$APP_GID" =~ ^[0-9]+$ ]] || aufruf_fehler "App-Benutzer $APP_BENUTZER gibt es nicht"
    [[ "$APP_UID" != 0 ]] || aufruf_fehler "der App-Benutzer darf nicht root sein"
    command -v setpriv >/dev/null 2>&1 || aufruf_fehler "setpriv fehlt (Paket util-linux)"
}

# ------------------------------------------------------------- Grundlagen
jetzt() {
    if [[ -n "$JETZT_FEST" ]]; then
        printf '%s' "$JETZT_FEST"
    else
        printf '%(%s)T' -1
    fi
}

zufalls_kennung() {
    local k
    k="$(od -An -N16 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n' || true)"
    [[ "$k" =~ $RE_KENNUNG ]] || k="$(printf '%08x%08x%08x%08x' "$RANDOM$RANDOM" "$$" "$RANDOM$RANDOM" "$(jetzt)")"
    printf '%s' "${k:0:32}"
}

kurz() { if [[ "$1" =~ ^[0-9a-f]{7} ]]; then printf '%s' "${1:0:7}"; else printf '%s' "-"; fi; }

# Text für eine Zeile: ohne Steuerzeichen, höchstens <max> Byte (UTF-8 bleibt;
# ein abgeschnittenes Zeichen am Ende räumt iconv bzw. PHP mb_scrub weg).
zeile_bereinigen() {   # zeile_bereinigen <text> <max>
    local t="${1//[[:cntrl:]]/}"   # LC_ALL=C: 0x00–0x1F und 0x7F
    printf '%s' "${t:0:$2}"
}

# Als root läuft hier Code aus dem Anwendungsverzeichnis. Könnte die
# Anwendung (www-data) ihn verändern, wäre das ein Weg zu root.
root_eigen() {
    local info uid modus
    info="$(stat -L -c '%u %a' -- "$1" 2>/dev/null || true)"
    [[ -n "$info" ]] || return 1
    uid="${info%% *}"
    modus="${info#* }"
    [[ "$uid" == 0 ]] || return 1
    (( (8#$modus & 8#022) == 0 ))
}

# deploy/* landet als root in /etc/systemd/system, Hooks und core.* in
# .git/config liefen bei fetch und checkout als root, bin/power ruft das
# Update als App-Benutzer – nichts davon darf www-data gehören oder für
# andere schreibbar sein, und keine Verweise.
code_pruefen() {
    local skript p befund liste
    skript="$(readlink -f -- "${BASH_SOURCE[0]}")"
    for p in "$skript" "${skript%/*}" "$ZIEL" "$ZIEL/.git" "$ZIEL/bin" "$UPDATE_SKRIPT"; do
        root_eigen "$p" || aufruf_fehler "Code nicht root-eigen oder für andere schreibbar: $p"
    done
    liste=("$ZIEL/deploy" "$ZIEL/.git/config" "$ZIEL/bin/power")
    if [[ -e "$ZIEL/.git/hooks" || -L "$ZIEL/.git/hooks" ]]; then
        liste+=("$ZIEL/.git/hooks")
    fi
    befund="$(find -P "${liste[@]}" \( -type l -o ! -user root -o -perm /022 \) -print -quit 2>&1 || echo "find scheiterte")"
    [[ -z "$befund" ]] || aufruf_fehler "Code nicht root-eigen, für andere schreibbar oder ein Verweis: ${befund%%$'\n'*}"
}

# Als App-Benutzer ausführen. Die Sperren (fd 8, fd 9) gehen nie mit.
als_app() {
    if [[ -z "$APP_BENUTZER" ]]; then
        "$@" 8>&- 9>&-
    else
        setpriv --reuid="$APP_UID" --regid="$APP_GID" --init-groups -- "$@" 8>&- 9>&-
    fi
}

# Eine Datei als App-Benutzer schreiben (Inhalt von stdin): Temp-Datei im
# selben Ordner, 640, dann umbenennen. In storage/ gehört so nichts root.
als_app_schreiben() {   # als_app_schreiben <ziel> < quelle
    als_app /bin/sh -c 'umask 027; t=$(mktemp "${1%/*}/.neu.XXXXXX") || exit 1
        if cat > "$t" && chmod 640 "$t" && mv -f -- "$t" "$1"; then exit 0; fi
        rm -f -- "$t"; exit 1' sh "$1" 2>/dev/null
}

# Eine Datei nur für root (600) – Temp-Datei im selben Ordner, umbenennen.
root_schreiben() {   # root_schreiben <ziel> < quelle
    local t
    t="$(mktemp "${1%/*}/.neu.XXXXXX" 2>/dev/null || true)"
    [[ -n "$t" ]] || return 1
    if cat > "$t" && chmod 600 -- "$t" && mv -f -- "$t" "$1"; then
        return 0
    fi
    rm -f -- "$t"
    return 1
}

# git nur lesend: kein Hook, keine optionale Sperre (status schriebe sonst den
# Index neu), safe.directory nur für diesen Aufruf.
gl() {
    git -c safe.directory="$ZIEL" -c core.hooksPath=/dev/null --no-optional-locks -C "$ZIEL" "$@"
}

hat_schnittstelle() {
    local zeile n=0
    [[ -f "$1" && -r "$1" ]] || return 1
    while IFS= read -r zeile && (( ++n <= 10 )); do
        [[ "$zeile" =~ $SCHNITTSTELLE_MUSTER ]] && return 0
    done < "$1"
    return 1
}

# Ist update.lock frei? Die Unterschale gibt die Sperre sofort wieder frei –
# der Helfer hält sie nie (sonst meldete das Update selbst „besetzt“).
update_sperre_frei() {
    [[ -d "${UPDATE_SPERRE%/*}" ]] || return 0
    ( flock -n 9 ) 9>>"$UPDATE_SPERRE" 2>/dev/null
}

# Nur über den EXIT-Trap erreichbar:
# shellcheck disable=SC2317
aufraeumen() {
    if [[ -n "$ARBEIT" && "$ARBEIT" == "$ARBEIT_BASIS/powermanagement-update."* ]]; then
        rm -rf -- "$ARBEIT"
    fi
    return 0
}

arbeit_anlegen() {
    [[ -d "$ARBEIT_BASIS" ]] || aufruf_fehler "Arbeitsordner $ARBEIT_BASIS fehlt"
    ARBEIT="$(mktemp -d "$ARBEIT_BASIS/powermanagement-update.XXXXXX" 2>/dev/null || true)"
    [[ -n "$ARBEIT" && -d "$ARBEIT" ]] || aufruf_fehler "Arbeitsordner in $ARBEIT_BASIS ließ sich nicht anlegen"
    chmod 700 -- "$ARBEIT"
    trap aufraeumen EXIT
}

# Arbeitsordner früherer Läufe, die nicht aufräumen konnten (SIGKILL, Strom
# weg) – nur bei freier update.lock: dann schreibt kein Update mehr hinein.
arbeit_reste_raeumen() {
    local d
    update_sperre_frei || return 0
    for d in "$ARBEIT_BASIS"/powermanagement-update.*; do
        [[ -d "$d" && ! -L "$d" && "$d" != "$ARBEIT" && "${d##*/}" =~ ^powermanagement-update\.[A-Za-z0-9]{6}$ ]] || continue
        rm -rf -- "$d"
    done
    return 0
}

zustand_anlegen() {
    if [[ ! -d "$ZUSTAND" ]]; then
        install -d -m 700 -- "$ZUSTAND" 2>/dev/null || aufruf_fehler "$ZUSTAND ließ sich nicht anlegen"
    fi
    if [[ ! -d "$PROTOKOLLE" ]]; then
        install -d -m 700 -- "$PROTOKOLLE" 2>/dev/null || aufruf_fehler "$PROTOKOLLE ließ sich nicht anlegen"
    fi
}

# ------------------------------------------------------------- update.conf
# OBERFLAECHE=ja|nein, AUTOMATIK=ja|nein – gelesen, nie per source. Was sich
# nicht lesen lässt oder einen anderen Wert trägt, schaltet ab (lieber kein
# Update als eines, das der Betreiber abgeschaltet hatte).
conf_lesen() {
    local inhalt zeile
    OBERFLAECHE="ja"
    AUTOMATIK="ja"
    [[ -e "$UPDATE_CONF" || -L "$UPDATE_CONF" ]] || return 0
    if [[ ! -f "$UPDATE_CONF" || -L "$UPDATE_CONF" || ! -r "$UPDATE_CONF" ]] \
        || { [[ $EUID -eq 0 && "$EIGENTUEMER" == "ja" ]] && ! root_eigen "$UPDATE_CONF"; }; then
        echo "update-helfer.sh: $UPDATE_CONF lässt sich nicht sicher lesen – Updates aus der Oberfläche bleiben abgeschaltet." >&2
        OBERFLAECHE="nein"
        AUTOMATIK="nein"
        return 0
    fi
    inhalt="$(head -c 4096 -- "$UPDATE_CONF" 2>/dev/null || true)"
    while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        zeile="${zeile%$'\r'}"
        zeile="${zeile#"${zeile%%[![:space:]]*}"}"
        zeile="${zeile%"${zeile##*[![:space:]]}"}"
        [[ -z "$zeile" || "$zeile" == \#* ]] && continue
        if [[ "$zeile" =~ ^(OBERFLAECHE|AUTOMATIK)[[:space:]]*=[[:space:]]*\"?(ja|nein)\"?$ ]]; then
            printf -v "${BASH_REMATCH[1]}" '%s' "${BASH_REMATCH[2]}"
        elif [[ "$zeile" =~ ^(OBERFLAECHE|AUTOMATIK)[[:space:]]*= ]]; then
            echo "update-helfer.sh: $UPDATE_CONF: ${BASH_REMATCH[1]} erwartet ja oder nein – gilt als nein." >&2
            printf -v "${BASH_REMATCH[1]}" '%s' "nein"
        fi
    done <<< "$inhalt"
    return 0
}

# ------------------------------------------------------------- Pfad-Einheit, Lebenszeichen
# Ist soll/ ein echter Ordner (als App-Benutzer gesehen)? Sonst wird die
# Pfad-Einheit nicht geheilt – ein böswilliger Verweis schaukelte sonst alle
# 15 Minuten 20 Starts auf.
pfad_heilen() {
    PFAD_ZUSTAND="ok"
    if ! als_app /bin/sh -c '[ -d "$1" ] && [ ! -L "$1" ]' sh "$SOLL" 2>/dev/null; then
        PFAD_ZUSTAND="soll_ungueltig"
        return 0
    fi
    [[ "$OHNE_SYSTEMD" == "nein" ]] || return 0
    if systemctl is-failed --quiet powermanagement-update.path 2>/dev/null; then
        systemctl reset-failed powermanagement-update.path powermanagement-update.service >/dev/null 2>&1 || true
        if systemctl restart powermanagement-update.path >/dev/null 2>&1; then
            PFAD_ZUSTAND="repariert"
            echo "update-helfer.sh: powermanagement-update.path war ausgefallen – wieder gestartet."
        fi
    fi
    return 0
}

# marke_wert <schlüssel>: ein Wert aus der Marke (Kopf und Form, kein source)
marke_wert() {
    local zeile n=0
    [[ -f "$ZUSTAND/offen" && ! -L "$ZUSTAND/offen" ]] || return 0
    while IFS= read -r zeile && (( ++n <= 200 )); do
        if (( n == 1 )); then
            [[ "$zeile" == "$KOPF_OFFEN" ]] || return 0
            continue
        fi
        if [[ "$zeile" =~ ^([a-z_]+)\ ([ -~]+)$ && "${BASH_REMATCH[1]}" == "$1" ]]; then
            printf '%s' "${BASH_REMATCH[2]}"
            return 0
        fi
    done < <(head -c 16384 -- "$ZUSTAND/offen" 2>/dev/null || true)
    return 0
}

# Wiederaufnahmen der jetzigen Marke: WA_ZEIT WA_VERSUCHE WA_GEMELDET
wiederaufnahme_lesen() {
    local z="" v="" g="" marke_zeit
    WA_VERSUCHE=0
    WA_GEMELDET="nein"
    marke_zeit="$(marke_wert zeit)"
    [[ "$marke_zeit" =~ ^[0-9]{1,12}$ ]] || marke_zeit="-"
    WA_ZEIT="$marke_zeit"
    if [[ -f "$ZUSTAND/wiederaufnahme" ]]; then
        read -r z v g < "$ZUSTAND/wiederaufnahme" || true
        if [[ "$z" == "$marke_zeit" && "$v" =~ ^[0-9]{1,3}$ ]]; then
            WA_VERSUCHE=$((10#$v))
            [[ "$g" == "gemeldet" ]] && WA_GEMELDET="ja"
        fi
    fi
    return 0
}

offen_bestimmen() {
    OFFEN_ZUSTAND="nein"
    [[ -f "$ZUSTAND/offen" ]] || return 0
    OFFEN_ZUSTAND="ja"
    wiederaufnahme_lesen
    (( WA_VERSUCHE >= WIEDERAUFNAHMEN_MAX )) && OFFEN_ZUSTAND="aufgegeben"
    return 0
}

lebenszeichen() {
    local fassung
    offen_bestimmen
    fassung="$(gl rev-parse -q --verify 'HEAD^{commit}' 2>/dev/null || true)"
    [[ "$fassung" =~ $RE_SHA ]] || fassung="-"
    {
        echo "$KOPF_HELFER"
        echo "zeit $(jetzt)"
        echo "fassung $fassung"
        echo "oberflaeche $OBERFLAECHE"
        echo "automatik $AUTOMATIK"
        echo "offen $OFFEN_ZUSTAND"
        echo "pfad $PFAD_ZUSTAND"
    } > "$ARBEIT/helfer"
    als_app_schreiben "$IST/helfer" < "$ARBEIT/helfer" \
        || echo "update-helfer.sh: $IST/helfer ließ sich nicht schreiben (Update-Funktion eingerichtet?)" >&2
    return 0
}

# ------------------------------------------------------------- Lauf (ist/lauf)
lauf_text() {
    local w
    echo "$KOPF_LAUF"
    echo "kennung $L_KENNUNG"
    echo "aktion $L_AKTION"
    echo "ausloeser $L_AUSLOESER"
    echo "von $L_VON"
    echo "zustand $L_ZUSTAND"
    echo "ergebnis $L_ERGEBNIS"
    echo "grund $L_GRUND"
    echo "schritt $L_SCHRITT"
    echo "beginn $L_BEGINN"
    echo "puls $L_PULS"
    echo "ende $L_ENDE"
    echo "alt $L_ALT"
    echo "neu $L_NEU"
    echo "ziel $L_ZIEL"
    echo "sicherung $L_SICHERUNG"
    echo "pruefung $L_PRUEFUNG"
    for w in ${L_WARNUNGEN[@]+"${L_WARNUNGEN[@]}"}; do
        echo "warnung $w"
    done
    echo "invocation $L_INVOCATION"
    echo "protokoll $L_PROTOKOLL"
}

# lauf_schreiben [nur_app]: ist/lauf als App-Benutzer, dazu die root-Kopie
# (für den Nachlauf – die kann www-data nicht verändern).
lauf_schreiben() {
    lauf_text > "$ARBEIT/lauf"
    if [[ "${1:-}" != "nur_app" ]]; then
        root_schreiben "$ZUSTAND/lauf" < "$ARBEIT/lauf" || echo "update-helfer.sh: $ZUSTAND/lauf ließ sich nicht schreiben" >&2
    fi
    als_app_schreiben "$IST/lauf" < "$ARBEIT/lauf" || true
    return 0
}

kopf_lesen() {
    local k
    k="$(gl rev-parse -q --verify 'HEAD^{commit}' 2>/dev/null || true)"
    [[ "$k" =~ $RE_SHA ]] || k="-"
    printf '%s' "$k"
}

# Einen neuen Lauf beginnen: Felder aus dem geprüften Auftrag
lauf_beginnen() {   # lauf_beginnen <kennung> <aktion> <ausloeser> <von> <ziel>
    local inv="${INVOCATION_ID:-}"
    L_KENNUNG="$1"; L_AKTION="$2"; L_AUSLOESER="$3"; L_VON="$4"; L_ZIEL="$5"
    L_ZUSTAND="laeuft"; L_ERGEBNIS="-"; L_GRUND="-"; L_SCHRITT="0 12 Auftrag angenommen"
    L_BEGINN="$(jetzt)"; L_PULS="$L_BEGINN"; L_ENDE="-"
    L_ALT="$(kopf_lesen)"; L_NEU="-"; L_SICHERUNG="-"; L_PRUEFUNG="-"; L_WARNUNGEN=()
    [[ "$inv" =~ ^[0-9a-f]{32}$ ]] || inv="-"
    L_INVOCATION="$inv"
    L_PROTOKOLL="-"
}

lauf_ende() {   # lauf_ende <zustand> <ergebnis> <grund>
    L_ZUSTAND="$1"; L_ERGEBNIS="$2"; L_GRUND="${3:--}"
    L_ENDE="$(jetzt)"; L_PULS="$L_ENDE"
    lauf_schreiben
    local grund="" neu="$L_NEU"
    [[ "$L_GRUND" != "-" ]] && grund=" ($L_GRUND)"
    [[ "$neu" == "-" ]] && neu="$L_ZIEL"
    echo "Auftrag ${L_KENNUNG:0:8} $L_AKTION: $L_ERGEBNIS$grund $(kurz "$L_ALT") → $(kurz "$neu")"
}

# Abgelehnt: der Lauf zeigt die Kennung des Auftrags (sofern lesbar), damit
# die Anwendung ihre Ablehnung findet.
lauf_abgelehnt() {   # lauf_abgelehnt <code>
    local k="${V_KENNUNG:-}"
    [[ "$k" =~ $RE_KENNUNG ]] || k="$(zufalls_kennung)"
    lauf_beginnen "$k" "${V_AKTION:--}" "${V_AUSLOESER:--}" "${V_VON:--}" "${V_ZIEL:--}"
    L_SCHRITT="0 12 -"
    lauf_ende fehler abgelehnt "$1"
}

# ------------------------------------------------------------- Zustand root-seitig
# zeit_wert <art>: Zeitpunkt aus „zeiten“ (pruefen | aktualisieren | automatik)
zeit_wert() {
    local art wert rest
    [[ -f "$ZUSTAND/zeiten" ]] || return 0
    while read -r art wert rest; do
        if [[ "$art" == "$1" && "$wert" =~ ^[0-9]{1,12}$ ]]; then
            printf '%s' "$wert"
            return 0
        fi
    done < "$ZUSTAND/zeiten"
    return 0
}
zeit_status() {   # Status des letzten Abrufs (ok | abruf | kein_zugang)
    local art wert rest
    [[ -f "$ZUSTAND/zeiten" ]] || return 0
    while read -r art wert rest; do
        if [[ "$art" == "pruefen" && "$rest" =~ ^(ok|abruf|kein_zugang)$ ]]; then
            printf '%s' "$rest"
            return 0
        fi
    done < "$ZUSTAND/zeiten"
    return 0
}
zeit_setzen() {   # zeit_setzen <art> <unix> [<status>]
    local art wert rest
    {
        if [[ -f "$ZUSTAND/zeiten" ]]; then
            while read -r art wert rest; do
                [[ "$art" =~ ^(pruefen|aktualisieren|automatik)$ && "$art" != "$1" ]] || continue
                printf '%s %s%s\n' "$art" "$wert" "${rest:+ $rest}"
            done < "$ZUSTAND/zeiten"
        fi
        printf '%s %s%s\n' "$1" "$2" "${3:+ $3}"
    } > "$ARBEIT/zeiten"
    root_schreiben "$ZUSTAND/zeiten" < "$ARBEIT/zeiten" || true
}

kennung_bekannt() {
    [[ -f "$ZUSTAND/kennungen" ]] || return 1
    grep -qE "^$1 " -- "$ZUSTAND/kennungen"
}

# VOR dem Ausführen: dieselbe Kennung läuft nie zweimal.
kennung_merken() {
    {
        if [[ -f "$ZUSTAND/kennungen" ]]; then
            grep -E '^[0-9a-f]{32} [0-9]+$' -- "$ZUSTAND/kennungen" | tail -n $((KENNUNGEN_MAX - 1)) || true
        fi
        printf '%s %s\n' "$A_KENNUNG" "$(jetzt)"
    } > "$ARBEIT/kennungen"
    root_schreiben "$ZUSTAND/kennungen" < "$ARBEIT/kennungen" \
        || aufruf_fehler "$ZUSTAND/kennungen ließ sich nicht schreiben"
}

ist_gescheitert() {   # ist_gescheitert <sha40>
    [[ -f "$ZUSTAND/gescheitert" ]] || return 1
    grep -qE "^$1 " -- "$ZUSTAND/gescheitert"
}

# Einen gescheiterten Stand vermerken (die Automatik versucht ihn nie wieder),
# falls update-debian.sh das nicht schon selbst getan hat.
gescheitert_merken() {   # gescheitert_merken <sha40> <grund> <seit>
    local sha="$1" grund="$2" seit="$3" s z rest
    [[ "$sha" =~ $RE_SHA && "$GESCHEITERT_GRUENDE" == *" $grund "* ]] || return 0
    if [[ -f "$ZUSTAND/gescheitert" ]]; then
        while read -r s z rest; do
            [[ "$s" == "$sha" && "$z" =~ ^[0-9]{1,12}$ ]] && (( 10#$z >= seit )) && return 0
        done < "$ZUSTAND/gescheitert"
    fi
    {
        if [[ -f "$ZUSTAND/gescheitert" ]]; then
            grep -E '^[0-9a-f]{40} [0-9]+ [a-z_]+$' -- "$ZUSTAND/gescheitert" | tail -n 19 || true
        fi
        printf '%s %s %s\n' "$sha" "$(jetzt)" "$grund"
    } > "$ARBEIT/gescheitert"
    root_schreiben "$ZUSTAND/gescheitert" < "$ARBEIT/gescheitert" || true
    return 0
}

# ------------------------------------------------------------- Auftrag
# Übernehmen in einem Aufruf als App-Benutzer: umbenennen (ein neuer Auftrag
# landet wieder unter „auftrag“), nur eine echte Datei lesen (höchstens
# 1025 Byte, timeout gegen eine untergeschobene FIFO), dann löschen. root
# liest danach nur noch die eigene Kopie.
#   rc 0 gelesen, 3 kein Auftrag (oder kein soll/), sonst abgelehnt (UEBERNAHME_GRUND)
auftrag_uebernehmen() {
    local rc=0
    : > "$ARBEIT/auftrag"
    als_app /bin/sh -c '
        cd -- "$1" 2>/dev/null || exit 6
        [ -e auftrag ] || [ -L auftrag ] || exit 3
        mv -f -- auftrag ".uebernommen-$2" 2>/dev/null || exit 4
        if [ -f ".uebernommen-$2" ] && [ ! -L ".uebernommen-$2" ]; then
            timeout -s KILL 10 head -c "$3" -- ".uebernommen-$2"; rc=$?
        else
            rc=5
        fi
        rm -rf -- ".uebernommen-$2"
        exit $rc' sh "$SOLL" "$$" "$((AUFTRAG_MAX + 1))" > "$ARBEIT/auftrag" 2>/dev/null || rc=$?
    case "$rc" in
        0) return 0 ;;
        # soll/ fehlt oder ist nicht zu betreten: dann liegt dort auch kein
        # Auftrag (ist/helfer meldet „pfad soll_ungueltig“).
        3|6) return 3 ;;
        4) UEBERNAHME_GRUND="soll_ungueltig" ;;
        *) UEBERNAHME_GRUND="keine_datei" ;;
    esac
    : > "$ARBEIT/auftrag"
    return 1
}

# Für die Anzeige einer Ablehnung: was sich trotz eines Fehlers erkennen lässt
# (je Feld nur ein Wert, der seine Regel erfüllt).
vorab_lesen() {
    local f="$1"
    V_KENNUNG="$(grep -a -m1 -oE '^kennung [0-9a-f]{32}$' -- "$f" 2>/dev/null | cut -d' ' -f2 || true)"
    V_AKTION="$(grep -a -m1 -oE '^aktion (pruefen|aktualisieren)$' -- "$f" 2>/dev/null | cut -d' ' -f2 || true)"
    V_AUSLOESER="$(grep -a -m1 -oE '^ausloeser (hand|automatik|konsole)$' -- "$f" 2>/dev/null | cut -d' ' -f2 || true)"
    V_VON="$(grep -a -m1 -oE '^von [A-Za-z0-9._@-]{1,64}$' -- "$f" 2>/dev/null | cut -d' ' -f2 || true)"
    V_ZIEL="$(grep -a -m1 -oE '^ziel [0-9a-f]{40}$' -- "$f" 2>/dev/null | cut -d' ' -f2 || true)"
    return 0
}

# Form (alles oder nichts): setzt A_*; rc 1 mit GRUND
auftrag_syntax_pruefen() {   # auftrag_syntax_pruefen <datei>
    local f="$1" groesse rest letztes zeile n=0 schluessel wert
    local -A gesehen=()
    A_KENNUNG=""; A_AKTION=""; A_AUSLOESER=""; A_VON=""; A_ZEIT=""; A_ZIEL=""
    groesse="$(stat -c %s -- "$f" 2>/dev/null || echo 0)"
    if (( groesse > AUFTRAG_MAX )); then GRUND="groesse"; return 1; fi
    if (( groesse == 0 )); then GRUND="format"; return 1; fi
    rest="$(tr -d '\n\040-\176' < "$f" | wc -c || echo 1)"
    if (( rest > 0 )); then GRUND="zeichen"; return 1; fi
    letztes="$(tail -c 1 -- "$f" | od -An -tx1 | tr -d ' \n' || true)"
    if [[ "$letztes" != "0a" ]]; then GRUND="format"; return 1; fi
    while IFS= read -r zeile; do
        n=$((n + 1))
        if (( n == 1 )); then
            if [[ "$zeile" != "$KOPF_AUFTRAG" ]]; then GRUND="kopf"; return 1; fi
            continue
        fi
        if [[ ! "$zeile" =~ ^([a-z_]+)\ (.+)$ ]]; then GRUND="format"; return 1; fi
        schluessel="${BASH_REMATCH[1]}"
        wert="${BASH_REMATCH[2]}"
        case "$schluessel" in
            kennung|aktion|ausloeser|von|zeit|ziel) ;;
            *) GRUND="schluessel"; return 1 ;;
        esac
        if [[ -n "${gesehen[$schluessel]+x}" ]]; then GRUND="doppelt_schluessel"; return 1; fi
        gesehen[$schluessel]=1
        case "$schluessel" in
            kennung)   [[ "$wert" =~ $RE_KENNUNG ]] || { GRUND="wert"; return 1; }; A_KENNUNG="$wert" ;;
            aktion)    [[ "$wert" =~ ^(pruefen|aktualisieren)$ ]] || { GRUND="wert"; return 1; }; A_AKTION="$wert" ;;
            ausloeser) [[ "$wert" =~ ^(hand|automatik|konsole)$ ]] || { GRUND="wert"; return 1; }; A_AUSLOESER="$wert" ;;
            von)       [[ "$wert" =~ $RE_VON ]] || { GRUND="wert"; return 1; }; A_VON="$wert" ;;
            zeit)      [[ "$wert" =~ $RE_ZEIT ]] || { GRUND="wert"; return 1; }; A_ZEIT="$wert" ;;
            ziel)      [[ "$wert" =~ $RE_SHA ]] || { GRUND="wert"; return 1; }; A_ZIEL="$wert" ;;
        esac
    done < "$f"
    if [[ -z "$A_KENNUNG" || -z "$A_AKTION" || -z "$A_AUSLOESER" || -z "$A_VON" || -z "$A_ZEIT" ]]; then
        GRUND="fehlt"; return 1
    fi
    if [[ "$A_AKTION" == "pruefen" && -n "$A_ZIEL" ]]; then GRUND="verboten"; return 1; fi
    if [[ "$A_AKTION" == "aktualisieren" && -z "$A_ZIEL" ]]; then GRUND="fehlt"; return 1; fi
    return 0
}

auftrag_zeit_pruefen() {
    local alter grenze="$ALTER_HAND"
    [[ "$A_AUSLOESER" == "automatik" ]] && grenze="$ALTER_AUTOMATIK"
    alter=$(( $(jetzt) - 10#$A_ZEIT ))
    if (( alter > grenze )); then GRUND="veraltet"; return 1; fi
    if (( -alter > ZUKUNFT_MAX )); then GRUND="zukunft"; return 1; fi
    return 0
}

# abstand_unter <art> <sekunden>: liegt das letzte Ereignis dieser Art näher?
# (Ein Zeitpunkt in der Zukunft – Uhr verstellt – zählt nicht.)
abstand_unter() {
    local letzte d
    letzte="$(zeit_wert "$1")"
    [[ -n "$letzte" ]] || return 1
    d=$(( $(jetzt) - 10#$letzte ))
    (( d >= 0 && d < $2 ))
}

# Alles prüfen: Form, Zeit, Kennung, update.conf, Abstände (urteil §5.3)
auftrag_pruefen() {
    auftrag_syntax_pruefen "$ARBEIT/auftrag" || return 1
    auftrag_zeit_pruefen || return 1
    if kennung_bekannt "$A_KENNUNG"; then GRUND="doppelt"; return 1; fi
    if [[ "$OBERFLAECHE" == "nein" ]]; then GRUND="abgeschaltet"; return 1; fi
    if [[ "$AUTOMATIK" == "nein" && "$A_AUSLOESER" == "automatik" && "$A_AKTION" == "aktualisieren" ]]; then
        GRUND="abgeschaltet"; return 1
    fi
    if [[ "$A_AKTION" == "aktualisieren" ]]; then
        if [[ -e "$ZUSTAND/offen" ]]; then GRUND="offen"; return 1; fi
        if [[ "$WIEDER" == "ja" ]]; then GRUND="wiederhergestellt"; return 1; fi
        if abstand_unter aktualisieren "$ABSTAND_AKTUALISIEREN"; then GRUND="zu_oft"; return 1; fi
        if [[ "$A_AUSLOESER" == "automatik" ]]; then
            if ist_gescheitert "$A_ZIEL"; then GRUND="gescheitert_vorher"; return 1; fi
            if abstand_unter automatik "$ABSTAND_AUTOMATIK"; then GRUND="zu_oft"; return 1; fi
        fi
    fi
    return 0
}

# --auftrag-pruefen: nur Form und Zeit, ohne Zustand (Abgleich mit PHP)
auftrag_nur_pruefen() {
    local t
    t="$(mktemp "${TMPDIR:-/tmp}/pm-auftrag.XXXXXX")"
    head -c "$((AUFTRAG_MAX + 1))" > "$t" || true
    GRUND=""
    if auftrag_syntax_pruefen "$t" && auftrag_zeit_pruefen; then
        echo "ok $A_AKTION"
    else
        echo "abgelehnt $GRUND"
    fi
    rm -f -- "$t"
    return 0
}

# ------------------------------------------------------------- Protokoll
# Bereinigt für die Anwendung: ohne Farben (ESC), ohne CR und Steuerzeichen,
# Zugangsdaten in Adressen und Token-Muster geschwärzt; UTF-8 und TAB bleiben.
bereinigen() {
    sed -E -e 's/\x1b\[[0-9;?]*[ -\/]*[@-~]//g' \
        -e 's#(://)[^/@[:space:]]+@#\1***@#g' \
        -e 's/(gh[pousr]_|github_pat_)[A-Za-z0-9_]{10,}/\1***/g' \
        | tr -d '\000-\010\013-\037\177'
}
# Dasselbe als Strom fürs Journal (zeilenweise, ohne Puffer)
bereinigen_strom() {
    sed -u -E -e 's/\x1b\[[0-9;?]*[ -\/]*[@-~]//g' \
        -e 's#(://)[^/@[:space:]]+@#\1***@#g' \
        -e 's/(gh[pousr]_|github_pat_)[A-Za-z0-9_]{10,}/\1***/g' \
        -e 's/[\x00-\x08\x0b-\x1f\x7f]//g'
}
utf8_bereinigen() {
    if command -v iconv >/dev/null 2>&1; then
        iconv -c -f UTF-8 -t UTF-8 2>/dev/null || true
    else
        cat
    fi
}

protokoll_kopieren() {
    [[ -n "$LOG" && -f "$LOG" ]] || return 0
    {
        echo "$KOPF_PROTOKOLL"
        echo "kennung $L_KENNUNG"
        echo
        tail -c "$PROTOKOLL_BYTES" -- "$LOG" | bereinigen | utf8_bereinigen
    } > "$ARBEIT/protokoll" 2>/dev/null || true
    als_app_schreiben "$IST/protokoll" < "$ARBEIT/protokoll" || true
    return 0
}

protokoll_anlegen() {
    L_PROTOKOLL="$(date -u -d "@$(jetzt)" +%Y%m%d-%H%M%S)-${L_KENNUNG:0:8}.log"
    LOG="$PROTOKOLLE/$L_PROTOKOLL"
    install -m 600 /dev/null "$LOG"
}

protokolle_aufraeumen() {
    local alle=() i
    mapfile -t alle < <(find "$PROTOKOLLE" -mindepth 1 -maxdepth 1 -type f -name '*.log' -printf '%f\n' 2>/dev/null \
        | grep -E "$RE_PROTOKOLL" | sort || true)
    for (( i = 0; i < ${#alle[@]} - PROTOKOLLE_MAX; i++ )); do
        rm -f -- "${PROTOKOLLE:?}/${alle[i]}"
    done
    return 0
}

verlauf_spiegeln() {
    {
        echo "$KOPF_VERLAUF"
        if [[ -f "$ZUSTAND/verlauf" ]]; then
            grep -aE '^eintrag [ -~]+$' -- "$ZUSTAND/verlauf" | tail -n "$VERLAUF_ZEIGEN" || true
        fi
    } > "$ARBEIT/verlauf"
    als_app_schreiben "$IST/verlauf" < "$ARBEIT/verlauf" || true
    return 0
}

# ist/wartung ohne Marke bei freier update.lock und älter als 10 min: ein
# Update starb, bevor es die Marke schrieb – die Schreibsperre der Oberfläche
# hebt dann niemand mehr auf.
wartung_verwaist_raeumen() {
    local inhalt seit=""
    [[ ! -e "$ZUSTAND/offen" ]] || return 0
    inhalt="$(als_app /bin/sh -c '[ -f "$1" ] && [ ! -L "$1" ] && exec head -c 512 -- "$1"' sh "$IST/wartung" 2>/dev/null || true)"
    [[ -n "$inhalt" ]] || return 0
    update_sperre_frei || return 0
    if [[ "$inhalt" =~ (^|$'\n')seit\ ([0-9]{1,12})($'\n'|$) ]]; then
        seit="${BASH_REMATCH[2]}"
        (( $(jetzt) - 10#$seit > WARTUNG_VERWAIST )) || return 0
    fi
    if als_app rm -f -- "$IST/wartung" 2>/dev/null; then
        echo "update-helfer.sh: verwaiste Schreibsperre der Oberfläche aufgehoben."
    fi
    return 0
}

# ------------------------------------------------------------- Ergebnis
# Die Ergebnisdatei von update-debian.sh streng lesen: Kopf, Form, Werte gegen
# die Codelisten. Unbekannte Schlüssel einer neueren Fassung werden überlesen.
ergebnis_lesen() {   # setzt E_*; rc 1, wenn ungültig
    local f="$1" zeile n=0 schluessel wert
    E_ERGEBNIS=""; E_GRUND="-"; E_ALT=""; E_NEU="-"; E_SICHERUNG="-"; E_PRUEFUNG="-"; E_WARNUNGEN=()
    [[ -f "$f" && ! -L "$f" ]] || return 1
    (( $(stat -c %s -- "$f" 2>/dev/null || echo 99999) <= 4096 )) || return 1
    while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        n=$((n + 1))
        if (( n == 1 )); then
            [[ "$zeile" == "$KOPF_ERGEBNIS" ]] || return 1
            continue
        fi
        [[ "$zeile" =~ ^([a-z_]+)\ ([ -~]+)$ ]] || return 1
        schluessel="${BASH_REMATCH[1]}"
        wert="${BASH_REMATCH[2]}"
        case "$schluessel" in
            ergebnis)  [[ "$ERGEBNISSE" == *" $wert "* ]] || return 1; E_ERGEBNIS="$wert" ;;
            grund)     [[ "$wert" == "-" || "$GRUENDE" == *" $wert "* ]] || return 1; E_GRUND="$wert" ;;
            alt)       [[ "$wert" == "-" || "$wert" =~ $RE_SHA ]] || return 1; E_ALT="$wert" ;;
            neu)       [[ "$wert" == "-" || "$wert" =~ $RE_SHA ]] || return 1; E_NEU="$wert" ;;
            zweig)     [[ "$wert" == "-" || "$wert" =~ $RE_ZWEIG ]] || return 1 ;;
            sicherung) [[ "$wert" == "-" || "$wert" =~ $RE_SICHERUNG ]] || return 1; E_SICHERUNG="$wert" ;;
            pruefung)  [[ "$wert" =~ ^(ok|warnung|fehler|-)$ ]] || return 1; E_PRUEFUNG="$wert" ;;
            warnung)   [[ "$WARNUNGEN_BEKANNT" == *" $wert "* ]] || return 1; E_WARNUNGEN+=("$wert") ;;
            *) ;;
        esac
    done < <(head -c 4096 -- "$f")
    [[ -n "$E_ERGEBNIS" ]]
}

# Ergebnis des Kindes in den Lauf: die Datei ist maßgeblich. Fehlt sie oder
# ist sie ungültig, gilt „abgebrochen“ (Signal von uns) bzw. „unterbrochen“.
ergebnis_auswerten() {
    if ergebnis_lesen "$ARBEIT/ergebnis"; then
        L_ERGEBNIS="$E_ERGEBNIS"
        L_GRUND="$E_GRUND"
        [[ -n "$E_ALT" && "$E_ALT" != "-" ]] && L_ALT="$E_ALT"
        L_NEU="$E_NEU"
        L_SICHERUNG="$E_SICHERUNG"
        L_PRUEFUNG="$E_PRUEFUNG"
        L_WARNUNGEN=(${E_WARNUNGEN[@]+"${E_WARNUNGEN[@]}"})
    else
        L_ERGEBNIS="abgebrochen"
        L_GRUND="unterbrochen"
        [[ "$ABGEBROCHEN" == "ja" ]] && L_GRUND="abgebrochen"
        echo "update-helfer.sh: keine gültige Ergebnisdatei vom Update (Exit $KIND_RC)."
    fi
    if [[ "$ERGEBNISSE_OK" == *" $L_ERGEBNIS "* ]]; then
        L_ZUSTAND="fertig"
    else
        L_ZUSTAND="fehler"
    fi
    return 0
}

# Abrufe und Abstände merken (Drosseln von Suchen, 120 s, 20 h)
zeiten_merken() {
    local e="$L_ERGEBNIS" g="$L_GRUND" ende
    ende="$(jetzt)"
    case "$L_AKTION" in
        pruefen)
            if [[ "$e" == "geprueft" ]]; then
                zeit_setzen pruefen "$ende" ok
            elif [[ "$e" == "nicht_begonnen" && ( "$g" == "abruf" || "$g" == "kein_zugang" ) ]]; then
                zeit_setzen pruefen "$ende" "$g"
            fi ;;
        aktualisieren)
            zeit_setzen aktualisieren "$ende"
            if [[ "$e" == "nicht_begonnen" && ( "$g" == "abruf" || "$g" == "kein_zugang" ) ]]; then
                zeit_setzen pruefen "$ende" "$g"
            elif [[ " aktuell eingespielt eingespielt_mit_warnung zurueckgerollt unvollstaendig " == *" $e "* ]] \
                || [[ "$e" == "nicht_begonnen" && " nicht_linear ziel_ungueltig platz sicherung " == *" $g "* ]]; then
                zeit_setzen pruefen "$ende" ok
            fi
            if [[ "$L_AUSLOESER" == "automatik" && "$e" != "nicht_begonnen" && "$e" != "abgebrochen" ]]; then
                zeit_setzen automatik "$ende"
            fi ;;
    esac
    return 0
}

# ------------------------------------------------------------- Kind (update-debian.sh)
puls() {
    local z
    L_PULS="$(jetzt)"
    if [[ -f "$ARBEIT/fortschritt" ]]; then
        z="$(head -c 256 -- "$ARBEIT/fortschritt" 2>/dev/null || true)"
        z="${z%%$'\n'*}"
        if [[ "$z" =~ ^schritt\ ([0-9]{1,2})\ ([0-9]{1,2})\ (.+)$ ]]; then
            L_SCHRITT="${BASH_REMATCH[1]} ${BASH_REMATCH[2]} $(zeile_bereinigen "${BASH_REMATCH[3]}" 100)"
        fi
    fi
    lauf_schreiben nur_app
}

# kind <skript> <optionen…>: update-debian.sh mit leerer Umgebung (nur diese
# Werte), umask 022 und ohne Terminal. Die Ausgabe geht in eine root-Datei –
# nie in ein Rohr (stirbt der Leser, träfe SIGPIPE das Update) – und von dort
# bereinigt ins Journal. Signale an den Helfer gehen immer als TERM weiter:
# ein mit & gestartetes Kind einer nicht interaktiven bash ignoriert SIGINT;
# mit KillMode=mixed bekommt nur der Helfer das TERM von systemd.
kind() {
    local skript="$1" n=0 mitleser
    shift
    rm -f -- "$ARBEIT/ergebnis" "$ARBEIT/fortschritt"
    KIND_RC=0
    set +e
    trap - ERR
    (
        umask 022
        exec env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/root LANG=C.UTF-8 \
            NO_COLOR=1 DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none NEEDRESTART_MODE=l \
            NEEDRESTART_SUSPEND=1 INVOCATION_ID="${INVOCATION_ID:-}" PM_UPDATE_AUSLOESER="$L_AUSLOESER" \
            PM_UPDATE_KENNUNG="$L_KENNUNG" PM_UPDATE_VON="$L_VON" \
            /bin/bash "$skript" "$@" --ergebnis-datei="$ARBEIT/ergebnis" --fortschritt-datei="$ARBEIT/fortschritt"
    ) </dev/null >>"$LOG" 2>&1 8>&- &
    KIND=$!
    # Ins Journal; stirbt der Mitleser, merkt das Update nichts.
    ( exec 8>&-; tail --pid="$KIND" -s 0.5 -n +1 -F -- "$LOG" 2>/dev/null | bereinigen_strom ) &
    mitleser=$!
    trap 'ABGEBROCHEN=ja; kill -s TERM "$KIND" 2>/dev/null' TERM INT HUP
    # Alle 0,5 s nachsehen, alle 2 s ein Puls, alle 4 s das Protokoll.
    while kill -0 "$KIND" 2>/dev/null; do
        sleep 0.5 8>&- &
        wait $! 2>/dev/null      # ein Signal unterbricht nur das Warten
        n=$((n + 1))
        (( n % 4 == 0 )) && puls             # Fehler hier stören das Update nie
        (( n % 8 == 0 )) && protokoll_kopieren
    done
    wait "$KIND" 2>/dev/null
    KIND_RC=$?
    wait "$mitleser" 2>/dev/null
    trap 'ABGEBROCHEN=ja' TERM INT HUP
    trap 'echo "update-helfer.sh: unerwarteter Fehler (Zeile $LINENO)" >&2; exit 1' ERR
    set -e
    return 0
}

# Lauf mit dem Kind: Ergebnis, Zeiten, gescheiterte Stände, Protokoll
kind_lauf() {   # kind_lauf <skript> <optionen…>
    local skript="$1"
    shift
    if [[ "$ABGEBROCHEN" == "ja" ]]; then
        lauf_ende fehler abgebrochen abgebrochen
        return 0
    fi
    if ! hat_schnittstelle "$skript"; then
        echo "update-helfer.sh: $skript kennt die Schnittstelle 1 nicht – nichts ausgeführt."
        lauf_ende fehler nicht_begonnen intern
        return 0
    fi
    protokoll_anlegen
    lauf_schreiben
    kind "$skript" "$@"
    ergebnis_auswerten
    zeiten_merken
    if [[ "$L_AKTION" == "aktualisieren" && ( "$L_ERGEBNIS" == "zurueckgerollt" || "$L_ERGEBNIS" == "unvollstaendig" ) ]]; then
        gescheitert_merken "$L_ZIEL" "$L_GRUND" "$L_BEGINN"
    fi
    protokoll_kopieren
    lauf_ende "$L_ZUSTAND" "$L_ERGEBNIS" "$L_GRUND"
}

# ------------------------------------------------------------- Wiederaufnehmen
# Ein Update starb mitten im Lauf (SIGKILL, Strom weg): die Marke „offen“
# steht, update.lock ist frei. Zurückgerollt wird mit der Fassung, die Stufe 1
# vorher gesichert hat (alt_skript) – ein kaputtes neues Skript kann das
# Zurückrollen so nicht verhindern. Höchstens zweimal je Marke.
alt_skript_waehlen() {
    local a
    a="$(marke_wert alt_skript)"
    if [[ -n "$a" && "$a" == "$ZUSTAND/alt/"* && "${a#"$ZUSTAND/alt/"}" =~ ^[A-Za-z0-9._-]+$ && "$a" != *..* ]] \
        && [[ -f "$a" && ! -L "$a" ]] \
        && { [[ $EUID -ne 0 || "$EIGENTUEMER" == "nein" ]] || root_eigen "$a"; } \
        && hat_schnittstelle "$a" && bash -n "$a" 2>/dev/null; then
        printf '%s' "$a"
        return 0
    fi
    [[ -n "$a" ]] && echo "update-helfer.sh: die gesicherte alte Fassung ($a) ist nicht brauchbar – nehme $UPDATE_SKRIPT." >&2
    printf '%s' "$UPDATE_SKRIPT"
}

wiederaufnehmen() {
    local skript
    wiederaufnahme_lesen
    if (( WA_VERSUCHE >= WIEDERAUFNAHMEN_MAX )); then
        OFFEN_ZUSTAND="aufgegeben"
        if [[ "$WA_GEMELDET" != "ja" ]]; then
            echo "update-helfer.sh: das unterbrochene Update ließ sich nach $WA_VERSUCHE Versuchen nicht zurückrollen – Handarbeit nötig."
            lauf_beginnen "$(zufalls_kennung)" wiederaufnehmen system system -
            L_SCHRITT="0 12 -"
            lauf_ende fehler unvollstaendig unterbrochen
            printf '%s %s gemeldet\n' "$WA_ZEIT" "$WA_VERSUCHE" | root_schreiben "$ZUSTAND/wiederaufnahme" || true
        fi
        return 0
    fi
    printf '%s %s\n' "$WA_ZEIT" "$((WA_VERSUCHE + 1))" | root_schreiben "$ZUSTAND/wiederaufnahme" \
        || aufruf_fehler "$ZUSTAND/wiederaufnahme ließ sich nicht schreiben"
    WIEDER="ja"
    echo "update-helfer.sh: ein früheres Update wurde unterbrochen – Zurückrollen (Versuch $((WA_VERSUCHE + 1)) von $WIEDERAUFNAHMEN_MAX)."
    skript="$(alt_skript_waehlen)"
    lauf_beginnen "$(zufalls_kennung)" wiederaufnehmen system system -
    kind_lauf "$skript" --wiederaufnehmen
    verlauf_spiegeln
    if [[ ! -e "$ZUSTAND/offen" ]]; then
        rm -f -- "$ZUSTAND/wiederaufnahme"
    fi
    return 0
}

# ------------------------------------------------------------- Stand aus git
# Nur lesend und ohne Netz (Refs vom letzten Abruf). Kandidaten: Stände auf
# der Erst-Eltern-Linie von origin/<zweig>, die Nachfahren des installierten
# sind – auf jeden gelingt „merge --ff-only“. Beides getrennt erfragen und
# schneiden: „--first-parent --ancestry-path“ zusammen verliert den Merge, in
# dem ein installierter Stand eines Seitenzweigs aufging.
stand_schreiben() {
    local f="$ARBEIT/stand" kopf zweig="-" spitze="" zeile h ct s url quelle="-" abruf abruf_zeit="-" status zeit_abruf
    local neu=0 linear="nein" lokal="nein" mig="nein" einr="nein" flows="nein" weitere=0 n=0
    local -A nachfahren=()
    kopf="$(kopf_lesen)"
    if [[ "$kopf" != "-" ]]; then
        zweig="$(gl symbolic-ref -q --short HEAD 2>/dev/null || true)"
        if [[ ! "$zweig" =~ $RE_ZWEIG ]] || ! git check-ref-format --branch "$zweig" >/dev/null 2>&1; then
            zweig="-"
        fi
        if [[ "$zweig" != "-" ]]; then
            spitze="$(gl rev-parse -q --verify "refs/remotes/origin/$zweig^{commit}" 2>/dev/null || true)"
            [[ "$spitze" =~ $RE_SHA ]] || spitze=""
        fi
        url="$(gl config --get remote.origin.url 2>/dev/null || true)"
        if [[ "$url" =~ ^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]; then
            quelle="${url%.git}"
        fi
        [[ -n "$(gl status --porcelain --untracked-files=no 2>/dev/null || true)" ]] && lokal="ja"
    fi
    if [[ -f "$ZIEL/.git/FETCH_HEAD" ]]; then
        abruf_zeit="$(stat -c %Y -- "$ZIEL/.git/FETCH_HEAD" 2>/dev/null || echo -)"
    fi
    status="$(zeit_status)"
    zeit_abruf="$(zeit_wert pruefen)"
    if [[ -n "$status" ]]; then
        abruf="$status"
        # Ein späterer erfolgreicher Abruf (etwa von der Konsole) gilt mehr.
        if [[ "$status" != "ok" && "$abruf_zeit" =~ ^[0-9]+$ && -n "$zeit_abruf" ]] && (( abruf_zeit > 10#$zeit_abruf )); then
            abruf="ok"
        fi
    elif [[ "$abruf_zeit" != "-" ]]; then
        abruf="ok"
    else
        abruf="nie"
    fi
    {
        echo "$KOPF_STAND"
        echo "erzeugt $(jetzt)"
        echo "abruf $abruf"
        echo "abruf_zeit $abruf_zeit"
        echo "zweig $zweig"
        echo "quelle $quelle"
        if [[ "$kopf" != "-" ]]; then
            echo "installiert $kopf $(gl log -1 --format=%ct "$kopf" 2>/dev/null || echo 0)"
            s="$(zeile_bereinigen "$(gl log -1 --format=%s "$kopf" 2>/dev/null || true)" "$BETREFF_MAX")"
            echo "installiert_betreff ${s:--}"
        else
            echo "installiert - -"
            echo "installiert_betreff -"
        fi
        if [[ -n "$spitze" ]]; then
            echo "verfuegbar $spitze $(gl log -1 --format=%ct "$spitze" 2>/dev/null || echo 0)"
            neu="$(gl rev-list --count --no-merges "$kopf..$spitze" 2>/dev/null || echo 0)"
            [[ "$neu" =~ ^[0-9]+$ ]] || neu=0
            gl merge-base --is-ancestor "$kopf" "$spitze" 2>/dev/null && linear="ja"
            gl diff --quiet "$kopf" "$spitze" -- database/migrations 2>/dev/null || mig="ja"
            gl diff --quiet "$kopf" "$spitze" -- deploy 2>/dev/null || einr="ja"
            gl diff --quiet "$kopf" "$spitze" -- deploy/node-red/flows.json 2>/dev/null || flows="ja"
        else
            echo "verfuegbar - -"
        fi
        echo "neu $neu"
        echo "linear $linear"
        echo "lokal_geaendert $lokal"
        echo "migrationen $mig"
        echo "einrichtung $einr"
        echo "flows $flows"
        if [[ -n "$spitze" && "$linear" == "ja" && "$kopf" != "$spitze" ]]; then
            while IFS= read -r h; do
                [[ "$h" =~ $RE_SHA ]] && nachfahren[$h]=1
            done < <(gl rev-list --ancestry-path "$kopf..$spitze" 2>/dev/null || true)
            n=0
            while IFS=' ' read -r h ct; do
                [[ "$h" =~ $RE_SHA && "$ct" =~ ^[0-9]+$ && -n "${nachfahren[$h]+x}" ]] || continue
                echo "ziel_kandidat $h $ct"
                n=$((n + 1))
                (( n >= STAND_KANDIDATEN )) && break
            done < <(gl log --first-parent --format='%H %ct' "$kopf..$spitze" 2>/dev/null || true)
            while IFS= read -r zeile; do
                h="${zeile%% *}"; zeile="${zeile#* }"
                ct="${zeile%% *}"; s="${zeile#* }"
                [[ "$h" =~ $RE_SHA && "$ct" =~ ^[0-9]+$ ]] || continue
                [[ "$s" == "$zeile" ]] && s=""
                echo "commit $h $ct $(zeile_bereinigen "$s" "$BETREFF_MAX")"
            done < <(gl log --no-merges -n "$STAND_COMMITS" --format='%H %ct %s' "$kopf..$spitze" 2>/dev/null || true)
            (( neu > STAND_COMMITS )) && weitere=$((neu - STAND_COMMITS))
            n=0
            while IFS= read -r zeile; do
                h="${zeile%% *}"; s="${zeile#* }"
                [[ "$h" =~ $RE_SHA && "$s" != "$zeile" ]] || continue
                s="$(zeile_bereinigen "$s" "$BETREFF_MAX")"
                [[ -n "${s// /}" ]] || continue
                echo "hinweis ${h:0:7} $s"
                n=$((n + 1))
                (( n >= STAND_HINWEISE )) && break
            done < <(gl log -n 500 --format='%H %(trailers:key=Update-Hinweis,valueonly,unfold,separator=%x20)' \
                "$kopf..$spitze" 2>/dev/null || true)
        fi
        echo "weitere $weitere"
        if [[ -f "$ZUSTAND/gescheitert" ]]; then
            grep -oE '^[0-9a-f]{40} [0-9]+ [a-z_]+$' -- "$ZUSTAND/gescheitert" | cut -d' ' -f1 | sort -u \
                | sed 's/^/gescheitert /' || true
        fi
    } > "$f.roh" 2>/dev/null
    utf8_bereinigen < "$f.roh" > "$f"
    als_app_schreiben "$IST/stand" < "$f" || true
    return 0
}

# ------------------------------------------------------------- Nachlauf (ExecStopPost)
# Läuft, wenn der Dienst endet – auch nach SIGKILL des Helfers oder nach dem
# Zeitlimit. Steht die root-Kopie des Laufs noch auf „laeuft“ und ist
# update.lock frei, starb der Helfer: dann „fehler helfer“ bzw.
# „zeitueberschreitung“. Liest auch die Lauf-Datei eines älteren Helfers.
nachlauf() {
    local zeile schluessel wert inv="${INVOCATION_ID:-}"
    local -A lr=()
    local w=()
    [[ -f "$ZUSTAND/lauf" && ! -L "$ZUSTAND/lauf" ]] || return 0
    while IFS= read -r zeile; do
        [[ "$zeile" =~ ^([a-z_]+)\ ([^[:cntrl:]]*)$ ]] || continue
        schluessel="${BASH_REMATCH[1]}"
        wert="${BASH_REMATCH[2]}"
        if [[ "$schluessel" == "warnung" ]]; then
            [[ "$WARNUNGEN_BEKANNT" == *" $wert "* ]] && w+=("$wert")
        else
            lr[$schluessel]="$wert"
        fi
    done < <(head -c 8192 -- "$ZUSTAND/lauf")
    L_KENNUNG="${lr[kennung]:-}"; [[ "$L_KENNUNG" =~ $RE_KENNUNG ]] || L_KENNUNG="$(zufalls_kennung)"
    L_AKTION="${lr[aktion]:--}"; [[ "$L_AKTION" =~ ^(pruefen|aktualisieren|wiederaufnehmen)$ ]] || L_AKTION="-"
    L_AUSLOESER="${lr[ausloeser]:--}"; [[ "$L_AUSLOESER" =~ ^(hand|automatik|konsole|system)$ ]] || L_AUSLOESER="-"
    L_VON="${lr[von]:--}"; [[ "$L_VON" =~ $RE_VON ]] || L_VON="-"
    L_SCHRITT="${lr[schritt]:-0 12 -}"; [[ "$L_SCHRITT" =~ ^[0-9]{1,2}\ [0-9]{1,2}\ [^[:cntrl:]]{1,200}$ ]] || L_SCHRITT="0 12 -"
    L_BEGINN="${lr[beginn]:-0}"; [[ "$L_BEGINN" =~ ^[0-9]{1,12}$ ]] || L_BEGINN="0"
    L_ALT="${lr[alt]:--}"; [[ "$L_ALT" =~ $RE_SHA ]] || L_ALT="-"
    L_NEU="${lr[neu]:--}"; [[ "$L_NEU" =~ $RE_SHA ]] || L_NEU="-"
    L_ZIEL="${lr[ziel]:--}"; [[ "$L_ZIEL" =~ $RE_SHA ]] || L_ZIEL="-"
    L_SICHERUNG="${lr[sicherung]:--}"; [[ "$L_SICHERUNG" =~ $RE_SICHERUNG ]] || L_SICHERUNG="-"
    L_PRUEFUNG="${lr[pruefung]:--}"; [[ "$L_PRUEFUNG" =~ ^(ok|warnung|fehler|-)$ ]] || L_PRUEFUNG="-"
    L_WARNUNGEN=(${w[@]+"${w[@]}"})
    L_INVOCATION="${lr[invocation]:--}"; [[ "$L_INVOCATION" =~ ^[0-9a-f]{32}$ ]] || L_INVOCATION="-"
    L_PROTOKOLL="${lr[protokoll]:--}"; [[ "$L_PROTOKOLL" =~ $RE_PROTOKOLL ]] || L_PROTOKOLL="-"
    L_ZUSTAND="${lr[zustand]:-}"; L_ERGEBNIS="${lr[ergebnis]:--}"; L_GRUND="${lr[grund]:--}"

    if [[ "$L_ZUSTAND" == "laeuft" ]]; then
        if ! update_sperre_frei; then
            echo "update-helfer.sh: das Update läuft noch (update.lock belegt) – nichts nachzutragen."
            return 0
        fi
        L_GRUND="helfer"
        [[ "${SERVICE_RESULT:-}" == "timeout" ]] && L_GRUND="zeitueberschreitung"
        echo "update-helfer.sh: der Update-Dienst endete mitten im Lauf (${SERVICE_RESULT:-ohne Angabe})."
        if [[ -e "$ZUSTAND/offen" ]]; then
            echo "update-helfer.sh: das unterbrochene Update wird beim nächsten Start des Dienstes zurückgerollt."
        fi
        if [[ "$L_AKTION" == "aktualisieren" ]]; then
            gescheitert_merken "$L_ZIEL" "$L_GRUND" "$L_BEGINN"
        fi
        lauf_ende fehler abgebrochen "$L_GRUND"
    elif [[ "${SERVICE_RESULT:-}" == "timeout" && "$L_INVOCATION" != "-" && "$L_INVOCATION" == "$inv" \
            && "$L_AKTION" == "aktualisieren" && "$L_GRUND" == "abgebrochen" \
            && " zurueckgerollt unvollstaendig abgebrochen " == *" $L_ERGEBNIS "* ]]; then
        # Das Zeitlimit des Dienstes schickte TERM; das Update rollte sauber
        # zurück. Das ist kein Abbruch durch jemanden, sondern ein Urteil.
        echo "update-helfer.sh: das Update lief in das Zeitlimit des Dienstes."
        gescheitert_merken "$L_ZIEL" zeitueberschreitung "$L_BEGINN"
        L_GRUND="zeitueberschreitung"
        lauf_schreiben
    fi
    return 0
}

# ------------------------------------------------------------- main
main() {
    local rc=0
    optionen "$@"
    if [[ "$MODUS" == "auftrag-pruefen" ]]; then
        auftrag_nur_pruefen
        return 0
    fi
    benutzer_aufloesen
    if [[ $EUID -eq 0 && "$EIGENTUEMER" == "ja" ]]; then
        code_pruefen
    fi
    cd /
    [[ -d "${SPERRE%/*}" ]] || install -d -m 750 -- "${SPERRE%/*}"
    exec 8>>"$SPERRE" || aufruf_fehler "Sperrdatei $SPERRE lässt sich nicht öffnen"
    chmod 600 -- "$SPERRE" 2>/dev/null || true
    if ! flock -w 5 8; then
        # Läuft schon ein Helfer: PathExists= holt einen liegenden Auftrag danach.
        echo "update-helfer.sh: ein anderer Lauf des Helfers ist aktiv – dieser endet."
        return 0
    fi
    # Ein Signal außerhalb des Updates beendet nichts halb – der Lauf endet
    # geordnet (kein neues Update mehr).
    trap 'ABGEBROCHEN=ja' TERM INT HUP
    arbeit_anlegen
    zustand_anlegen
    if [[ "$MODUS" == "nachlauf" ]]; then
        nachlauf
        conf_lesen
        pfad_heilen
        lebenszeichen
        return 0
    fi

    arbeit_reste_raeumen
    conf_lesen
    pfad_heilen
    lebenszeichen
    if [[ -e "$ZUSTAND/offen" ]] && update_sperre_frei; then
        wiederaufnehmen
    fi
    wartung_verwaist_raeumen
    verlauf_spiegeln

    auftrag_uebernehmen || rc=$?
    case "$rc" in
        0) ;;
        3)  # kein Auftrag (Zeitgeber, nach einem Lauf)
            stand_schreiben
            protokolle_aufraeumen
            lebenszeichen
            return 0 ;;
        *)
            V_KENNUNG=""; V_AKTION=""; V_AUSLOESER=""; V_VON=""; V_ZIEL=""
            echo "update-helfer.sh: Auftrag abgelehnt ($UEBERNAHME_GRUND)."
            lauf_abgelehnt "$UEBERNAHME_GRUND"
            stand_schreiben
            lebenszeichen
            return 0 ;;
    esac
    vorab_lesen "$ARBEIT/auftrag"
    GRUND=""
    if ! auftrag_pruefen; then
        lauf_abgelehnt "$GRUND"
        stand_schreiben
        lebenszeichen
        return 0
    fi
    kennung_merken
    lauf_beginnen "$A_KENNUNG" "$A_AKTION" "$A_AUSLOESER" "$A_VON" "${A_ZIEL:--}"
    case "$A_AKTION" in
        pruefen)
            if abstand_unter pruefen "$ABSTAND_PRUEFEN"; then
                # Eben erst geholt: Stand aus den Refs, ohne Netz.
                L_SCHRITT="0 12 -"
                lauf_ende fertig geprueft -
            else
                kind_lauf "$UPDATE_SKRIPT" --pruefen
            fi ;;
        aktualisieren)
            kind_lauf "$UPDATE_SKRIPT" --bis="$A_ZIEL" ;;
    esac
    stand_schreiben
    verlauf_spiegeln
    # Ohne Ergebnis, aber mit Marke (das Update starb): sofort zurückrollen.
    if [[ "$L_ERGEBNIS" == "abgebrochen" && -e "$ZUSTAND/offen" && "$ABGEBROCHEN" == "nein" ]] && update_sperre_frei; then
        wiederaufnehmen
        stand_schreiben
    fi
    protokolle_aufraeumen
    lebenszeichen
    return 0
}

main "$@"; exit $?
