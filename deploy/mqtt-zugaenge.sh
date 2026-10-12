#!/bin/bash
#
# Eigene MQTT-Zugänge der Shellys in Mosquitto übernehmen.
#
# Die Anwendung (www-data) darf /etc/mosquitto nicht anfassen. Sie schreibt
# ihren Sollzustand nach storage/mqtt/soll/zugaenge.txt; dieser Helfer
# (root, gestartet von powermanagement-mqtt-zugaenge.path/.timer) prüft ihn
# streng und überträgt ihn:
#
#   /etc/mosquitto/powermanagement.passwd        verwalteter Block oben mit den
#                                                Konten (nur Mosquitto-Hashes)
#   /etc/mosquitto/powermanagement-wirksam.acl   Grundlage (powermanagement.acl)
#                                                + „topic deny <präfix>/#“ für den
#                                                gemeinsamen Zugang shelly
#
# Danach lädt er Mosquitto neu (SIGHUP – bestehende Verbindungen bleiben) und
# schreibt das Ergebnis als App-Benutzer nach storage/mqtt/ist/ergebnis.txt.
# Zeilen der Kennwortdatei außerhalb des Blocks (shelly, nodered,
# powermanagement, von Hand angelegte) bleiben Byte für Byte, wie sie sind.
#
# Aufruf:
#   mqtt-zugaenge.sh --ziel=DIR --app-benutzer=USER [--neu-laden=systemd|pid:N|nein]
#                    [--viele-entfernen]
#   Prüf-/Testoptionen: --passwd=F --basis=F --wirksam=F --conf=F (mehrfach)
#                       --soll=F --ist=F --lock=F --ohne-eigentuemerpruefung
#                       --app-benutzer=   (leer: Soll und Ergebnis direkt, ohne setpriv)
#   mqtt-zugaenge.sh --nur-namen   liest „<generation> <benutzer>“ je Zeile von der
#                                  Standardeingabe, schreibt je Zeile „ok“ oder
#                                  „fehler <code>“ (feste Namensregeln)
#
# Letzte Zeile auf stdout: geaendert | unveraendert | abgelehnt
# Exit 0 = ok/unverändert, 2 = Soll abgelehnt (wirksame ACL trotzdem aus dem
# aktuellen Block gebaut), 1 = Fehler.
#
# Eingaben werden nie ausgeführt: nur „while IFS= read -r“, kein eval, keine
# aus Eingaben gebauten awk-/sed-Programme. Hashes erscheinen nie in einer
# Ausgabe, im Journal oder im Ergebnis.

set -Eeuo pipefail
export LC_ALL=C
umask 077

trap 'echo "mqtt-zugaenge.sh: unerwarteter Fehler (Zeile $LINENO)" >&2; exit 1' ERR

# ------------------------------------------------------------- Festwerte
KOPF_SOLL='# powermanagement mqtt-zugaenge 1'
KOPF_ERGEBNIS='# powermanagement mqtt-zugaenge-ergebnis 1'
MARKE_ANFANG='# >>> powermanagement geraetezugaenge - verwaltet von mqtt-zugaenge.sh, nicht von Hand aendern'
MARKE_ENDE='# <<< powermanagement geraetezugaenge'
# Als Markierung zählt jede Zeile, die so beginnt – auch eine angepasste.
MARKE_ANFANG_KURZ='# >>> powermanagement geraetezugaenge'
MARKE_ENDE_KURZ='# <<< powermanagement geraetezugaenge'
ERZEUGT_KOPF='# --- erzeugt von mqtt-zugaenge.sh - nicht von Hand aendern ---'
ERZEUGT_TEXT='# Gemeinsamer Zugang: kein Zugriff auf Shellys mit eigenem Zugang.'

GROESSE_MAX=262144
KONTEN_MAX=2000
ENTFERNEN_MAX=5
SPERRE_VORLAUF_MAX=600
RUNDEN_MAX=5

RE_GEN2='^[a-z0-9][a-z0-9_.-]{0,63}$'
RE_GEN2_SHELLY='^shelly/[a-z0-9][a-z0-9_.-]{0,63}$'
RE_GEN1='^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$'
# Was überhaupt ein Benutzer- oder Präfixname sein kann (für Namen außerhalb
# des Blocks und für gelesene Sperren) – alles andere kann nie gleich sein.
RE_NAME_ALLG='^[A-Za-z0-9][A-Za-z0-9_./-]*$'
RE_HASH='^\$7\$101\$[A-Za-z0-9+/]{16}\$[A-Za-z0-9+/]{86}==$'
RE_LAUF='^lauf ([0-9]{1,12})$'
RE_KONTO='^konto ([^ ]+) ([^ ]+) ([^ ]+) ([^ ]+)$'
RE_BIS='^bis:([0-9]{10})$'
RE_BLOCK_KONTO='^# konto ([^ ]+) ([^ ]+) ([^ ]+)$'
RE_BASIS='^(user|topic|pattern)[[:space:]]+[^[:space:]]'
RE_LEER='^[[:space:]]*$'
RE_ACL_FILE='^[[:space:]]*acl_file([[:space:]]+(.*))?$'
RE_DENY='^topic deny (.+)/#$'

# ------------------------------------------------------------- Vorgaben
ZIEL=""
APP_BENUTZER=""
APP_GESETZT="nein"
NEU_LADEN="systemd"
VIELE_ENTFERNEN="nein"
PASSWD="/etc/mosquitto/powermanagement.passwd"
BASIS="/etc/mosquitto/powermanagement.acl"
WIRKSAM="/etc/mosquitto/powermanagement-wirksam.acl"
CONF_LISTE=()
SOLL=""
IST=""
# Nicht unter /run/lock: das ist für alle beschreibbar (1777) – dort könnte
# ein anderer Benutzer vorher einen Verweis oder eine eigene Datei unter
# diesem Namen ablegen. /etc/mosquitto gehört root.
LOCK="/etc/mosquitto/powermanagement-mqtt-zugaenge.lock"
EIGENTUEMER_PRUEFEN="ja"
NUR_NAMEN="nein"
CONF_NEU=""

aufruf_fehler() {
    echo "mqtt-zugaenge.sh: $1" >&2
    exit 1
}

# Pfad aus einer Option: absolut, ohne Zeilenumbruch.
#   pfad_setzen <variable> <wert> <option>
pfad_setzen() {
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

for arg in "$@"; do
    case "$arg" in
        --ziel=*)          einmal --ziel; pfad_setzen ZIEL "${arg#*=}" --ziel; [[ "$ZIEL" == / ]] || ZIEL="${ZIEL%/}" ;;
        --app-benutzer=*)  einmal --app-benutzer; APP_BENUTZER="${arg#*=}"; APP_GESETZT="ja" ;;
        --neu-laden=*)     einmal --neu-laden; NEU_LADEN="${arg#*=}" ;;
        --viele-entfernen) einmal --viele-entfernen; VIELE_ENTFERNEN="ja" ;;
        --passwd=*)        einmal --passwd; pfad_setzen PASSWD "${arg#*=}" --passwd ;;
        --basis=*)         einmal --basis; pfad_setzen BASIS "${arg#*=}" --basis ;;
        --wirksam=*)       einmal --wirksam; pfad_setzen WIRKSAM "${arg#*=}" --wirksam ;;
        --conf=*)          pfad_setzen CONF_NEU "${arg#*=}" --conf; CONF_LISTE+=("$CONF_NEU") ;;
        --soll=*)          einmal --soll; pfad_setzen SOLL "${arg#*=}" --soll ;;
        --ist=*)           einmal --ist; pfad_setzen IST "${arg#*=}" --ist ;;
        --lock=*)          einmal --lock; pfad_setzen LOCK "${arg#*=}" --lock ;;
        --ohne-eigentuemerpruefung) einmal "$arg"; EIGENTUEMER_PRUEFEN="nein" ;;
        --nur-namen)       einmal "$arg"; NUR_NAMEN="ja" ;;
        *)                 aufruf_fehler "unbekannte Option: ${arg%%=*}" ;;
    esac
done

# ------------------------------------------------------------- Namensregeln
# Gleich in PHP (MqttZugaenge::namensFehler). Ergebnis in NAME_FEHLER:
# leer = in Ordnung, sonst generation | zeichen | reserviert.
NAME_FEHLER=""
name_pruefen() {
    local generation="$1" benutzer="$2"
    NAME_FEHLER=""
    case "$generation" in
        1)
            if [[ ! "$benutzer" =~ $RE_GEN1 ]]; then
                NAME_FEHLER="zeichen"
                return 0
            fi ;;
        2)
            if [[ ! "$benutzer" =~ $RE_GEN2 && ! "$benutzer" =~ $RE_GEN2_SHELLY ]]; then
                NAME_FEHLER="zeichen"
                return 0
            fi ;;
        *)
            NAME_FEHLER="generation"
            return 0 ;;
    esac
    # Ohne Groß/Klein, auf den ganzen Namen (LC_ALL=C: nur ASCII).
    case "${benutzer,,}" in
        shelly|shellies|nodered|powermanagement|pm|command|announce|pm-*|pm_*|pm.*)
            NAME_FEHLER="reserviert" ;;
    esac
    return 0
}

# Präfix (Themenbaum ohne /#) und Gerätename aus Benutzer und Generation.
praefix_von() {
    if [[ "$2" == 1 ]]; then
        printf 'shellies/%s' "$1"
    else
        printf '%s' "$1"
    fi
}
GERAET=""
geraet_von() {
    local letztes="$1"
    [[ "$2" == 2 ]] && letztes="${letztes#shelly/}"
    GERAET="${letztes,,}"
}

if [[ "$NUR_NAMEN" == "ja" ]]; then
    # NUL-Bytes verschluckte „read“ – vorher in ein Zeichen verwandeln, das
    # jede Regel ablehnt.
    tr '\000' '\377' | while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        generation="${zeile%% *}"
        benutzer=""
        [[ "$zeile" == *" "* ]] && benutzer="${zeile#* }"
        name_pruefen "$generation" "$benutzer"
        if [[ -z "$NAME_FEHLER" ]]; then
            echo "ok"
        else
            echo "fehler $NAME_FEHLER"
        fi
    done
    exit 0
fi

# ------------------------------------------------------------- Aufruf prüfen
[[ -n "$ZIEL" ]] || aufruf_fehler "--ziel=DIR fehlt"
[[ "$APP_GESETZT" == "ja" ]] || aufruf_fehler "--app-benutzer=USER fehlt (leer erlaubt)"
case "$NEU_LADEN" in
    systemd|nein) ;;
    pid:*)
        [[ "${NEU_LADEN#pid:}" =~ ^[1-9][0-9]{0,9}$ ]] || aufruf_fehler "--neu-laden=pid:N erwartet eine Prozessnummer" ;;
    *) aufruf_fehler "--neu-laden erwartet systemd, pid:N oder nein" ;;
esac
[[ -n "$SOLL" ]] || SOLL="$ZIEL/storage/mqtt/soll/zugaenge.txt"
[[ -n "$IST" ]] || IST="$ZIEL/storage/mqtt/ist/ergebnis.txt"
if [[ ${#CONF_LISTE[@]} -eq 0 ]]; then
    CONF_LISTE=(/etc/mosquitto/mosquitto.conf)
    for f in /etc/mosquitto/conf.d/*.conf; do
        [[ -e "$f" ]] && CONF_LISTE+=("$f")
    done
fi

APP_UID=""
APP_GID=""
if [[ -n "$APP_BENUTZER" ]]; then
    [[ "$APP_BENUTZER" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]*$ ]] || aufruf_fehler "--app-benutzer: ungültiger Name"
    APP_UID="$(id -u -- "$APP_BENUTZER" 2>/dev/null)" || aufruf_fehler "App-Benutzer $APP_BENUTZER gibt es nicht"
    APP_GID="$(id -g -- "$APP_BENUTZER" 2>/dev/null)" || aufruf_fehler "App-Benutzer $APP_BENUTZER gibt es nicht"
fi

# Als root läuft hier Code aus dem Anwendungsverzeichnis. Könnte die
# Anwendung (www-data) ihn verändern, wäre das ein Weg zu root.
root_eigen() {
    local info uid modus
    info="$(stat -L -c '%u %a' -- "$1" 2>/dev/null)" || return 1
    uid="${info%% *}"
    modus="${info#* }"
    [[ "$uid" == 0 ]] || return 1
    (( (8#$modus & 8#022) == 0 ))
}
if [[ $EUID -eq 0 && "$EIGENTUEMER_PRUEFEN" == "ja" ]]; then
    SKRIPT="$(readlink -f -- "${BASH_SOURCE[0]}")"
    for pfad_pruefen in "$SKRIPT" "$(dirname -- "$SKRIPT")" "$ZIEL"; do
        if ! root_eigen "$pfad_pruefen"; then
            echo "mqtt-zugaenge.sh: Code-Verzeichnis nicht root-eigen: $pfad_pruefen" >&2
            exit 1
        fi
    done
fi

ARBEIT="$(mktemp -d)"
OFFEN_TMP=""
aufraeumen() {
    [[ -n "$OFFEN_TMP" ]] && rm -f -- "$OFFEN_TMP"
    rm -rf -- "$ARBEIT"
}
trap aufraeumen EXIT

# Zwei Läufe (Pfad-Einheit, Zeitgeber, Installation) nie gleichzeitig.
# „>>“: eine vorhandene Datei nie kürzen. Kindprozesse als App-Benutzer
# bekommen diesen Deskriptor nicht mit (9>&- bei setpriv).
exec 9>>"$LOCK" || aufruf_fehler "Sperrdatei $LOCK lässt sich nicht öffnen"
# Nur der Besitzer darf sie öffnen – wer sie lesen kann, kann sie auch
# sperren und den Helfer so aussperren.
chmod go-rwx -- "$LOCK" 2>/dev/null || true
flock -w 30 9 || aufruf_fehler "Sperre $LOCK nach 30 s nicht frei"

# ------------------------------------------------------------- Bausteine
JETZT=0

# Soll lesen – als App-Benutzer: einer Datei (oder einem Verweis), die
# www-data nicht lesen darf, folgt der Helfer so nie. Höchstens ein Byte über
# der Grenze, damit „zu groß“ erkennbar bleibt. „timeout“: tauscht jemand
# zwischen test und head eine FIFO unter, hängt der Lauf nicht (samt Sperre).
# SOLL_ZUSTAND: da | fehlt | unlesbar
SOLL_ZUSTAND=""
soll_holen() {
    local ziel="$1" rc=0
    : > "$ziel"
    if [[ -z "$APP_BENUTZER" ]]; then
        if [[ -f "$SOLL" ]]; then
            timeout -s KILL 10 head -c "$((GROESSE_MAX + 1))" -- "$SOLL" > "$ziel" 2>/dev/null 9>&- || rc=1
        else
            rc=3
        fi
    else
        setpriv --reuid="$APP_UID" --regid="$APP_GID" --init-groups -- \
            /bin/sh -c 'test -f "$1" || exit 3; exec timeout -s KILL 10 head -c "$2" -- "$1"' \
            sh "$SOLL" "$((GROESSE_MAX + 1))" > "$ziel" 2>/dev/null 9>&- || rc=$?
    fi
    case "$rc" in
        0) SOLL_ZUSTAND="da" ;;
        3) SOLL_ZUSTAND="fehlt" ;;
        *) SOLL_ZUSTAND="unlesbar" ;;
    esac
}

# Lauf-Nummer aus Kopf und zweiter Zeile – auch wenn der Rest ungültig ist,
# soll die Anwendung erfahren, dass ihr Lauf abgelehnt wurde.
SOLL_LAUF="-"
lauf_bestimmen() {
    local z1="" z2=""
    SOLL_LAUF="-"
    [[ "$SOLL_ZUSTAND" == "da" ]] || return 0
    { IFS= read -r z1 || true; IFS= read -r z2 || true; } < "$1"
    if [[ "$z1" == "$KOPF_SOLL" && "$z2" =~ $RE_LAUF ]]; then
        SOLL_LAUF="${BASH_REMATCH[1]}"
    fi
    return 0
}

# Kennung, an der eine neue Soll-Datei zu erkennen ist.
soll_kennung() {
    if [[ "$SOLL_ZUSTAND" == "fehlt" ]]; then
        printf 'fehlt'
    else
        printf '%s %s' "$SOLL_ZUSTAND" "$SOLL_LAUF"
    fi
}

# Nur druckbares ASCII und LF? Sonst Zeile des ersten fremden Bytes in
# ZEICHEN_ZEILE.
ZEICHEN_ZEILE=0
nur_druckbar() {
    local datei="$1" rein="$ARBEIT/rein" erste="" versatz zeilen
    tr -d -c '\n\040-\176' < "$datei" > "$rein"
    if cmp -s -- "$datei" "$rein"; then
        return 0
    fi
    erste="$( { cmp -l -- "$datei" "$rein" 2>/dev/null || true; } | head -n 1)"
    erste="${erste#"${erste%%[! ]*}"}"
    versatz="${erste%% *}"
    if [[ ! "$versatz" =~ ^[0-9]+$ ]]; then
        # Die bereinigte Fassung ist ein Anfang der Datei: das erste fremde
        # Byte steht direkt dahinter.
        versatz=$(( $(wc -c < "$rein") + 1 ))
    fi
    zeilen="$(head -c "$((versatz - 1))" -- "$datei" | tr -d -c '\n' | wc -c)"
    ZEICHEN_ZEILE=$(( zeilen + 1 ))
    return 1
}

# Soll prüfen – alles oder nichts. Ergebnis: SOLL_GUELTIG, SOLL_FEHLER
# („<zeile> <code>“), SOLL_ENTFERNEN, Konten in T_GEN/T_HASH/T_SPERRE.
declare -A T_GEN=() T_HASH=() T_SPERRE=() T_GERAETE=()
SOLL_GUELTIG="nein"
SOLL_FEHLER=""
SOLL_ENTFERNEN="nein"
soll_fehler() {
    SOLL_GUELTIG="nein"
    SOLL_FEHLER="$1 $2"
}
soll_pruefen() {
    local datei="$1" zeile benutzer generation hash sperre bis
    local -i nr=0 anzahl=0
    SOLL_GUELTIG="nein"
    SOLL_FEHLER=""
    SOLL_ENTFERNEN="nein"
    T_GEN=(); T_HASH=(); T_SPERRE=(); T_GERAETE=()

    if (( $(wc -c < "$datei") > GROESSE_MAX )); then
        soll_fehler 0 groesse
        return 0
    fi
    if ! nur_druckbar "$datei"; then
        soll_fehler "$ZEICHEN_ZEILE" format
        return 0
    fi
    while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        nr+=1
        if (( nr == 1 )); then
            [[ "$zeile" == "$KOPF_SOLL" ]] || { soll_fehler 1 kopf; return 0; }
            continue
        fi
        if (( nr == 2 )); then
            [[ "$zeile" =~ $RE_LAUF ]] || { soll_fehler 2 lauf; return 0; }
            continue
        fi
        if (( nr == 3 )) && [[ "$zeile" == "entfernen-erlaubt ja" ]]; then
            SOLL_ENTFERNEN="ja"
            continue
        fi
        if (( nr == 3 )) && [[ "$zeile" == "entfernen-erlaubt nein" ]]; then
            continue
        fi
        [[ -z "$zeile" || "$zeile" == \#* ]] && continue
        if [[ ! "$zeile" =~ $RE_KONTO ]]; then
            soll_fehler "$nr" format
            return 0
        fi
        benutzer="${BASH_REMATCH[1]}"
        generation="${BASH_REMATCH[2]}"
        hash="${BASH_REMATCH[3]}"
        sperre="${BASH_REMATCH[4]}"
        anzahl+=1
        if (( anzahl > KONTEN_MAX )); then
            soll_fehler "$nr" anzahl
            return 0
        fi
        name_pruefen "$generation" "$benutzer"
        if [[ -n "$NAME_FEHLER" ]]; then
            soll_fehler "$nr" "$NAME_FEHLER"
            return 0
        fi
        if [[ -n "${AUSSEN[$benutzer]+x}" ]]; then
            soll_fehler "$nr" reserviert
            return 0
        fi
        if [[ -n "${T_GEN[$benutzer]+x}" ]]; then
            soll_fehler "$nr" doppelt
            return 0
        fi
        geraet_von "$benutzer" "$generation"
        if [[ -n "${T_GERAETE[$GERAET]+x}" ]]; then
            soll_fehler "$nr" geraet
            return 0
        fi
        if [[ ! "$hash" =~ $RE_HASH ]]; then
            soll_fehler "$nr" hash
            return 0
        fi
        case "$sperre" in
            -|dauerhaft) ;;
            *)
                if [[ ! "$sperre" =~ $RE_BIS ]]; then
                    soll_fehler "$nr" sperre
                    return 0
                fi
                bis=$(( 10#${BASH_REMATCH[1]} ))
                if (( bis > JETZT + SPERRE_VORLAUF_MAX )); then
                    soll_fehler "$nr" sperre
                    return 0
                fi ;;
        esac
        T_GEN[$benutzer]="$generation"
        T_HASH[$benutzer]="$hash"
        T_SPERRE[$benutzer]="$sperre"
        T_GERAETE[$GERAET]=1
    done < "$datei"
    if (( nr < 1 )); then
        soll_fehler 1 kopf
        return 0
    fi
    if (( nr < 2 )); then
        soll_fehler 2 lauf
        return 0
    fi
    SOLL_GUELTIG="ja"
}

# Kennwortdatei zerlegen: Zeilen außerhalb des Blocks (Byte für Byte nach
# $ARBEIT/aussen), deren Namen (AUSSEN) und der Block (B_GEN/B_HASH/B_SPERRE).
# PASSWD_FEHLER: leer | fehlt | marker
declare -A AUSSEN=() B_GEN=() B_HASH=() B_SPERRE=()
PASSWD_FEHLER=""
passwd_lesen() {
    local zeile name generation sperre hash
    local -i nr=0 anfang=0 ende=0 n_anfang=0 n_ende=0
    PASSWD_FEHLER=""
    AUSSEN=(); B_GEN=(); B_HASH=(); B_SPERRE=()
    if [[ ! -f "$PASSWD" ]]; then
        PASSWD_FEHLER="fehlt"
        return 0
    fi
    while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        nr+=1
        case "$zeile" in
            "$MARKE_ANFANG_KURZ"*) n_anfang+=1; anfang=$nr ;;
            "$MARKE_ENDE_KURZ"*)   n_ende+=1; ende=$nr ;;
        esac
    done < "$PASSWD"

    : > "$ARBEIT/block"
    if (( n_anfang == 0 && n_ende == 0 )); then
        cat -- "$PASSWD" > "$ARBEIT/aussen"
    elif (( n_anfang == 1 && n_ende == 1 && anfang < ende )); then
        head -n "$((anfang - 1))" -- "$PASSWD" > "$ARBEIT/aussen"
        tail -n "+$((ende + 1))" -- "$PASSWD" >> "$ARBEIT/aussen"
        head -n "$((ende - 1))" -- "$PASSWD" | tail -n "+$((anfang + 1))" > "$ARBEIT/block"
    else
        PASSWD_FEHLER="marker"
        return 0
    fi

    # Namen so lesen, wie Mosquitto es tut: Kommentar nur mit „#“ ganz vorn,
    # Zeilen ohne „:“ zählen nicht, führende „:“ überliest es, und um den
    # Namen herum fällt Leerraum weg (aus „ hand :…“ wird „hand“). Bei
    # doppelten Namen gilt in Mosquitto die *letzte* Zeile – ein Konto im
    # Block oben würde von Hand angelegte also nicht ersetzen, aber auch nie
    # wirken. Darum gelten diese Namen als reserviert.
    while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        [[ "$zeile" == \#* || "$zeile" != *:* ]] && continue
        name="${zeile#"${zeile%%[!:]*}"}"
        name="${name%%:*}"
        name="${name#"${name%%[![:space:]]*}"}"
        name="${name%"${name##*[![:space:]]}"}"
        [[ "$name" =~ $RE_NAME_ALLG ]] && AUSSEN[$name]=1
    done < "$ARBEIT/aussen"

    # Der Block stammt von diesem Helfer. Passt er nicht genau zu dem, was der
    # Helfer schreibt, wird nichts geändert (Fehler „marker“) – lieber Stillstand
    # als ein halb verstandener Zustand.
    while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        [[ -z "$zeile" ]] && continue
        if [[ "$zeile" =~ $RE_BLOCK_KONTO ]]; then
            name="${BASH_REMATCH[1]}"
            generation="${BASH_REMATCH[2]}"
            sperre="${BASH_REMATCH[3]}"
            name_pruefen "$generation" "$name"
            if [[ -n "$NAME_FEHLER" || -n "${B_GEN[$name]+x}" ]]; then
                PASSWD_FEHLER="marker"
                return 0
            fi
            if [[ "$sperre" != "-" && "$sperre" != "dauerhaft" && ! "$sperre" =~ $RE_BIS ]]; then
                PASSWD_FEHLER="marker"
                return 0
            fi
            B_GEN[$name]="$generation"
            B_SPERRE[$name]="$sperre"
        elif [[ "$zeile" == *:* && "$zeile" != \#* ]]; then
            name="${zeile%%:*}"
            hash="${zeile#*:}"
            if [[ ! "$name" =~ $RE_NAME_ALLG || ! "$hash" =~ $RE_HASH ]] || [[ -n "${B_HASH[$name]+x}" ]]; then
                PASSWD_FEHLER="marker"
                return 0
            fi
            B_HASH[$name]="$hash"
        else
            PASSWD_FEHLER="marker"
            return 0
        fi
    done < "$ARBEIT/block"
    for name in "${!B_GEN[@]}"; do
        [[ -n "${B_HASH[$name]+x}" ]] || { PASSWD_FEHLER="marker"; return 0; }
    done
    for name in "${!B_HASH[@]}"; do
        [[ -n "${B_GEN[$name]+x}" ]] || { PASSWD_FEHLER="marker"; return 0; }
    done
    return 0
}

# Grundlage prüfen. BASIS_OK, BASIS_MUSTER, SPERRE_MOEGLICH: ja | nein
BASIS_OK="nein"
BASIS_MUSTER="nein"
SPERRE_MOEGLICH="nein"
basis_pruefen() {
    local zeile muster="nein" sperre="nein"
    BASIS_OK="nein"
    BASIS_MUSTER="nein"
    SPERRE_MOEGLICH="nein"
    [[ -f "$BASIS" && -r "$BASIS" ]] || return 0
    # NUL-Bytes sähe „read“ nicht – Mosquitto schon.
    tr -d '\000' < "$BASIS" | cmp -s - "$BASIS" || return 0
    while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        [[ "$zeile" == "pattern readwrite %u/#" ]] && muster="ja"
        [[ "$zeile" == "user shelly" ]] && sperre="ja"
        [[ "$zeile" =~ $RE_LEER || "$zeile" == \#* ]] && continue
        [[ "$zeile" =~ $RE_BASIS ]] || return 0
    done < "$BASIS"
    BASIS_OK="ja"
    BASIS_MUSTER="$muster"
    SPERRE_MOEGLICH="$sperre"
}

# Genau eine acl_file-Zeile in allen Konfigurationsdateien, und sie nennt
# die wirksame Datei?
ACL_WIRKSAM="nein"
acl_wirksam_bestimmen() {
    local datei zeile wert
    local -i anzahl=0 treffer=0
    for datei in "${CONF_LISTE[@]}"; do
        [[ -f "$datei" && -r "$datei" ]] || continue
        while IFS= read -r zeile || [[ -n "$zeile" ]]; do
            if [[ "$zeile" =~ $RE_ACL_FILE ]]; then
                anzahl+=1
                wert="${BASH_REMATCH[2]}"
                wert="${wert%"${wert##*[![:space:]]}"}"
                [[ "$wert" == "$WIRKSAM" ]] && treffer+=1
            fi
        done < "$datei"
    done
    if (( anzahl == 1 && treffer == 1 )); then
        ACL_WIRKSAM="ja"
    else
        ACL_WIRKSAM="nein"
    fi
}

# Sperren, die gerade in der wirksamen Datei stehen (nach der letzten
# erzeugten Überschrift) – für Läufe, die die Datei nicht anfassen.
GESPERRT=()
gesperrt_aus_datei() {
    local zeile p drin="nein"
    GESPERRT=()
    [[ -f "$WIRKSAM" ]] || return 0
    while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        if [[ "$zeile" == "$ERZEUGT_KOPF" ]]; then
            drin="ja"
            GESPERRT=()
            continue
        fi
        if [[ "$drin" == "ja" && "$zeile" =~ $RE_DENY ]]; then
            p="${BASH_REMATCH[1]}"
            [[ "$p" =~ $RE_NAME_ALLG ]] && GESPERRT+=("$p")
        fi
    done < "$WIRKSAM"
    return 0
}

# Datei ersetzen: Temp-Datei daneben, Besitzer mosquitto, 600, umbenennen.
datei_ersetzen() {
    local quelle="$1" ziel="$2"
    OFFEN_TMP="$(mktemp "$(dirname -- "$ziel")/.mqtt-zugaenge.XXXXXX")"
    cat -- "$quelle" > "$OFFEN_TMP"
    if [[ $EUID -eq 0 ]] && id -u mosquitto >/dev/null 2>&1; then
        chown mosquitto:mosquitto -- "$OFFEN_TMP"
    fi
    chmod 600 -- "$OFFEN_TMP"
    mv -f -- "$OFFEN_TMP" "$ziel"
    OFFEN_TMP=""
}

neu_laden() {
    case "$NEU_LADEN" in
        nein) return 0 ;;
        systemd)
            if systemctl is-active --quiet mosquitto.service; then
                systemctl reload mosquitto.service
            fi ;;
        pid:*)
            kill -HUP "${NEU_LADEN#pid:}" ;;
    esac
}

# Ergebnis schreiben – als App-Benutzer, damit in storage/ nichts root gehört
# und kein Verweis dort root irgendwohin schreiben lässt.
ergebnis_schreiben() {
    local quelle="$1"
    if [[ -z "$APP_BENUTZER" ]]; then
        (
            umask 027
            t="$(mktemp "$(dirname -- "$IST")/.ergebnis.XXXXXX")" || exit 1
            if cat -- "$quelle" > "$t" && chmod 640 -- "$t" && mv -f -- "$t" "$IST"; then
                exit 0
            fi
            rm -f -- "$t"
            exit 1
        ) 2>/dev/null
    else
        setpriv --reuid="$APP_UID" --regid="$APP_GID" --init-groups -- \
            /bin/sh -c 'umask 027
                t=$(mktemp "$(dirname "$1")/.ergebnis.XXXXXX") || exit 1
                if cat > "$t" && chmod 640 "$t" && mv -f "$t" "$1"; then exit 0; fi
                rm -f "$t"; exit 1' sh "$IST" < "$quelle" > /dev/null 2>&1 9>&-
    fi
}

# ------------------------------------------------------------- eine Runde
declare -A S_GEN=() S_HASH=() S_SPERRE=()
RUNDE_STATUS=""
RUNDE_GEAENDERT="nein"
RUNDE_KENNUNG=""
FEHLER=()

runde() {
    local name p geaendert="nein" abgelehnt="nein" fehler="nein"
    local -i plus=0 minus=0 anders=0 weg=0
    local namen=() sperren=() konten=()
    printf -v JETZT '%(%s)T' -1
    FEHLER=()
    RUNDE_GEAENDERT="nein"

    soll_holen "$ARBEIT/soll"
    lauf_bestimmen "$ARBEIT/soll"
    RUNDE_KENNUNG="$(soll_kennung)"

    passwd_lesen
    if [[ "$PASSWD_FEHLER" == "fehlt" ]]; then
        echo "mqtt-zugaenge.sh: Kennwortdatei $PASSWD fehlt" >&2
        exit 1
    fi
    basis_pruefen
    acl_wirksam_bestimmen

    if [[ "$PASSWD_FEHLER" == "marker" ]]; then
        FEHLER+=("0 marker")
        RUNDE_STATUS="fehler"
        gesperrt_aus_datei
        ergebnis_bauen "$ARBEIT/ergebnis" konten GESPERRT
        ergebnis_schreiben "$ARBEIT/ergebnis" || echo "mqtt-zugaenge.sh: Ergebnis $IST ließ sich nicht schreiben" >&2
        echo "fehler: Zeile 0 (marker) – Block in $PASSWD beschädigt, nichts geändert" >&2
        return 0
    fi

    # Zustand S: gültiges Soll, sonst der aktuelle Block.
    S_GEN=(); S_HASH=(); S_SPERRE=()
    case "$SOLL_ZUSTAND" in
        da)       soll_pruefen "$ARBEIT/soll" ;;
        unlesbar) soll_fehler 0 format ;;
        fehlt)    SOLL_GUELTIG="nein"; SOLL_FEHLER="" ;;
    esac
    if [[ "$SOLL_GUELTIG" == "ja" ]]; then
        for name in "${!B_GEN[@]}"; do
            [[ -n "${T_GEN[$name]+x}" ]] || weg+=1
        done
        if (( weg > ENTFERNEN_MAX )) && [[ "$SOLL_ENTFERNEN" != "ja" && "$VIELE_ENTFERNEN" != "ja" ]]; then
            SOLL_GUELTIG="nein"
            SOLL_FEHLER="0 viele"
        fi
    fi
    if [[ "$SOLL_GUELTIG" == "ja" ]]; then
        for name in "${!T_GEN[@]}"; do
            S_GEN[$name]="${T_GEN[$name]}"
            S_HASH[$name]="${T_HASH[$name]}"
            S_SPERRE[$name]="${T_SPERRE[$name]}"
        done
    else
        for name in "${!B_GEN[@]}"; do
            S_GEN[$name]="${B_GEN[$name]}"
            S_HASH[$name]="${B_HASH[$name]}"
            S_SPERRE[$name]="${B_SPERRE[$name]}"
        done
        if [[ -n "$SOLL_FEHLER" ]]; then
            abgelehnt="ja"
            FEHLER+=("$SOLL_FEHLER")
        fi
    fi

    # Abgelaufene Prüf-Sperren gelten als „-“.
    for name in "${!S_SPERRE[@]}"; do
        if [[ "${S_SPERRE[$name]}" =~ $RE_BIS ]] && (( 10#${BASH_REMATCH[1]} <= JETZT )); then
            S_SPERRE[$name]="-"
        fi
    done

    # Neue Kennwortdatei: Block oben (nur mit Konten), dann alle übrigen
    # Zeilen unverändert.
    if (( ${#S_GEN[@]} > 0 )); then
        mapfile -t namen < <(printf '%s\n' "${!S_GEN[@]}" | sort)
    fi
    {
        if (( ${#namen[@]} > 0 )); then
            printf '%s\n' "$MARKE_ANFANG"
            for name in "${namen[@]}"; do
                printf '# konto %s %s %s\n' "$name" "${S_GEN[$name]}" "${S_SPERRE[$name]}"
            done
            for name in "${namen[@]}"; do
                printf '%s:%s\n' "$name" "${S_HASH[$name]}"
            done
            printf '%s\n' "$MARKE_ENDE"
        fi
        cat -- "$ARBEIT/aussen"
    } > "$ARBEIT/passwd.neu"

    for name in "${namen[@]}"; do
        if [[ "${S_SPERRE[$name]}" != "-" ]]; then
            sperren+=("$(praefix_von "$name" "${S_GEN[$name]}")")
        fi
    done
    if (( ${#sperren[@]} > 0 )); then
        mapfile -t sperren < <(printf '%s\n' "${sperren[@]}" | sort)
    fi

    if [[ "$BASIS_OK" != "ja" ]]; then
        # Grundlage unbrauchbar: weder wirksame ACL noch Kennwortdatei
        # anfassen – beide passen nur zusammen.
        FEHLER+=("0 basis")
        fehler="ja"
        for name in "${!B_GEN[@]}"; do
            konten+=("$name")
        done
        gesperrt_aus_datei
    else
        {
            cat -- "$BASIS"
            if [[ "$SPERRE_MOEGLICH" == "ja" ]] && (( ${#sperren[@]} > 0 )); then
                printf '\n%s\n%s\nuser shelly\n' "$ERZEUGT_KOPF" "$ERZEUGT_TEXT"
                for p in "${sperren[@]}"; do
                    printf 'topic deny %s/#\n' "$p"
                done
            fi
        } > "$ARBEIT/wirksam.neu"

        # Die alten Fassungen aufheben: Lässt sich der Broker nicht neu laden,
        # kommen sie zurück – dann stimmen Dateien, Ergebnis und Broker
        # weiter überein, und der nächste Lauf versucht es erneut.
        local passwd_ersetzt="nein" wirksam_ersetzt="nein" wirksam_alt="nein"
        if ! cmp -s -- "$ARBEIT/passwd.neu" "$PASSWD"; then
            cat -- "$PASSWD" > "$ARBEIT/passwd.alt"
            datei_ersetzen "$ARBEIT/passwd.neu" "$PASSWD"
            passwd_ersetzt="ja"
        fi
        if [[ ! -f "$WIRKSAM" ]] || ! cmp -s -- "$ARBEIT/wirksam.neu" "$WIRKSAM"; then
            if [[ -f "$WIRKSAM" ]]; then
                cat -- "$WIRKSAM" > "$ARBEIT/wirksam.alt"
                wirksam_alt="ja"
            fi
            datei_ersetzen "$ARBEIT/wirksam.neu" "$WIRKSAM"
            wirksam_ersetzt="ja"
        fi
        [[ "$passwd_ersetzt" == "ja" || "$wirksam_ersetzt" == "ja" ]] && geaendert="ja"

        if [[ "$geaendert" == "ja" ]] && ! neu_laden; then
            echo "mqtt-zugaenge.sh: Mosquitto ließ sich nicht neu laden – alter Stand wiederhergestellt" >&2
            FEHLER+=("0 neuladen")
            fehler="ja"
            geaendert="nein"
            if [[ "$passwd_ersetzt" == "ja" ]]; then
                datei_ersetzen "$ARBEIT/passwd.alt" "$PASSWD"
            fi
            # Eine wirksame Datei, die vorher fehlte, liest der Broker gerade
            # nicht – sie bleibt (ein späterer Start braucht sie).
            if [[ "$wirksam_alt" == "ja" ]]; then
                datei_ersetzen "$ARBEIT/wirksam.alt" "$WIRKSAM"
            elif [[ "$wirksam_ersetzt" == "ja" ]]; then
                RUNDE_GEAENDERT="ja"
            fi
            for name in "${!B_GEN[@]}"; do
                konten+=("$name")
            done
            gesperrt_aus_datei
        else
            if [[ "$geaendert" == "ja" ]]; then
                RUNDE_GEAENDERT="ja"
                for name in "${!S_GEN[@]}"; do
                    if [[ -z "${B_GEN[$name]+x}" ]]; then
                        plus+=1
                    elif [[ "${B_GEN[$name]}" != "${S_GEN[$name]}" || "${B_HASH[$name]}" != "${S_HASH[$name]}" \
                            || "${B_SPERRE[$name]}" != "${S_SPERRE[$name]}" ]]; then
                        anders+=1
                    fi
                done
                for name in "${!B_GEN[@]}"; do
                    [[ -n "${S_GEN[$name]+x}" ]] || minus+=1
                done
            fi
            konten=(${namen[@]+"${namen[@]}"})
            GESPERRT=()
            [[ "$SPERRE_MOEGLICH" == "ja" ]] && GESPERRT=(${sperren[@]+"${sperren[@]}"})
        fi
    fi

    if [[ "$fehler" == "ja" ]]; then
        RUNDE_STATUS="fehler"
    elif [[ "$abgelehnt" == "ja" ]]; then
        RUNDE_STATUS="abgelehnt"
    elif [[ "$geaendert" == "ja" ]]; then
        RUNDE_STATUS="ok"
    else
        RUNDE_STATUS="unveraendert"
    fi

    ergebnis_bauen "$ARBEIT/ergebnis" konten GESPERRT
    if ! ergebnis_schreiben "$ARBEIT/ergebnis"; then
        echo "mqtt-zugaenge.sh: Ergebnis $IST ließ sich nicht schreiben" >&2
        ERGEBNIS_FEHLT="ja"
    fi

    # Eine Zeile fürs Journal – Namen höchstens, nie Hashes.
    if [[ "$RUNDE_STATUS" == "fehler" || "$RUNDE_STATUS" == "abgelehnt" ]]; then
        local eintrag text=""
        for eintrag in "${FEHLER[@]}"; do
            text+="${text:+, }Zeile ${eintrag%% *} (${eintrag#* })"
        done
        echo "$RUNDE_STATUS: $text" >&2
    else
        echo "uebernommen: +$plus -$minus ~$anders Konten, ${#GESPERRT[@]} Sperren (Lauf $SOLL_LAUF)" >&2
    fi
    return 0
}

# ergebnis_bauen <datei> <name der Konten-Liste> <name der Sperren-Liste>
ergebnis_bauen() {
    local -n _konten="$2" _gesperrt="$3"
    local eintrag
    {
        printf '%s\n' "$KOPF_ERGEBNIS"
        printf 'lauf %s\n' "$SOLL_LAUF"
        printf 'zeit %s\n' "$JETZT"
        printf 'status %s\n' "$RUNDE_STATUS"
        printf 'acl_wirksam %s\n' "$ACL_WIRKSAM"
        printf 'basis_muster %s\n' "$BASIS_MUSTER"
        printf 'sperre_moeglich %s\n' "$SPERRE_MOEGLICH"
        if (( ${#_konten[@]} > 0 )); then
            printf '%s\n' "${_konten[@]}" | sort | while IFS= read -r eintrag; do
                printf 'konto %s\n' "$eintrag"
            done
        fi
        if (( ${#_gesperrt[@]} > 0 )); then
            printf '%s\n' "${_gesperrt[@]}" | sort | while IFS= read -r eintrag; do
                printf 'gesperrt %s\n' "$eintrag"
            done
        fi
        for eintrag in ${FEHLER[@]+"${FEHLER[@]}"}; do
            printf 'fehler %s\n' "$eintrag"
        done
    } > "$1"
}

# ------------------------------------------------------------- Ablauf
ERGEBNIS_FEHLT="nein"
GEAENDERT_GESAMT="nein"
for (( runde_nr = 1; runde_nr <= RUNDEN_MAX; runde_nr++ )); do
    runde
    [[ "$RUNDE_GEAENDERT" == "ja" ]] && GEAENDERT_GESAMT="ja"
    # Hat die Anwendung inzwischen ein neues Soll geschrieben? Dann gleich
    # noch eine Runde – sonst wartet sie auf den Zeitgeber.
    soll_holen "$ARBEIT/soll.neu"
    lauf_bestimmen "$ARBEIT/soll.neu"
    [[ "$(soll_kennung)" == "$RUNDE_KENNUNG" ]] && break
done

if [[ "$GEAENDERT_GESAMT" == "ja" ]]; then
    echo "geaendert"
elif [[ "$RUNDE_STATUS" == "abgelehnt" ]]; then
    echo "abgelehnt"
else
    echo "unveraendert"
fi

case "$RUNDE_STATUS" in
    fehler)    exit 1 ;;
    abgelehnt) exit 2 ;;
esac
[[ "$ERGEBNIS_FEHLT" == "ja" ]] && exit 1
exit 0
