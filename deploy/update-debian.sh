#!/usr/bin/env bash
# update-debian-schnittstelle: 1
# (Stabil: jede neue Fassung nimmt „--stufe=2“ mit Marke Format 1 von jeder älteren Stufe 1 an,
#  ebenso „--nur-abgleichen [--ohne-dienste]“, „--wiederaufnehmen“, „--ergebnis-datei=“, „--fortschritt-datei=“.)
#
# PowerManagement auf den Stand aus dem Git-Repository bringen.
#
# Das Skript sichert vorher Datenbank und Konfiguration, holt die
# Änderungen, führt die Migrationen aus, zieht fehlende Pakete nach und
# startet die Dienste neu. Geht dabei etwas schief, kommt der alte Stand
# vollständig zurück: Quellcode und Zweig, Datenbank, Einheiten und
# Vorlagen. Die Anwendung bleibt also nicht halb aktualisiert stehen.
#
# Zwei Stufen: Stufe 1 ist die laufende (alte) Fassung. Sie holt, prüft
# „nur vorwärts“, sichert, legt die Marke „offen“ an und checkt den neuen
# Stand aus. Danach übernimmt per exec Stufe 2 der *neuen* Fassung (Rechte,
# Migrationen, Pakete, Abgleich, Dienste, Prüfung) – so laufen immer die
# Schritte, die zum neuen Code gehören. Stirbt der Lauf (Strom weg, SIGKILL),
# bleibt die Marke stehen; --wiederaufnehmen rollt dann zurück (das macht
# der Update-Dienst nach dem Start selbst).
#
# Beispiele:
#   ./deploy/update-debian.sh                   aktualisieren
#   ./deploy/update-debian.sh --pruefen         nur zeigen, was sich ändern würde (holt per git, ändert sonst nichts)
#   ./deploy/update-debian.sh --flows-ersetzen  auch veränderte Node-RED-Flows ersetzen
#   ./deploy/update-debian.sh --nur-abgleichen  Einheiten und Vorlagen zum ausgecheckten Stand abgleichen
#   ./deploy/update-debian.sh --wiederaufnehmen ein unterbrochenes Update zurückrollen
#
# Exit: 0 eingespielt, aktuell oder geprüft · 1 nichts verändert ·
#       2 zurückgerollt · 3 unvollständig zurückgerollt (Handarbeit, die Marke bleibt)
#
# Unverändert bleiben: config/config.php, storage/ (Datenbank, Grundrisse,
# Protokolle), eigene Änderungen an den Node-RED-Flows, an settings.js, an
# der nginx-Site und an der Mosquitto-ACL (Grundlage powermanagement.acl).
# Neue Fassungen dieser Dateien legt das Skript daneben
# (…powermanagement-neu…) und sagt Bescheid. Die eigenen MQTT-Zugänge der
# Shellys (Block in der Kennwortdatei, wirksame ACL) gleicht
# deploy/mqtt-zugaenge.sh ab.
#
# Regel für neue Dateien außerhalb von git: jede gehört in abgleichen(), und
# eine Datei, die es vorher nicht gab, trägt neu_merken() vor dem Schreiben
# als neu_angelegt in die Marke ein – das Zurückrollen entfernt sie wieder.
# Dieses Skript stoppt powermanagement-update.service nie und startet ihn nie
# neu (das wäre ein SIGTERM an den eigenen Dienst); von der Konsole aus stößt
# es ihn nur an (start --no-block), damit Stand und Verlauf stimmen.

# -E: der ERR-Trap muss auch greifen, wenn ein Kommando in einer Funktion
# scheitert – sonst bliebe ein halb eingespieltes Update stehen.
set -Eeuo pipefail

# Unbeaufsichtigt (systemd-Dienst, Zeitplan, systemd-run) gibt es weder HOME
# noch ein Terminal. Ohne HOME brach das Update bei git ab (Befund 24). Die
# umask des Aufrufers gilt nie: Einheiten, Vorlagen und Code, die hier
# entstehen, müssen für andere lesbar sein.
: "${HOME:=/root}"
export HOME
umask 022
# git fragt nie nach (niemand tippt), und ein hängender Abruf bricht ab.
export GIT_TERMINAL_PROMPT=0 GIT_HTTP_LOW_SPEED_LIMIT=1000 GIT_HTTP_LOW_SPEED_TIME=60

ETC="/etc/powermanagement"
INSTALLATION_CONF="$ETC/installation.conf"
VORLAGEN="/var/lib/powermanagement/vorlagen"
# Nur root (700): Marke „offen“, Kopie der alten Fassung, Verlauf, gescheiterte Stände
ZUSTAND="/var/lib/powermanagement/update"
# Nur root (700): Protokolle des Update-Dienstes und des Zurückrollens
PROTOKOLLE="/var/log/powermanagement/update"
MARKE="$ZUSTAND/offen"
SITE="/etc/nginx/sites-available/powermanagement"
MOSQUITTO_CONF="/etc/mosquitto/conf.d/powermanagement.conf"
SCHNITTSTELLE_MUSTER='^# update-debian-schnittstelle:.*(^| )1( |$)'
# Feste Schritte (Fortschritt für die Oberfläche):
#   1 Stand prüfen · 2 Änderungen holen · 3 Sicherung · 4 Quellcode · 5 Rechte und Ordner ·
#   6 Migrationen · 7 Pakete · 8 Abgleichen · 9 Dienste starten · 10 Kacheln · 11 Prüfen · 12 Fertig
GESAMT=12
# Ordner in storage/, die der Anwendung gehören (750). mqtt/: Soll und
# Ergebnis der eigenen MQTT-Zugänge; update/: Aufträge an den Update-Dienst
# (soll) und dessen Rückmeldungen (ist).
APP_ORDNER=(logs plans demo cache inventar sessions backups mqtt mqtt/soll mqtt/ist update update/soll update/ist)
# So lange wartet wartung_an höchstens auf Änderungen, die vor der Schreibsperre begannen.
ANFRAGEN_WARTEN=150
# Gründe, die einen Stand als gescheitert vermerken (ein Signal ist kein Urteil über den Commit)
GESCHEITERT_GRUENDE=" checkout neue_fassung migration pakete einrichtung nginx dienste pruefung zeitueberschreitung "

# ================================================================ Grundlagen

vorgaben() {
    # Vorgaben – überschrieben von dem, was die Installation festgehalten hat.
    ZIEL="/opt/powermanagement"
    APP_USER="www-data"
    MIT_INFLUX="ja"
    MIT_NODERED="ja"
    MIT_MOSQUITTO="ja"
    NODERED_APP="/opt/node-red"
    NODERED_DIR="/var/lib/powermanagement/node-red"

    ZWEIG=""
    FLOWS_ERSETZEN="nein"
    ERZWINGEN="nein"
    NUR_ZEIGEN="nein"
    BEHALTEN=5
    GIT_CRED_FILE=""
    BIS=""
    MODUS="update"
    OHNE_DIENSTE="nein"
    ERGEBNIS_DATEI=""
    FORTSCHRITT_DATEI=""

    # Zustand des Laufs
    BEGINN="$(date +%s)"
    AKTION="aktualisieren"
    SPERRE_GEHALTEN="nein"
    ALT=""; ALT_ZWEIG="-"; ZWEIG_REF_VORHER="-"; NEU=""
    SICHERUNG="-"; SCHNAPPSCHUSS="-"; MIGRIERT="nein"; PHASE="-"
    LOGIN_VORHER="-"; PRUEFUNG_VORHER="-"; AKTIV_VORHER=(); LOGIN_CODE="000"
    PRUEF_HOST="127.0.0.1"; PRUEF_PORT="80"; PRUEF_NAME="powermanagement.lan"
    SAMMLER_ANGEHALTEN="nein"; NEU_MERKEN="nein"; ABGLEICH_BEGONNEN="nein"; NACH_SIGNAL="nein"
    WARNUNGEN=(); PRUEFUNG="-"; PRUEF_RC=1; PRUEF_AUSGABE=""
    SCHRITT_NR=0; SCHRITT_NAME="Start"; SCHRITT_GRUND="intern"
    SIGNAL=""; ZURUECK=""; ABBRUCH=""; ARBEIT=""; SPERRE_AUFGEHOBEN="nein"
    KENNUNG="-"; AUSLOESER="konsole"; VON="root"
    FPM_VERSION=""
}

installation_lesen() {
    local schluessel wert
    [[ -r "$INSTALLATION_CONF" ]] || return 0
    # Nicht per „source": nur bekannte Schlüssel übernehmen, nichts ausführen.
    while IFS='=' read -r schluessel wert; do
        case "$schluessel" in
            ZIEL|APP_USER|MIT_INFLUX|MIT_NODERED|MIT_MOSQUITTO|NODERED_APP|NODERED_DIR)
                printf -v "$schluessel" '%s' "$wert" ;;
        esac
    done < <(grep -E '^[A-Z_]+=' "$INSTALLATION_CONF" || true)
}

hilfe() {
    cat <<HILFE
Aufruf: $0 [Optionen]

  --ziel=<pfad>          Installationsverzeichnis   (Vorgabe: $ZIEL)
  --zweig=<name>         Zweig                      (Vorgabe: der ausgecheckte)
  --bis=<commit>         genau diesen Commit einspielen (40 Zeichen; nur vorwärts,
                         auf der Erst-Eltern-Linie des Zweigs)
  --git-zugang=<datei>   Token für ein privates Repository
                         (Vorgabe: /etc/powermanagement/git.conf, Inhalt GITHUB_TOKEN=…)
  --flows-ersetzen       Node-RED-Flows auch dann ersetzen, wenn sie im Editor
                         verändert wurden (die alten werden gesichert)
  --erzwingen            lokale Änderungen am Quellcode verwerfen statt abzubrechen,
                         auch auf einen Stand, der nicht vorwärts liegt
  --behalten=<anzahl>    so viele Sicherungen bleiben liegen (Vorgabe: $BEHALTEN)
  --pruefen              nur zeigen, was sich ändern würde – holt per git, ändert sonst nichts
  --nur-abgleichen       Ordner, Einheiten, Vorlagen und Konfiguration zum ausgecheckten
                         Stand abgleichen und nur Geändertes neu starten (kein git,
                         keine Migration, keine Sicherung)
  --ohne-dienste         mit --nur-abgleichen: gar nichts starten
  --wiederaufnehmen      ein unterbrochenes Update (Marke $MARKE) zurückrollen
  --ergebnis-datei=<f>   Ergebnis maschinenlesbar ablegen (Ordner: root, nur für root schreibbar)
  --fortschritt-datei=<f> aktuellen Schritt ablegen (ebenso)
  -h, --hilfe            diese Hilfe

Exit: 0 eingespielt/aktuell/geprüft · 1 nichts verändert · 2 zurückgerollt ·
      3 unvollständig (Handarbeit, siehe docs/installation-debian-lxc.md)
HILFE
}

optionsfehler() {
    echo "$1" >&2
    ergebnis nicht_begonnen intern
    exit 1
}

# Ergebnis- und Fortschrittsdatei: absolut, nur [A-Za-z0-9._/-], der Ordner
# gehört root und ist für niemanden sonst schreibbar – root legt dort Dateien
# an und benennt sie um.
ausgabe_pfad_ok() {
    local pfad="$1" ordner modus
    [[ "$pfad" =~ ^/[A-Za-z0-9._/-]+$ && "$pfad" != */ && "$pfad" != */. && "$pfad" != */.. ]] || return 1
    ordner="${pfad%/*}"
    [[ -n "$ordner" ]] || ordner="/"
    [[ -d "$ordner" ]] || return 1
    [[ "$(stat -L -c %u -- "$ordner" 2>/dev/null)" == 0 ]] || return 1
    modus="$(stat -L -c %a -- "$ordner" 2>/dev/null)" || return 1
    (( (8#$modus & 8#022) == 0 ))
}

# Zweignamen: nur, was auch in Marke und Ergebnis passt
zweig_ok() {
    [[ "$1" =~ ^[A-Za-z0-9._/+-]{1,200}$ ]] && git check-ref-format --branch "$1" >/dev/null 2>&1
}

optionen() {
    local opt wert hat_wert stufe="" wieder="nein" nur="nein"
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
            --ziel|--zweig|--git-zugang|--behalten|--bis|--ergebnis-datei|--fortschritt-datei|--stufe)
                if [[ "$hat_wert" == "nein" ]]; then
                    [[ $# -gt 0 ]] || optionsfehler "Die Option $opt braucht einen Wert."
                    wert="$1"
                    shift
                fi ;;
            *)
                [[ "$hat_wert" == "nein" ]] || optionsfehler "Die Option $opt nimmt keinen Wert." ;;
        esac
        case "$opt" in
            --ziel)            ZIEL="${wert%/}" ;;
            --zweig)           ZWEIG="$wert" ;;
            --git-zugang)      GIT_CRED_FILE="$wert" ;;
            --behalten)        BEHALTEN="$wert" ;;
            --bis)             BIS="$wert" ;;
            --flows-ersetzen)  FLOWS_ERSETZEN="ja" ;;
            --erzwingen)       ERZWINGEN="ja" ;;
            --pruefen)         NUR_ZEIGEN="ja" ;;
            --nur-abgleichen)  nur="ja" ;;
            --ohne-dienste)    OHNE_DIENSTE="ja" ;;
            --wiederaufnehmen) wieder="ja" ;;
            --ergebnis-datei)
                ausgabe_pfad_ok "$wert" || optionsfehler "--ergebnis-datei: Pfad absolut, Ordner gehört root und ist nur für root schreibbar."
                ERGEBNIS_DATEI="$wert" ;;
            --fortschritt-datei)
                ausgabe_pfad_ok "$wert" || optionsfehler "--fortschritt-datei: Pfad absolut, Ordner gehört root und ist nur für root schreibbar."
                FORTSCHRITT_DATEI="$wert" ;;
            --stufe)           stufe="$wert" ;;
            -h|--hilfe|--help) hilfe; exit 0 ;;
            *) echo "Unbekannte Option: $opt" >&2; hilfe >&2; optionsfehler "Abbruch." ;;
        esac
    done

    if [[ -n "$stufe" ]]; then
        [[ "$stufe" == 2 ]] || optionsfehler "--stufe kennt nur 2."
        MODUS="stufe2"
    fi
    [[ "$nur" == "ja" ]] && MODUS="nur_abgleichen"
    [[ "$wieder" == "ja" ]] && MODUS="wiederaufnehmen"
    local modi=0
    [[ -n "$stufe" ]] && modi=$((modi + 1))
    [[ "$nur" == "ja" ]] && modi=$((modi + 1))
    [[ "$wieder" == "ja" ]] && modi=$((modi + 1))
    [[ "$NUR_ZEIGEN" == "ja" ]] && modi=$((modi + 1))
    (( modi <= 1 )) || optionsfehler "--pruefen, --nur-abgleichen, --wiederaufnehmen und --stufe schließen sich aus."
    if [[ "$OHNE_DIENSTE" == "ja" && "$MODUS" != "nur_abgleichen" ]]; then
        optionsfehler "--ohne-dienste gilt nur mit --nur-abgleichen."
    fi
    if [[ -n "$BIS" ]]; then
        [[ "$MODUS" == "update" ]] || optionsfehler "--bis gilt nur für ein Update."
        [[ "$BIS" =~ ^[0-9a-f]{40}$ ]] || optionsfehler "--bis erwartet einen vollständigen Commit (40 Zeichen 0-9a-f)."
    fi
    [[ "$ZIEL" == /* ]] || optionsfehler "--ziel erwartet einen absoluten Pfad."
    if [[ -n "$ZWEIG" ]] && ! zweig_ok "$ZWEIG"; then
        optionsfehler "--zweig: kein gültiger Zweigname (erlaubt: A–Z a–z 0–9 . _ / + -)."
    fi
    [[ "$BEHALTEN" =~ ^[1-9][0-9]*$ ]] || optionsfehler "--behalten erwartet eine Zahl ab 1."
    [[ "$NUR_ZEIGEN" == "ja" ]] && AKTION="pruefen"
    [[ "$MODUS" == "wiederaufnehmen" ]] && AKTION="wiederaufnehmen"
    return 0
}

umgebung_pruefen() {
    if [[ $EUID -ne 0 ]]; then
        echo "Bitte als root ausführen (oder mit sudo)." >&2
        ergebnis nicht_begonnen intern
        exit 1
    fi
    if [[ ! -d /run/systemd/system ]] || ! command -v systemctl >/dev/null 2>&1; then
        echo "Hier läuft kein systemd – PowerManagement braucht es." >&2
        ergebnis nicht_begonnen intern
        exit 1
    fi
}

# Wer hat den Lauf ausgelöst? Der Update-Dienst gibt es in der Umgebung mit
# (env -i, nur diese Werte); von der Konsole: konsole und der Benutzer hinter
# sudo. Nur zur Anzeige und für den Verlauf – nie in einer Entscheidung.
herkunft_lesen() {
    local v
    KENNUNG="-"
    AUSLOESER="konsole"
    if [[ "${PM_UPDATE_KENNUNG:-}" =~ ^[0-9a-f]{32}$ ]]; then
        KENNUNG="$PM_UPDATE_KENNUNG"
    fi
    case "${PM_UPDATE_AUSLOESER:-}" in
        hand|automatik|konsole|system) AUSLOESER="$PM_UPDATE_AUSLOESER" ;;
    esac
    v="${PM_UPDATE_VON:-${SUDO_USER:-root}}"
    v="$(printf '%s' "$v" | LC_ALL=C tr -cd 'A-Za-z0-9._@-' | cut -c1-64)"
    VON="${v:-root}"
}

farben() {
    # Farben nur am Terminal – im Journal und in Protokolldateien stünden
    # sonst Steuerzeichen (journalctl zeigt solche Zeilen ohne -a als „blob data“).
    if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
        F_S=$'\033[1;34m'; F_H=$'\033[1;33m'; F_0=$'\033[0m'
    else
        F_S=""; F_H=""; F_0=""
    fi
}

# schritt <nr> <name> [<grund-code>]: Überschrift, Fortschritt für die
# Oberfläche, und der Grund, unter dem ein Fehler in diesem Schritt läuft.
schritt() {
    SCHRITT_NR="$1"
    SCHRITT_NAME="$2"
    SCHRITT_GRUND="${3:-intern}"
    printf '\n%s==> %s%s\n' "$F_S" "$2" "$F_0"
    fortschritt_schreiben
}
hinweis() { printf '%s    %s%s\n' "$F_H" "$1" "$F_0" >&2; }

# Nie ein Fehler: der Fortschritt ist nur Anzeige.
fortschritt_schreiben() {
    local t
    [[ -n "$FORTSCHRITT_DATEI" ]] || return 0
    t="$(mktemp "${FORTSCHRITT_DATEI%/*}/.fortschritt.XXXXXX" 2>/dev/null)" || return 0
    if printf 'schritt %s %s %s\n' "$SCHRITT_NR" "$GESAMT" "$SCHRITT_NAME" > "$t" 2>/dev/null; then
        mv -f -- "$t" "$FORTSCHRITT_DATEI" 2>/dev/null || rm -f -- "$t"
    else
        rm -f -- "$t"
    fi
    return 0
}

# Die Sperren (fd 8, fd 9) bekommt der Anwendungsbenutzer nicht mit: ein
# Prozess, der den Deskriptor erbt und weiterläuft, hielte sie fest.
as_app() {
    (cd "$ZIEL" && runuser -u "$APP_USER" -- "$@") 8>&- 9>&-
}

# Ein Verzeichnis in storage/ anlegen – als Anwendungsbenutzer, nicht als
# root (siehe install-debian.sh: ein Verweis in storage/ soll root nicht dazu
# bringen, dessen Ziel der Anwendung zu übereignen).
app_verzeichnis() {
    as_app mkdir -p -- "$1"
    as_app chmod "${2:-750}" -- "$1"
}

# Eine Datei als Anwendungsbenutzer schreiben (Inhalt von stdin): Temp-Datei
# im selben Ordner, dann umbenennen – nie halb geschrieben, und root folgt
# dort keinem Verweis.
als_app_schreiben() {
    as_app /bin/sh -c 'umask 027; t="$(mktemp "${1%/*}/.neu.XXXXXX")" || exit 1
        if cat > "$t" && chmod 640 "$t" && mv -f -- "$t" "$1"; then exit 0; fi
        rm -f -- "$t"; exit 1' sh "$1"
}

# git als root im Checkout: safe.directory gilt nur für diesen Aufruf (keine
# globale Einstellung – ohne HOME bräche „git config --global“ ab), und
# Hooks aus .git laufen nie (core.hooksPath=/dev/null).
g() {
    git -c safe.directory="$ZIEL" -c core.hooksPath=/dev/null -C "$ZIEL" "$@"
}

# Ausgaben von git ohne Benutzerteil in Adressen – stünden Zugangsdaten in
# einer Remote-Adresse, kämen sie sonst ins Protokoll.
ohne_zugangsdaten() { sed -E 's#(://)[^/@[:space:]]+@#\1#g'; }

# Trägt die Datei die Schnittstellenzeile (in den ersten zehn Zeilen)?
hat_schnittstelle() {
    local zeile n=0
    [[ -f "$1" ]] || return 1
    while IFS= read -r zeile && (( ++n <= 10 )); do
        [[ "$zeile" =~ $SCHNITTSTELLE_MUSTER ]] && return 0
    done < "$1"
    return 1
}

kurz() { if [[ "$1" =~ ^[0-9a-f]{7} ]]; then printf '%s' "${1:0:7}"; else printf '%s' "-"; fi; }

fpm_version() {
    find /etc/php -mindepth 2 -maxdepth 2 -type d -name fpm 2>/dev/null \
        | awk -F/ '{print $4}' | sort -V | tail -1 || true
}

# apt unbeaufsichtigt: auf die dpkg-Sperre warten (apt-daily und
# unattended-upgrades laufen im Hintergrund), bei geänderten Conffiles nie
# fragen, sondern die vorhandene Fassung behalten. „apt-get update“ wartet
# trotz Lock::Timeout nicht auf die Sperre der Paketlisten – daher die Schleife.
APT=(apt-get -o DPkg::Lock::Timeout=600 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)
apt_holen() {
    local n=0
    until "${APT[@]}" update -qq; do
        if (( ++n >= 20 )); then
            hinweis "apt-get update scheitert weiter – Netz und Paketquellen prüfen."
            return 1
        fi
        hinweis "apt ist belegt oder nicht erreichbar – neuer Versuch in 15 s"
        sleep 15
    done
}

# Arbeitsordner für Temp-Dateien (root 700) – nicht in /tmp, und weg, wenn
# der Lauf endet (Befund 23).
arbeit_anlegen() {
    [[ -n "$ARBEIT" && -d "$ARBEIT" ]] && return 0
    install -d -m 700 "$ZUSTAND"
    ARBEIT="$(mktemp -d "$ZUSTAND/.arbeit.XXXXXX")"
    trap 'arbeit_aufraeumen' EXIT
}
arbeit_aufraeumen() {
    if [[ -n "$ARBEIT" && "$ARBEIT" == "$ZUSTAND/.arbeit."* ]]; then
        rm -rf -- "$ARBEIT"
    fi
    ARBEIT=""
    return 0
}

# ================================================================ Sperre
# Zwei Läufe gleichzeitig (etwa von Hand und aus dem Update-Dienst) würden
# sich gegenseitig die Datenbank zurückrollen. Die Sperrdatei liegt in
# /etc/powermanagement (nur root), nicht im für alle beschreibbaren
# /run/lock: Dort könnte ein anderer Benutzer vorher einen Verweis unter
# diesem Namen anlegen, dessen Ziel root dann beim Öffnen leert. Dieselbe
# Sperre nimmt deploy/install-debian.sh. Mit PM_UPDATE_SPERRE=geerbt hält
# sie schon der Aufrufer (fd 9 über exec bzw. an das Kind vererbt): dann den
# Deskriptor nicht neu öffnen – das gäbe sie kurz frei.
sperre_nehmen() {
    install -d -m 750 "$ETC"
    if [[ "${PM_UPDATE_SPERRE:-}" == "geerbt" ]]; then
        flock -n 9 2>/dev/null || return 1
    else
        exec 9>>"$ETC/update.lock"
        chmod 600 "$ETC/update.lock"
        flock -n 9 || return 1
    fi
    SPERRE_GEHALTEN="ja"
}

besetzt() {
    echo "Ein anderes Update läuft gerade." >&2
    ergebnis nicht_begonnen besetzt
    exit 1
}

# ================================================================ Ergebnis und Verlauf

# ergebnis <ergebnis> <grund|-> [<satz>]: Ergebnisdatei (falls verlangt),
# Zeile im Verlauf (nicht bei --pruefen) und die letzte Zeile des Laufs.
ergebnis() {
    local erg="$1" grund="${2:--}" satz="${3:-}" neu_jetzt="-" teil
    case "$erg" in
        eingespielt|eingespielt_mit_warnung) neu_jetzt="${NEU:--}" ;;
    esac
    ergebnis_datei_schreiben "$erg" "$grund" "$neu_jetzt"
    verlauf_schreiben "$erg" "$grund" "$neu_jetzt"
    teil="$erg"
    [[ "$grund" != "-" ]] && teil="$teil ($grund)"
    # alt → neu, sobald es ein anderes Ziel gab; sonst der Stand (oder nur der Satz)
    if [[ -n "$ALT" && -n "$NEU" && "$NEU" != "$ALT" ]]; then
        teil="$teil – $(kurz "$ALT") → $(kurz "$NEU")"
    elif [[ -z "$satz" && -n "$ALT" ]]; then
        satz="Stand $(kurz "$ALT")"
    fi
    [[ -n "$satz" ]] && teil="$teil – $satz"
    printf '\n==> Ergebnis: %s\n' "$teil"
    return 0
}

ergebnis_datei_schreiben() {
    local erg="$1" grund="$2" neu_jetzt="$3" t w zweig="-" sicherung="-"
    [[ -n "$ERGEBNIS_DATEI" ]] || return 0
    [[ -n "$ZWEIG" ]] && zweig_ok "$ZWEIG" && zweig="$ZWEIG"
    [[ "$SICHERUNG" != "-" ]] && sicherung="${SICHERUNG##*/}"
    t="$(mktemp "${ERGEBNIS_DATEI%/*}/.ergebnis.XXXXXX" 2>/dev/null)" || return 0
    {
        echo "# powermanagement update-ergebnis 1"
        echo "ergebnis $erg"
        echo "grund $grund"
        echo "alt ${ALT:--}"
        echo "neu $neu_jetzt"
        echo "zweig $zweig"
        echo "sicherung $sicherung"
        echo "pruefung $PRUEFUNG"
        for w in ${WARNUNGEN[@]+"${WARNUNGEN[@]}"}; do
            echo "warnung $w"
        done
    } > "$t" 2>/dev/null || { rm -f -- "$t"; return 0; }
    chmod 600 "$t" 2>/dev/null
    mv -f -- "$t" "$ERGEBNIS_DATEI" 2>/dev/null || rm -f -- "$t"
    return 0
}

# Jeder echte Lauf (auch von der Konsole) kommt in den Verlauf – reine
# Suchen nicht, und nur, wer die Sperre hält, schreibt hinein.
verlauf_schreiben() {
    local erg="$1" grund="$2" neu_jetzt="$3" t sicherung="-" verlauf="$ZUSTAND/verlauf"
    [[ "$SPERRE_GEHALTEN" == "ja" && "$AKTION" != "pruefen" && "$MODUS" != "nur_abgleichen" ]] || return 0
    [[ "$SICHERUNG" != "-" ]] && sicherung="${SICHERUNG##*/}"
    install -d -m 700 "$ZUSTAND" 2>/dev/null || return 0
    t="$(mktemp "$ZUSTAND/.verlauf.XXXXXX" 2>/dev/null)" || return 0
    {
        [[ -f "$verlauf" ]] && tail -n 499 -- "$verlauf"
        printf 'eintrag %s %s %s %s %s %s %s %s %s %s %s\n' "$BEGINN" "$(date +%s)" "$KENNUNG" "$AUSLOESER" "$VON" \
            "$AKTION" "$erg" "$grund" "${ALT:--}" "$neu_jetzt" "$sicherung"
    } > "$t" 2>/dev/null || { rm -f -- "$t"; return 0; }
    chmod 600 "$t" 2>/dev/null
    mv -f -- "$t" "$verlauf" 2>/dev/null || rm -f -- "$t"
    return 0
}

# gescheitert_merken <sha40> <grund>: die Automatik versucht diesen Stand nie wieder
gescheitert_merken() {
    local sha="$1" grund="$2" t datei="$ZUSTAND/gescheitert"
    [[ "$sha" =~ ^[0-9a-f]{40}$ && "$GESCHEITERT_GRUENDE" == *" $grund "* ]] || return 0
    install -d -m 700 "$ZUSTAND" 2>/dev/null || return 0
    t="$(mktemp "$ZUSTAND/.gescheitert.XXXXXX" 2>/dev/null)" || return 0
    {
        [[ -f "$datei" ]] && tail -n 19 -- "$datei"
        printf '%s %s %s\n' "$sha" "$(date +%s)" "$grund"
    } > "$t" 2>/dev/null || { rm -f -- "$t"; return 0; }
    chmod 600 "$t" 2>/dev/null
    mv -f -- "$t" "$datei" 2>/dev/null || rm -f -- "$t"
    return 0
}

# ================================================================ Marke „offen“
# Zugleich die Schnittstelle Stufe 1 → Stufe 2 (Format 1). Gelesen wird nur
# über eine Liste bekannter Schlüssel, jeder Wert gegen ein Muster, nie per
# source. Unbekannte Schlüssel (einer neueren Fassung) werden überlesen.

marke_schreiben() {
    local t
    install -d -m 700 "$ZUSTAND"
    t="$(mktemp "$ZUSTAND/.offen.XXXXXX")"
    {
        echo "# powermanagement update-offen 1"
        echo "phase stufe1"
        echo "zeit $BEGINN"
        echo "kennung $KENNUNG"
        echo "ausloeser $AUSLOESER"
        echo "von $VON"
        echo "alt $ALT"
        echo "alt_zweig $ALT_ZWEIG"
        echo "zweig $ZWEIG"
        echo "zweig_ref_vorher $ZWEIG_REF_VORHER"
        echo "neu $NEU"
        echo "alt_skript $ZUSTAND/alt/update-debian.sh"
        echo "sicherung $SICHERUNG"
        echo "schnappschuss $SCHNAPPSCHUSS"
        echo "migriert nein"
        echo "login_vorher $LOGIN_VORHER"
        echo "pruefung_vorher $PRUEFUNG_VORHER"
        if [[ ${#AKTIV_VORHER[@]} -gt 0 ]]; then echo "aktiv_vorher ${AKTIV_VORHER[*]}"; else echo "aktiv_vorher -"; fi
        echo "flows_ersetzen $FLOWS_ERSETZEN"
        echo "ergebnis_datei ${ERGEBNIS_DATEI:--}"
        echo "fortschritt_datei ${FORTSCHRITT_DATEI:--}"
    } > "$t"
    chmod 600 "$t"
    mv -f -- "$t" "$MARKE"
    PHASE="stufe1"
}

# marke_setzen <schlüssel> <wert>: einen Wert ersetzen (tmp + mv)
marke_setzen() {
    local schluessel="$1" wert="$2" t zeile gesetzt="nein"
    t="$(mktemp "$ZUSTAND/.offen.XXXXXX")" || return 1
    while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        if [[ "$zeile" == "$schluessel "* ]]; then
            [[ "$gesetzt" == "nein" ]] && printf '%s %s\n' "$schluessel" "$wert"
            gesetzt="ja"
        else
            printf '%s\n' "$zeile"
        fi
    done < "$MARKE" > "$t" || { rm -f -- "$t"; return 1; }
    [[ "$gesetzt" == "ja" ]] || printf '%s %s\n' "$schluessel" "$wert" >> "$t"
    chmod 600 "$t"
    mv -f -- "$t" "$MARKE"
    [[ "$schluessel" == "phase" ]] && PHASE="$wert"
    return 0
}

# marke_hinzufuegen <schlüssel> <wert>: eine weitere Zeile (neu_angelegt)
marke_hinzufuegen() {
    local t
    t="$(mktemp "$ZUSTAND/.offen.XXXXXX")" || return 1
    { cat -- "$MARKE" && printf '%s %s\n' "$1" "$2"; } > "$t" || { rm -f -- "$t"; return 1; }
    chmod 600 "$t"
    mv -f -- "$t" "$MARKE"
}

marke_loeschen() { rm -f -- "$MARKE"; }

# Was das Zurückrollen entfernen darf: nur Dateien der Anwendung, nie die
# Einheiten des Update-Dienstes selbst (disable --now stoppte den eigenen Dienst).
neu_angelegt_erlaubt() {
    local p="$1"
    if [[ "$p" =~ ^/etc/systemd/system/powermanagement-[a-z0-9-]+\.(service|timer|path)$ \
          && "$p" != /etc/systemd/system/powermanagement-update.* ]]; then
        return 0
    fi
    [[ "$p" =~ ^/etc/logrotate\.d/powermanagement[a-z0-9-]*$ ]] && return 0
    [[ "$p" =~ ^/etc/(nginx|mosquitto|php)/[^[:space:]]*powermanagement[^/[:space:]]*$ ]] && return 0
    return 1
}

# neu_merken <pfad>: in Stufe 2 eine Datei, die es vor dem Update nicht gab,
# vor dem Schreiben in die Marke – die alte Fassung kennt sie nicht und
# entfernte sie beim Abgleich nie.
neu_merken() {
    [[ "$NEU_MERKEN" == "ja" && -f "$MARKE" ]] || return 0
    [[ -e "$1" || -L "$1" ]] && return 0
    neu_angelegt_erlaubt "$1" || return 0
    marke_hinzufuegen neu_angelegt "$1"
}

# Mit --flows-ersetzen beiseitegelegte eigene Flows (flows.json.vor-<zeit>):
# nur dieser Name, nur in NODERED_DIR.
verschoben_erlaubt() {
    [[ "$1" == "$NODERED_DIR/flows.json.vor-"* && "${1#"$NODERED_DIR/flows.json.vor-"}" =~ ^[0-9]{8}-[0-9]{6}$ ]]
}

# verschoben_merken <pfad>: in Stufe 2 vor dem Umbenennen in die Marke
verschoben_merken() {
    [[ "$NEU_MERKEN" == "ja" && -f "$MARKE" ]] || return 0
    verschoben_erlaubt "$1" || return 0
    marke_hinzufuegen verschoben "$1"
}

# marke_lesen: setzt M_*; rc 1, wenn Kopf, Form oder ein Wert nicht passt
marke_lesen() {
    local zeile schluessel wert n=0
    M_PHASE=""; M_ZEIT=""; M_KENNUNG="-"; M_AUSLOESER="konsole"; M_VON="root"
    M_ALT=""; M_ALT_ZWEIG=""; M_ZWEIG=""; M_ZWEIG_REF_VORHER="-"; M_NEU=""
    M_SICHERUNG="-"; M_SCHNAPPSCHUSS="-"; M_MIGRIERT="nein"
    M_LOGIN_VORHER="-"; M_PRUEFUNG_VORHER="-"; M_AKTIV_VORHER="-"; M_FLOWS_ERSETZEN="nein"
    M_ERGEBNIS_DATEI="-"; M_FORTSCHRITT_DATEI="-"; M_NEU_ANGELEGT=(); M_VERSCHOBEN=()
    [[ -f "$MARKE" && ! -L "$MARKE" ]] || return 1
    while IFS= read -r zeile || [[ -n "$zeile" ]]; do
        n=$((n + 1))
        if (( n == 1 )); then
            [[ "$zeile" == "# powermanagement update-offen 1" ]] || return 1
            continue
        fi
        [[ "$zeile" =~ ^([a-z_]+)\ (.+)$ ]] || return 1
        schluessel="${BASH_REMATCH[1]}"
        wert="${BASH_REMATCH[2]}"
        case "$schluessel" in
            phase)            [[ "$wert" =~ ^(stufe1|checkout|stufe2|zurueck)$ ]] || return 1; M_PHASE="$wert" ;;
            zeit)             [[ "$wert" =~ ^[0-9]{1,12}$ ]] || return 1; M_ZEIT="$wert" ;;
            kennung)          [[ "$wert" =~ ^([0-9a-f]{32}|-)$ ]] || return 1; M_KENNUNG="$wert" ;;
            ausloeser)        [[ "$wert" =~ ^(hand|automatik|konsole|system)$ ]] || return 1; M_AUSLOESER="$wert" ;;
            von)              [[ "$wert" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] || return 1; M_VON="$wert" ;;
            alt)              [[ "$wert" =~ ^[0-9a-f]{40}$ ]] || return 1; M_ALT="$wert" ;;
            neu)              [[ "$wert" =~ ^[0-9a-f]{40}$ ]] || return 1; M_NEU="$wert" ;;
            alt_zweig)        [[ "$wert" == "-" ]] || zweig_ok "$wert" || return 1; M_ALT_ZWEIG="$wert" ;;
            zweig)            zweig_ok "$wert" || return 1; M_ZWEIG="$wert" ;;
            zweig_ref_vorher) [[ "$wert" =~ ^([0-9a-f]{40}|-)$ ]] || return 1; M_ZWEIG_REF_VORHER="$wert" ;;
            alt_skript)       # wählt der Update-Dienst fürs Wiederaufnehmen – hier nur geprüft
                [[ "$wert" == "-" || "$wert" =~ ^/var/lib/powermanagement/update/alt/[A-Za-z0-9._-]+$ ]] || return 1 ;;
            sicherung)
                [[ "$wert" == "-" || ( "$wert" == "$ZIEL/storage/backups/"* \
                    && "${wert#"$ZIEL/storage/backups/"}" =~ ^vor-update-[0-9-]+\.tar\.gz$ ) ]] || return 1
                M_SICHERUNG="$wert" ;;
            schnappschuss)
                [[ "$wert" == "-" || ( "$wert" == "$ZIEL/storage/backups/"* \
                    && "${wert#"$ZIEL/storage/backups/"}" =~ ^\.schnappschuss-[0-9-]+/database\.sqlite$ ) ]] || return 1
                M_SCHNAPPSCHUSS="$wert" ;;
            migriert)         [[ "$wert" =~ ^(ja|nein)$ ]] || return 1; M_MIGRIERT="$wert" ;;
            login_vorher)     [[ "$wert" =~ ^([0-9]{3}|-)$ ]] || return 1; M_LOGIN_VORHER="$wert" ;;
            pruefung_vorher)  [[ "$wert" =~ ^(ok|fehler|-)$ ]] || return 1; M_PRUEFUNG_VORHER="$wert" ;;
            aktiv_vorher)     [[ "$wert" =~ ^(-|[a-z0-9@._-]+( [a-z0-9@._-]+)*)$ ]] || return 1; M_AKTIV_VORHER="$wert" ;;
            flows_ersetzen)   [[ "$wert" =~ ^(ja|nein)$ ]] || return 1; M_FLOWS_ERSETZEN="$wert" ;;
            # Nur die Form: nach einem Absturz gibt es den Ordner in /run nicht
            # mehr – die Marke muss trotzdem lesbar bleiben. Ob der Ordner noch
            # root allein gehört, prüft stufe2_eintritt beim Übernehmen.
            ergebnis_datei)   [[ "$wert" =~ ^(-|/[A-Za-z0-9._/-]+)$ ]] || return 1; M_ERGEBNIS_DATEI="$wert" ;;
            fortschritt_datei) [[ "$wert" =~ ^(-|/[A-Za-z0-9._/-]+)$ ]] || return 1; M_FORTSCHRITT_DATEI="$wert" ;;
            neu_angelegt)     neu_angelegt_erlaubt "$wert" || return 1; M_NEU_ANGELEGT+=("$wert") ;;
            verschoben)       verschoben_erlaubt "$wert" || return 1; M_VERSCHOBEN+=("$wert") ;;
            *) ;;   # unbekannt: von einer neueren Fassung – überlesen
        esac
    done < "$MARKE"
    # Was eine ältere Stufe 1 nicht schreibt, bekommt eine Vorgabe.
    [[ -n "$M_ALT_ZWEIG" ]] || M_ALT_ZWEIG="$M_ZWEIG"
    [[ -n "$M_PHASE" && -n "$M_ZEIT" && -n "$M_ALT" && -n "$M_NEU" && -n "$M_ZWEIG" ]]
}

# Werte der Marke in den Lauf übernehmen (Stufe 2, --wiederaufnehmen)
marke_uebernehmen() {
    PHASE="$M_PHASE"; BEGINN="$M_ZEIT"
    KENNUNG="$M_KENNUNG"
    ALT="$M_ALT"; ALT_ZWEIG="$M_ALT_ZWEIG"; ZWEIG="$M_ZWEIG"; ZWEIG_REF_VORHER="$M_ZWEIG_REF_VORHER"; NEU="$M_NEU"
    SICHERUNG="$M_SICHERUNG"; SCHNAPPSCHUSS="$M_SCHNAPPSCHUSS"; MIGRIERT="$M_MIGRIERT"
    LOGIN_VORHER="$M_LOGIN_VORHER"; PRUEFUNG_VORHER="$M_PRUEFUNG_VORHER"; FLOWS_ERSETZEN="$M_FLOWS_ERSETZEN"
    AKTIV_VORHER=()
    if [[ "$M_AKTIV_VORHER" != "-" ]]; then
        read -r -a AKTIV_VORHER <<< "$M_AKTIV_VORHER"
    fi
}

# ================================================================ Schreibsperre
# Solange storage/update/ist/wartung steht, nimmt die Oberfläche keine
# Änderungen an – was zwischen Sicherung und Zurückrollen geschrieben würde,
# ginge sonst verloren (N10).
#
# Was vor der Sperre hereinkam, darf vor der Sicherung zu Ende schreiben:
# Jede schreibende Anfrage hält storage/update/.anfragen geteilt gesperrt,
# solange sie läuft – angemeldet, bevor sie nach der Sperre fragt
# (UpdateService::anfrageAnmelden()). Exklusiv bekommt die Datei hier also
# erst, wer keine solche Anfrage mehr neben sich hat. Ein fester Abstand
# reichte nicht: eine Übernahme von einer anderen Instanz oder aus git, ein
# PDF-Grundriss oder ein MQTT-Zugang warten bis zu zwei Minuten, bevor sie
# schreiben. Länger wird nicht gewartet – wer erst danach schreibt, fragt die
# Sperre vorher noch einmal (LocationUebertrag::anwenden()).
wartung_an() {
    local rc=0 anfragen="$ZIEL/storage/update/.anfragen"
    if ! as_app test -d "$ZIEL/storage/update/ist"; then
        hinweis "storage/update/ist fehlt – die Oberfläche bleibt während dieses Updates beschreibbar (wird jetzt eingerichtet)."
        return 0
    fi
    printf '# powermanagement update-wartung 1\nseit %s\nkennung %s\n' "$(date +%s)" "$KENNUNG" \
        | als_app_schreiben "$ZIEL/storage/update/ist/wartung"
    echo "    Schreibsperre der Oberfläche an"
    # 75: belegt bzw. Zeit um; jeder andere Fehler: die Datei geht nicht auf.
    as_app flock -E 75 -n -x "$anfragen" true 2>/dev/null || rc=$?
    if [[ "$rc" == 75 ]]; then
        echo "    Warte auf laufende Änderungen (höchstens ${ANFRAGEN_WARTEN} s) …"
        rc=0
        as_app flock -E 75 -w "$ANFRAGEN_WARTEN" -x "$anfragen" true 2>/dev/null || rc=$?
        case "$rc" in
            0) echo "    Laufende Änderungen sind fertig" ;;
            75) hinweis "Nach ${ANFRAGEN_WARTEN} s laufen noch Anfragen – was sie jetzt noch schreiben, kann ein Zurückrollen verwerfen." ;;
        esac
    fi
    case "$rc" in
        0|75) ;;
        *) sleep 2 ;;   # ohne Anmeldedatei: laufende Anfragen enden lassen wie früher
    esac
    return 0
}
wartung_aus() {
    if as_app test -e "$ZIEL/storage/update/ist/wartung" 2>/dev/null; then
        as_app rm -f -- "$ZIEL/storage/update/ist/wartung" && echo "    Schreibsperre der Oberfläche aus" && SPERRE_AUFGEHOBEN="ja"
    fi
    return 0
}

# Den Schnappschuss als Anwendungsbenutzer an die Stelle der Datenbank:
# harter Verweis im selben Dateisystem (braucht keinen Platz, öffnet nichts –
# eine untergeschobene FIFO hielte cp auf, und der Schnappschuss bleibt für
# einen weiteren Versuch), sonst kopieren mit Zeitlimit. Erst wenn die Kopie
# bereitliegt, -wal/-shm weg und umbenennen – keine alte WAL in der neuen Datei.
# Ist die Datenbank schon derselbe Verweis wie der Schnappschuss, liegt sie
# schon zurück (ein Absturz, bevor „migriert nein“ in der Marke stand): dann
# nichts anfassen. mv bräche dort mit „the same file“ ab – nachdem -wal/-shm
# schon weg wären, mit dem, was seitdem gespeichert wurde.
db_zuruecklegen() {
    as_app /bin/sh -c '[ -f "$1" ] && [ ! -L "$1" ] || exit 1
        [ "$1" -ef "$2" ] && exit 0
        rm -f -- "$2.zurueck" || exit 1
        ln -- "$1" "$2.zurueck" 2>/dev/null || timeout -s KILL 600 cp -- "$1" "$2.zurueck" || exit 1
        rm -f -- "$2-wal" "$2-shm" && mv -f -- "$2.zurueck" "$2"' sh "$SCHNAPPSCHUSS" "$DB"
}

schnappschuss_loeschen() {
    local ordner
    [[ -n "$SCHNAPPSCHUSS" && "$SCHNAPPSCHUSS" != "-" ]] || return 0
    ordner="${SCHNAPPSCHUSS%/database.sqlite}"
    [[ "$ordner" == "$ZIEL/storage/backups/.schnappschuss-"* ]] || return 0
    as_app rm -rf -- "$ordner" || true
    return 0
}

# ================================================================ Zugangsdaten für git
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
        ergebnis nicht_begonnen kein_zugang
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

# Geschrieben wird nur, was abweicht – auch --pruefen ruft das auf und soll
# sonst nichts verändern (N1).
git_zugang_ablegen() {
    local repo="${1:-}" schema="https" host="github.com" benutzer zeile
    if [[ "$repo" == git@* || "$repo" == ssh://* ]]; then
        return 0
    fi
    if [[ -z "$GIT_CRED_LINE" && -z "$GIT_TOKEN" ]]; then
        echo "In $GIT_CRED_FILE steht kein Token (erwartet: GITHUB_TOKEN=...)." >&2
        ergebnis nicht_begonnen kein_zugang
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
    if [[ ! -f "$GIT_STORE" || -L "$GIT_STORE" || "$(stat -c %a -- "$GIT_STORE")" != 600 ]] \
        || [[ "$(cat -- "$GIT_STORE")" != "$zeile" ]]; then
        install -m 600 /dev/null "$GIT_STORE"
        printf '%s\n' "$zeile" > "$GIT_STORE"
    fi
    if [[ "$(stat -c %a -- "$GIT_CRED_FILE" 2>/dev/null || true)" != 600 ]]; then
        chmod 600 "$GIT_CRED_FILE" 2>/dev/null || true
    fi
}

git_helper_setzen() {
    [[ -f "$GIT_STORE" ]] || return 0
    if [[ "$(g config --get credential.helper 2>/dev/null || true)" != "store --file=$GIT_STORE" ]]; then
        g config credential.helper "store --file=$GIT_STORE"
    fi
}

# ================================================================ Nachholen

# Eigene MQTT-Zugänge noch nicht eingerichtet (wirksame ACL oder Einheiten
# fehlen)? Das kommt vor, wenn der Code schon neu ist, aber ein älteres
# Update-Skript ihn geholt hat – das kannte diese Schritte noch nicht. Ebenso,
# wenn Mosquitto noch die Übergangsfassung liest: die installierte
# Konfiguration ist unverändert die zuletzt ausgelieferte (gemerkte Vorlage),
# aber nicht broker.conf – etwa nach einem zurückgerollten Update.
mqtt_nachholen_pruefen() {
    local merk
    MQTT_NACHHOLEN="nein"
    [[ "$MIT_MOSQUITTO" == "ja" ]] || return 0
    if [[ -f "$ZIEL/deploy/mqtt-zugaenge.sh" ]] \
        && { [[ ! -f /etc/mosquitto/powermanagement-wirksam.acl ]] \
             || [[ ! -f /etc/systemd/system/powermanagement-mqtt-zugaenge.path ]]; }; then
        MQTT_NACHHOLEN="ja"
    fi
    merk="$VORLAGEN/$(printf '%s' "$MOSQUITTO_CONF" | tr '/' '_')"
    if [[ -f "$ZIEL/deploy/mosquitto/broker.conf" && -f "$merk" && -f "$MOSQUITTO_CONF" ]] \
        && cmp -s "$merk" "$MOSQUITTO_CONF" && ! cmp -s "$ZIEL/deploy/mosquitto/broker.conf" "$MOSQUITTO_CONF"; then
        MQTT_NACHHOLEN="ja"
    fi
    return 0
}

# Einrichtung unvollständig (N3): eine Einheit der Liste liegt in deploy/,
# fehlt aber in /etc/systemd/system, oder ein Ordner fehlt. Das kommt vor,
# wenn ein älteres Update-Skript den neuen Stand geholt hat – es kannte die
# neuen Schritte nicht. Dann richtet dieser Lauf sie auch ohne neuen Code ein.
einrichtung_nachholen_pruefen() {
    local e d
    EINRICHTUNG_NACHHOLEN="nein"
    while IFS= read -r e; do
        if [[ -f "$ZIEL/deploy/$e" && ! -f "/etc/systemd/system/$e" ]]; then
            EINRICHTUNG_NACHHOLEN="ja"
        fi
    done < <(einheiten)
    for d in "$ZIEL/storage/update" "$ZIEL/storage/update/soll" "$ZIEL/storage/update/ist" "$ZUSTAND" "$PROTOKOLLE"; do
        if [[ ! -d "$d" ]]; then
            EINRICHTUNG_NACHHOLEN="ja"
        fi
    done
    return 0
}

# ================================================================ Rechte und Ordner

ordner_anlegen() {
    local d
    for d in "${APP_ORDNER[@]}"; do
        app_verzeichnis "$ZIEL/storage/$d"
    done
    install -d -m 750 /var/log/powermanagement
    install -d -m 700 "$PROTOKOLLE" "$ZUSTAND"
}

# Rechte wie bei der Installation: Code gehört root, storage/ der
# Anwendung. storage/ und config.php bleiben dabei außen vor – die
# Oberfläche läuft weiter und soll nicht kurz ihre Dateien verlieren.
rechte_setzen() {
    find "$ZIEL" -path "$ZIEL/storage" -prune -o ! -path "$ZIEL/config/config.php" \
        -exec chown root:root {} + -exec chmod go+rX,go-w {} +
    # Was git eben als root in storage/ angelegt hat (neue .gitkeep-Ordner),
    # erst der Anwendung übereignen – sonst darf sie dort keine Rechte setzen.
    chown -R -P "$APP_USER:$APP_USER" "$ZIEL/storage"
    ordner_anlegen
    if [[ -f "$ZIEL/config/config.php" ]]; then
        chown "root:$APP_USER" "$ZIEL/config/config.php"
        chmod 640 "$ZIEL/config/config.php"
    fi
}

# ================================================================ mitgelieferte Vorlagen
# Gleiche Logik wie im Installationsskript: ersetzt wird nur, was seit der
# letzten Auslieferung niemand verändert hat; sonst kommt die neue Fassung
# daneben. Ergebnis in VORLAGE_ERGEBNIS: neu | gleich | ersetzt | behalten
VORLAGE_ERGEBNIS=""
vorlage_uebernehmen() {
    local quelle="$1" ziel="$2" besitzer="$3" modus="$4" neu="$5" erzwingen="${6:-nein}"
    local merk vorher
    merk="$VORLAGEN/$(printf '%s' "$ziel" | tr '/' '_')"
    install -d -m 700 "$VORLAGEN"

    if [[ ! -e "$ziel" ]]; then
        VORLAGE_ERGEBNIS="neu"
        neu_merken "$ziel"
    elif cmp -s "$quelle" "$ziel"; then
        VORLAGE_ERGEBNIS="gleich"
    elif [[ "$erzwingen" == "ja" ]]; then
        # Umbenennen statt „cp -p“: cp folgte als root einem Verweis, den der
        # Besitzer des Ordners (nodered) unter dem Namen der Sicherung angelegt
        # hat, und überschriebe dessen Ziel (Befund 8). rename folgt keinem
        # Verweis; install legt die Datei unten neu an. Vorher in die Marke:
        # ein Zurückrollen benennt die eigene Fassung wieder zurück.
        vorher="$ziel.vor-$(date +%Y%m%d-%H%M%S)"
        verschoben_merken "$vorher"
        mv -fT -- "$ziel" "$vorher"
        VORLAGE_ERGEBNIS="ersetzt"
    elif [[ -f "$merk" ]] && cmp -s "$merk" "$ziel"; then
        VORLAGE_ERGEBNIS="ersetzt"
    elif [[ -f "$neu" && ! -L "$neu" ]] && cmp -s "$neu" "$ziel"; then
        # Die daneben gelegte neue Fassung ganz übernommen („cp …-neu“, wie
        # die Hinweise es anbieten): ausgeliefert, nicht von Hand verändert.
        # Der Merker stand noch auf der Fassung davor – ohne diesen Fall
        # bliebe die Datei beim nächsten Update, das die Vorlage ändert, als
        # „verändert“ stehen. Kein Verweis: -neu liegt teils in Ordnern, die
        # nicht root gehören (Node-RED).
        VORLAGE_ERGEBNIS="ersetzt"
    else
        neu_merken "$neu"
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

# ================================================================ Abgleich

# Die systemd-Einheiten der Anwendung – eine Liste für Update, Nachholen und
# --nur-abgleichen. Eine Einheit je Zeile (zusammenführbar).
einheiten() {
    local e=(
        powermanagement-sammler.service
        powermanagement-wartung.service
        powermanagement-wartung.timer
        powermanagement-update.service
        powermanagement-update.path
        powermanagement-update.timer
        powermanagement-update-zeitplan.service
        powermanagement-update-zeitplan.timer
    )
    [[ "$MIT_NODERED" == "ja" ]] && e+=(powermanagement-nodered.service)
    [[ "$MIT_MOSQUITTO" == "ja" ]] && e+=(
        powermanagement-mqtt-zugaenge.service
        powermanagement-mqtt-zugaenge.path
        powermanagement-mqtt-zugaenge.timer
    )
    printf '%s\n' "${e[@]}"
}

rendern() {
    sed -e "s|__APP_USER__|$APP_USER|g" -e "s|__APP_DIR__|$ZIEL|g" \
        -e "s|__NODERED_APP__|$NODERED_APP|g" -e "s|__NODERED_DIR__|$NODERED_DIR|g" "$1"
}

# datei_abgleichen <inhalt> <ziel>: schreibt nur bei Änderung (rc 0), sonst
# rc 1; rc 2, wenn sich die Datei nicht schreiben ließ. Die Aufrufer prüfen
# das mit „|| rc=$?“ – als Bedingung griffe weder set -e noch der ERR-Trap,
# und eine leere oder abgeschnittene Datei gälte als „aktualisiert“. Darum
# auch Temp-Datei und Umbenennen statt „>“ (das kürzte vorher). Die Endung „~“
# übergehen logrotate, systemd (keine Einheit) und PHP-FPM (nicht *.conf),
# falls ein SIGKILL die Temp-Datei liegen lässt.
datei_abgleichen() {
    local inhalt="$1" ziel="$2" t
    if [[ -f "$ziel" ]] && [[ "$inhalt" == "$(cat -- "$ziel")" ]]; then
        return 1
    fi
    neu_merken "$ziel" || return 2
    t="$(mktemp --suffix='~' "${ziel%/*}/.${ziel##*/}.XXXXXX")" || return 2
    if printf '%s\n' "$inhalt" > "$t" && chmod 644 -- "$t" && mv -fT -- "$t" "$ziel"; then
        return 0
    fi
    rm -f -- "$t"
    return 2
}

# Nach datei_abgleichen mit rc 2: melden und abbrechen (ERR-Trap) – außerhalb
# jeder Bedingung aufgerufen.
nicht_geschrieben() {
    hinweis "$1 ließ sich nicht schreiben (Platte voll, nur lesbar?)."
    false
}

# abgleichen: Einheiten, Logrotate, Pool, Site, Mosquitto, Node-RED für den
# ausgecheckten Stand. Merkt, was sich geändert hat (für die Neustarts).
abgleichen() {
    local einheit gerendert inhalt site_neu listen_zeile s_listen s_port s_name helfer_rc helfer_ausgabe helfer_meldung rc
    ABGLEICH_BEGONNEN="ja"
    FPM_VERSION="$(fpm_version)"
    NODERED_NEUSTART="nein"
    MOSQUITTO_NEUSTART="nein"
    MOSQUITTO_NEULADEN="nein"
    POOL_GEAENDERT="nein"
    SITE_GEAENDERT="nein"
    GEAENDERT_EINHEITEN=()
    mqtt_nachholen_pruefen
    arbeit_anlegen

    # systemd-Einheiten: gehören der Anwendung, werden immer abgeglichen.
    while IFS= read -r einheit; do
        [[ -f "$ZIEL/deploy/$einheit" ]] || continue
        gerendert="$(rendern "$ZIEL/deploy/$einheit")"
        rc=0
        datei_abgleichen "$gerendert" "/etc/systemd/system/$einheit" || rc=$?
        [[ "$rc" -ne 2 ]] || nicht_geschrieben "/etc/systemd/system/$einheit"
        if [[ "$rc" -eq 0 ]]; then
            echo "    $einheit aktualisiert"
            GEAENDERT_EINHEITEN+=("$einheit")
            [[ "$einheit" == powermanagement-nodered.service ]] && NODERED_NEUSTART="ja"
        fi
    done < <(einheiten)
    # --ohne-dienste (Herunterfahren): systemd lehnt dann manches ab. Die
    # Dateien stimmen trotzdem – der nächste Start lädt sie.
    if [[ ${#GEAENDERT_EINHEITEN[@]} -gt 0 ]]; then
        systemctl daemon-reload || [[ "$OHNE_DIENSTE" == "ja" ]]
    fi
    # Einschalten *und* starten, bei jedem Lauf: „enable" allein wirkt erst
    # beim nächsten Neustart des Containers – und der kommt im LXC selten.
    # --ohne-dienste: nur einschalten, nichts starten.
    local jetzt=(--now)
    [[ "$OHNE_DIENSTE" == "ja" ]] && jetzt=()
    systemctl enable --quiet powermanagement-sammler.service || [[ "$OHNE_DIENSTE" == "ja" ]]
    systemctl enable --quiet ${jetzt[@]+"${jetzt[@]}"} powermanagement-wartung.timer || [[ "$OHNE_DIENSTE" == "ja" ]]
    if [[ "$MIT_NODERED" == "ja" ]]; then
        systemctl enable --quiet powermanagement-nodered.service || [[ "$OHNE_DIENSTE" == "ja" ]]
    fi
    if [[ "$MIT_MOSQUITTO" == "ja" && -f /etc/systemd/system/powermanagement-mqtt-zugaenge.path ]]; then
        systemctl enable --quiet ${jetzt[@]+"${jetzt[@]}"} powermanagement-mqtt-zugaenge.path powermanagement-mqtt-zugaenge.timer \
            || [[ "$OHNE_DIENSTE" == "ja" ]]
    fi
    # Update-Dienst: Pfad-Einheit (Aufträge der Oberfläche) und Zeitgeber
    # (Lebenszeichen, Wiederanlauf). Den Dienst selbst startet dieses Skript
    # nie – es läuft womöglich gerade in ihm. Beide sind vor ihm geordnet
    # (Before=, das setzt systemd für jede auslösende Einheit selbst): Wird er
    # gerade gestoppt (Abbruch → Zurückrollen in ihm, mit dem Abgleich der alten
    # Fassung), hält systemd jeden Auftrag an ihnen bis zum Ende des Stopps
    # zurück – und der wartet auf dieses Skript. Darum nicht warten (--no-block).
    if [[ -f /etc/systemd/system/powermanagement-update.path && -f /etc/systemd/system/powermanagement-update.timer ]]; then
        systemctl enable --quiet --no-block ${jetzt[@]+"${jetzt[@]}"} powermanagement-update.path powermanagement-update.timer \
            || [[ "$OHNE_DIENSTE" == "ja" ]]
    fi
    # Update-Zeitplan (automatische Updates, als Anwendungsbenutzer): nur der
    # Zeitgeber – den Lauf startet er selbst alle fünf Minuten.
    if [[ -f /etc/systemd/system/powermanagement-update-zeitplan.timer ]]; then
        systemctl enable --quiet ${jetzt[@]+"${jetzt[@]}"} powermanagement-update-zeitplan.timer \
            || [[ "$OHNE_DIENSTE" == "ja" ]]
    fi
    # Geänderte Pfad- und Zeitgeber-Einheiten greifen erst nach einem Neustart –
    # nur, wenn sie laufen (try-restart startet nichts, was aus ist). Nie der
    # Update-Dienst selbst: der steht nicht in dieser Liste. --no-block wie oben
    # (Pfad-Einheit und Zeitgeber des Update-Dienstes).
    if [[ "$OHNE_DIENSTE" == "nein" ]]; then
        for einheit in ${GEAENDERT_EINHEITEN[@]+"${GEAENDERT_EINHEITEN[@]}"}; do
            case "$einheit" in
                *.path|*.timer) systemctl try-restart --no-block "$einheit" ;;
            esac
        done
    fi

    # Logrotate und PHP-FPM-Pool (Upload-Grenzen, open_basedir): gehören der
    # Anwendung – erst rendern, dann vergleichen, nur bei Änderung schreiben.
    inhalt="$(rendern "$ZIEL/deploy/powermanagement.logrotate")"
    rc=0
    datei_abgleichen "$inhalt" /etc/logrotate.d/powermanagement || rc=$?
    [[ "$rc" -ne 2 ]] || nicht_geschrieben /etc/logrotate.d/powermanagement
    [[ "$rc" -eq 0 ]] && echo "    Logrotate aktualisiert"
    if [[ -n "$FPM_VERSION" ]]; then
        inhalt="$(rendern "$ZIEL/deploy/php-fpm-pool.conf")"
        rc=0
        datei_abgleichen "$inhalt" "/etc/php/$FPM_VERSION/fpm/pool.d/powermanagement.conf" || rc=$?
        [[ "$rc" -ne 2 ]] || nicht_geschrieben "/etc/php/$FPM_VERSION/fpm/pool.d/powermanagement.conf"
        if [[ "$rc" -eq 0 ]]; then
            echo "    PHP-FPM-Pool aktualisiert"
            POOL_GEAENDERT="ja"
        fi
    fi

    # nginx-Site: nur ersetzen, wenn niemand sie verändert hat. Port, Adresse
    # und Servername stehen in der vorhandenen Site – daraus neu erzeugen.
    if [[ -f "$SITE" ]]; then
        listen_zeile="$(grep -m1 -E '^\s*listen\s+[^[]' "$SITE" | sed -E 's/^\s*listen\s+([^ ;]+).*/\1/' || true)"
        if [[ "$listen_zeile" == *:* ]]; then
            s_listen="${listen_zeile%:*}"
            s_port="${listen_zeile##*:}"
        else
            s_listen="0.0.0.0"
            s_port="$listen_zeile"
        fi
        s_name="$(grep -m1 -E '^\s*server_name\s' "$SITE" | sed -E 's/^\s*server_name\s+([^;]+);.*/\1/' || true)"
        site_neu="$ARBEIT/site"
        sed -e "s|__APP_DIR__|$ZIEL|g" \
            -e "s|__FPM_SOCKET__|/run/php/powermanagement.sock|g" \
            -e "s|__SERVER_NAME__|${s_name:-powermanagement.lan}|g" \
            -e "s|__LISTEN__|${s_listen:-0.0.0.0}|g" \
            -e "s|__PORT__|${s_port:-80}|g" \
            "$ZIEL/deploy/nginx-powermanagement.conf" > "$site_neu"
        # Ohne default_server betrieben (Zusammenstoß mit einer anderen Seite)?
        # Dann auch die neue Fassung ohne.
        if ! grep -qE '^\s*listen\s.*default_server' "$SITE"; then
            sed -i -E 's/^(\s*listen\s+[^[;]+) default_server;/\1;/' "$site_neu"
        fi
        vorlage_uebernehmen "$site_neu" "$SITE" root:root 644 /etc/nginx/powermanagement.powermanagement-neu
        rm -f "$site_neu"
        case "$VORLAGE_ERGEBNIS" in
            ersetzt|neu)
                echo "    nginx-Site aktualisiert"
                SITE_GEAENDERT="ja" ;;
            behalten)
                hinweis "Die nginx-Site ist von Hand verändert und bleibt so. Neue Fassung:"
                hinweis "  /etc/nginx/powermanagement.powermanagement-neu (diff -u $SITE /etc/nginx/powermanagement.powermanagement-neu)"
                # Grundrisse bis 100 MB brauchen den eigenen Block – ohne ihn
                # weist nginx sie mit der Grenze der Site ab (früher 64m).
                if ! grep -q 'location = /standorte/grundriss' "$SITE"; then
                    hinweis "  Darin fehlt der Block für Grundrisse bis 100 MB (location = /standorte/grundriss,"
                    hinweis "  client_max_body_size 105m) – von dort übernehmen, sonst lehnt nginx große Pläne ab."
                fi
                WARNUNGEN+=(site_behalten) ;;
        esac
    fi

    if [[ "$MIT_MOSQUITTO" == "ja" ]]; then
        # Auch ohne neuen Code (MQTT_NACHHOLEN): das Verzeichnis für Soll und
        # Ergebnis der eigenen MQTT-Zugänge muss da sein.
        app_verzeichnis "$ZIEL/storage/mqtt"
        app_verzeichnis "$ZIEL/storage/mqtt/soll"
        app_verzeichnis "$ZIEL/storage/mqtt/ist"

        # 1. Grundlage der ACL. Mosquitto liest sie nicht direkt, sondern die
        #    wirksame Fassung, die der Helfer daraus baut – ein Neuladen genügt.
        vorlage_uebernehmen "$ZIEL/deploy/mosquitto/powermanagement.acl" \
            /etc/mosquitto/powermanagement.acl mosquitto:mosquitto 600 \
            /etc/mosquitto/powermanagement.acl.powermanagement-neu
        [[ "$VORLAGE_ERGEBNIS" == "ersetzt" || "$VORLAGE_ERGEBNIS" == "neu" ]] && MOSQUITTO_NEULADEN="ja"
        if [[ "$VORLAGE_ERGEBNIS" == "behalten" ]]; then
            hinweis "Mosquitto-ACL verändert – neue Fassung: /etc/mosquitto/powermanagement.acl.powermanagement-neu"
            hinweis "Für eigene Zugänge je Shelly die pattern-Zeilen von dort übernehmen (Änderungen von Hand nur"
            hinweis "in /etc/mosquitto/powermanagement.acl, nie in der wirksamen Datei)."
            WARNUNGEN+=(acl_behalten)
        fi

        # 2. Eigene Zugänge je Shelly: wirksame ACL aus Grundlage und Block der
        #    Kennwortdatei bauen. Neu geladen wird unten, einmal für alles.
        if [[ -f "$ZIEL/deploy/mqtt-zugaenge.sh" && -f /etc/mosquitto/powermanagement.passwd ]]; then
            helfer_rc=0
            helfer_meldung="$ARBEIT/mqtt-helfer"
            helfer_ausgabe="$(bash "$ZIEL/deploy/mqtt-zugaenge.sh" --ziel="$ZIEL" --app-benutzer="$APP_USER" \
                --neu-laden=nein 2>"$helfer_meldung")" || helfer_rc=$?
            sed 's/^/    /' "$helfer_meldung"
            rm -f "$helfer_meldung"
            if [[ "${helfer_ausgabe##*$'\n'}" == "geaendert" ]]; then
                MOSQUITTO_NEULADEN="ja"
            fi
            if [[ "$helfer_rc" -ne 0 ]]; then
                hinweis "deploy/mqtt-zugaenge.sh endete mit $helfer_rc (siehe oben; journalctl -u powermanagement-mqtt-zugaenge)."
                WARNUNGEN+=(mqtt_helfer)
            fi
        fi
        # Nachholen: ein älteres Update-Skript hat womöglich schon die neue
        # Konfiguration eingespielt und den Broker ohne wirksame ACL neu
        # gestartet – dann steht er. Unten auf jeden Fall (neu) starten.
        [[ "$MQTT_NACHHOLEN" == "ja" ]] && MOSQUITTO_NEULADEN="ja"
        if [[ ! -f /etc/mosquitto/powermanagement-wirksam.acl ]]; then
            # Ohne wirksame ACL startet der Broker mit der neuen Konfiguration
            # nicht – dann wenigstens die Grundlage (ohne Sperren).
            neu_merken /etc/mosquitto/powermanagement-wirksam.acl
            install -o mosquitto -g mosquitto -m 600 /etc/mosquitto/powermanagement.acl \
                /etc/mosquitto/powermanagement-wirksam.acl
            MOSQUITTO_NEULADEN="ja"
        fi

        # 3. Konfiguration: der Wechsel auf die wirksame Datei braucht einmal
        #    einen Neustart.
        vorlage_uebernehmen "$ZIEL/deploy/mosquitto/broker.conf" \
            "$MOSQUITTO_CONF" root:root 644 \
            /etc/mosquitto/powermanagement.conf.powermanagement-neu
        if [[ "$VORLAGE_ERGEBNIS" == "ersetzt" || "$VORLAGE_ERGEBNIS" == "neu" ]]; then
            MOSQUITTO_NEUSTART="ja"
        fi
        if [[ "$VORLAGE_ERGEBNIS" == "behalten" ]]; then
            hinweis "Mosquitto-Konfiguration verändert – neue Fassung: /etc/mosquitto/powermanagement.conf.powermanagement-neu"
            hinweis "Eigene Zugänge je Shelly brauchen dort: acl_file /etc/mosquitto/powermanagement-wirksam.acl"
        fi
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
                hinweis "im Editor über Menü → Importieren übernehmen, oder: $0 --flows-ersetzen"
                hinweis "Tanksensoren brauchen die neuen Flows – als Voltmeter (Plus Uni, Add-on) und am Gen1 Uni (adc) kommen sonst"
                hinweis "keine Rohwerte an, als Analog-Eingang nur eingang, ohne x und ohne Erkennung von Sensorfehlern."
                hinweis "Eigene MQTT-Zugänge der Shellys ebenso (Schutzliste pm/status/mqtt-zugaenge, Antworten unter"
                hinweis "<präfix>/pm-nodered/rpc) – sonst meldet die Seite „MQTT-Zugänge“, dass Node-RED sie nicht bestätigt."
                WARNUNGEN+=(flows_behalten) ;;
        esac
    fi
    return 0
}

# Am Ende eines Laufs (Zusammenfassung, Nachholen) noch einmal, wenn die
# Flows blieben: was neu in den Flows ist, kommt dann nicht an – die
# Hinweise oben stehen weit vor dem Ergebnis.
flows_behalten_melden() {
    local w
    for w in ${WARNUNGEN[@]+"${WARNUNGEN[@]}"}; do
        [[ "$w" == flows_behalten ]] || continue
        echo "  Node-RED:  Flows im Editor verändert und behalten – die neue Fassung liegt unter"
        echo "             $NODERED_DIR/flows.powermanagement-neu.json. Tanksensoren und eigene MQTT-Zugänge"
        echo "             brauchen sie: im Editor Menü → Importieren, oder $ZIEL/deploy/update-debian.sh --flows-ersetzen"
        return 0
    done
    return 0
}

# ================================================================ Dienste

fpm_testen() {
    local bin
    bin="$(command -v "php-fpm$FPM_VERSION" 2>/dev/null || true)"
    [[ -n "$bin" ]] || return 0
    "$bin" -t 2>&1 | sed 's/^/    /'
}

# dienste_starten voll|geaendert: im vollen Update PHP-FPM, nginx und Sammler
# immer; beim Nachholen und mit --nur-abgleichen nur, was sich geändert hat.
dienste_starten() {
    local art="$1" einheit sammler_neu="nein"
    if [[ "$OHNE_DIENSTE" == "ja" ]]; then
        echo "    --ohne-dienste: es wird nichts gestartet."
        return 0
    fi
    for einheit in ${GEAENDERT_EINHEITEN[@]+"${GEAENDERT_EINHEITEN[@]}"}; do
        [[ "$einheit" == powermanagement-sammler.service ]] && sammler_neu="ja"
    done
    if [[ -n "$FPM_VERSION" ]] && [[ "$art" == "voll" || "$POOL_GEAENDERT" == "ja" ]]; then
        # Erst die Konfiguration prüfen, dann neu laden: Master und Socket
        # bleiben, die Oberfläche zeigt kaum 502, der OPcache wird geleert.
        SCHRITT_GRUND="dienste"
        fpm_testen
        systemctl reload-or-restart "php$FPM_VERSION-fpm"
        echo "    PHP-FPM neu geladen"
    fi
    if [[ "$art" == "voll" || "$SITE_GEAENDERT" == "ja" ]]; then
        SCHRITT_GRUND="nginx"
        nginx -t 2>&1 | sed 's/^/    /'
        SCHRITT_GRUND="dienste"
        systemctl reload nginx || systemctl restart nginx
    fi
    SCHRITT_GRUND="dienste"
    if [[ "$MOSQUITTO_NEUSTART" == "ja" ]]; then
        systemctl restart mosquitto
        echo "    mosquitto neu gestartet"
    elif [[ "$MOSQUITTO_NEULADEN" == "ja" ]]; then
        # Neu laden (SIGHUP): bestehende Verbindungen der Shellys bleiben. Läuft
        # der Broker gerade nicht, wird er gestartet.
        systemctl reload-or-restart mosquitto
        echo "    mosquitto neu geladen"
    fi
    if [[ "$MIT_MOSQUITTO" == "ja" && -f /etc/systemd/system/powermanagement-mqtt-zugaenge.service ]] \
        && [[ "$art" == "voll" || "$MOSQUITTO_NEUSTART" == "ja" || "$MOSQUITTO_NEULADEN" == "ja" ]]; then
        # Ergebnis für die Oberfläche auffrischen – der Lauf oben sah die neue
        # Broker-Konfiguration noch nicht.
        systemctl start --no-block powermanagement-mqtt-zugaenge.service || true
    fi
    if [[ "$MIT_NODERED" == "ja" && "$NODERED_NEUSTART" == "ja" ]]; then
        systemctl restart powermanagement-nodered.service
        echo "    Node-RED neu gestartet"
    fi
    if [[ "$art" == "voll" || "$sammler_neu" == "ja" ]]; then
        systemctl restart powermanagement-sammler.service
        echo "    Sammler läuft wieder"
    fi
    return 0
}

# Adresse für die Prüfung der Anmeldeseite: Listen und Servername aus der
# Site; lauscht nginx nur auf einer Adresse, ist 127.0.0.1 dort nicht erreichbar.
pruef_adresse() {
    local l n
    PRUEF_HOST="127.0.0.1"
    PRUEF_PORT="80"
    PRUEF_NAME="powermanagement.lan"
    [[ -f "$SITE" ]] || return 0
    l="$(grep -m1 -E '^\s*listen\s+[^[]' "$SITE" | sed -E 's/^\s*listen\s+([^ ;]+).*/\1/' || true)"
    if [[ "$l" == *:* ]]; then
        PRUEF_HOST="${l%:*}"
        PRUEF_PORT="${l##*:}"
    elif [[ -n "$l" ]]; then
        PRUEF_PORT="$l"
    fi
    [[ "$PRUEF_HOST" == "0.0.0.0" || "$PRUEF_HOST" == "*" || ! "$PRUEF_HOST" =~ ^[0-9.]+$ ]] && PRUEF_HOST="127.0.0.1"
    [[ "$PRUEF_PORT" =~ ^[0-9]{1,5}$ ]] || PRUEF_PORT="80"
    n="$(grep -m1 -E '^\s*server_name\s' "$SITE" | sed -E 's/^\s*server_name\s+([^; ]+).*/\1/' || true)"
    [[ "$n" =~ ^[A-Za-z0-9._-]+$ ]] && PRUEF_NAME="$n"
    return 0
}

login_code() {
    local code
    # curl schreibt „000" auch selbst, wenn keine Verbindung zustande kommt.
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H "Host: $PRUEF_NAME" \
        "http://$PRUEF_HOST:$PRUEF_PORT/login" 2>/dev/null)" || true
    printf '%s' "${code:-000}"
}

# Die Anmeldeseite antwortet mit 200 – zehn Versuche im Abstand von 2 s
login_200() {
    local i
    for i in 1 2 3 4 5 6 7 8 9 10; do
        LOGIN_CODE="$(login_code)"
        [[ "$LOGIN_CODE" == 200 ]] && return 0
        [[ "$i" -lt 10 ]] && sleep 2
    done
    return 1
}

# Pflichtprüfung: scheitert ein Punkt, wird zurückgerollt.
pflichtpruefung() {
    local ok="ja" einheit vorher nachher
    pruef_adresse
    if [[ "$LOGIN_VORHER" == 200 ]]; then
        if login_200; then
            echo "    Anmeldeseite: HTTP 200"
        else
            hinweis "Die Anmeldeseite antwortet mit HTTP $LOGIN_CODE statt 200."
            ok="nein"
        fi
    else
        echo "    Anmeldeseite: schon vor dem Update HTTP $LOGIN_VORHER – keine Pflicht"
    fi
    for einheit in ${FPM_VERSION:+"php$FPM_VERSION-fpm"} nginx ${AKTIV_VORHER[@]+"${AKTIV_VORHER[@]}"}; do
        if ! systemctl is-active --quiet "$einheit"; then
            hinweis "$einheit läuft nicht."
            ok="nein"
        fi
    done
    if [[ " ${AKTIV_VORHER[*]} " == *" powermanagement-sammler "* ]]; then
        # Ein Sammler, der gleich wieder abstürzt, wäre eben noch „active“.
        vorher="$(systemctl show -p NRestarts --value powermanagement-sammler.service 2>/dev/null || true)"
        sleep 10
        nachher="$(systemctl show -p NRestarts --value powermanagement-sammler.service 2>/dev/null || true)"
        if ! systemctl is-active --quiet powermanagement-sammler.service || [[ "$vorher" != "$nachher" ]]; then
            hinweis "Der Sammler läuft nicht stabil (Neustarts: ${vorher:-?} → ${nachher:-?})."
            ok="nein"
        else
            echo "    Sammler läuft stabil"
        fi
    fi
    # Die Anwendung startet, und das Schema antwortet (Zeile „Datenbank:“).
    # InfluxDB und MQTT entscheiden hier nicht – das ist die Wunschprüfung.
    PRUEF_RC=0
    PRUEF_AUSGABE="$(as_app timeout 60 php "$ZIEL/bin/power" pruefen 2>&1)" || PRUEF_RC=$?
    printf '%s\n' "$PRUEF_AUSGABE" | sed 's/^/    /'
    if ! grep -q '^Datenbank:' <<< "$PRUEF_AUSGABE" || grep -q '^Schlüssel:' <<< "$PRUEF_AUSGABE"; then
        hinweis "bin/power pruefen: die Anwendung startet nicht, das Schema antwortet nicht oder der Schlüssel fehlt."
        ok="nein"
    fi
    [[ "$ok" == "ja" ]]
}

# Wunschprüfung: bin/power pruefen ohne Fehler (InfluxDB, MQTT, Node-RED).
# Scheitert sie, bleibt das Update eingespielt – solche Störungen haben meist
# äußere Gründe, und ein Zurückrollen wäre ein weiterer Neustart.
wunschpruefung() {
    local i
    if [[ "$PRUEF_RC" -eq 0 ]]; then
        PRUEFUNG="ok"
        return 0
    fi
    if [[ "$PRUEFUNG_VORHER" != "ok" ]]; then
        PRUEFUNG="warnung"
        hinweis "bin/power pruefen meldet Fehler (siehe oben) – schon vor dem Update; das Update ist eingespielt."
        return 0
    fi
    # Node-RED braucht nach einem Neustart eine Weile bis zum ersten Herzschlag.
    # Ein Signal (Stopp, Herunterfahren) beendet nur das Warten – das Update
    # ist schon eingespielt.
    for i in 1 2 3 4 5 6 7 8 9; do
        [[ "$NACH_SIGNAL" == "ja" ]] && break
        sleep 10
        [[ "$NACH_SIGNAL" == "ja" ]] && break
        PRUEF_RC=0
        PRUEF_AUSGABE="$(as_app timeout 60 php "$ZIEL/bin/power" pruefen 2>&1)" || PRUEF_RC=$?
        if [[ "$PRUEF_RC" -eq 0 ]]; then
            echo "    bin/power pruefen ohne Fehler (nach $((i * 10)) s)"
            PRUEFUNG="ok"
            return 0
        fi
    done
    printf '%s\n' "$PRUEF_AUSGABE" | sed 's/^/    /'
    hinweis "bin/power pruefen meldet weiter Fehler (vor dem Update nicht) – das Update selbst ist eingespielt."
    PRUEFUNG="warnung"
    WARNUNGEN+=(pruefung)
    return 0
}

# Fehlende Kacheln (Schritt 10) rechnet das Update nicht selbst: Ein Plan mit
# 100 MB braucht Minuten, ein PDF mit 60 Seiten eine halbe Stunde. So lange
# stünde die Schreibsperre, die Frist des Update-Dienstes (60 min) liefe ab –
# und plan:kacheln wartete zudem, bis ein Bau im Hintergrund fertig ist
# (PlanTileService::nacheinander). Erst nach dem Punkt ohne Rückweg, als eigene
# Einheit und Anwendungsbenutzer (nie als root – sonst gehörten Kacheln root):
# Sie überlebt das Ende dieses Dienstes (systemd beendet dessen cgroup mit ihm),
# rechnet mit dem neuen Code und schreibt nur unter storage/plans/kacheln, nie
# in die Datenbank. Ohne Namensräume (PrivateTmp & Co.), damit sie auch im LXC
# ohne Nesting startet. Was nicht anläuft, holt die nächtliche Wartung nach.
#
# --nachholen: was das Update liegen ließ – Aufträge, die während der
# Schreibsperre warteten (Upload, Abgleich, „Plan neu rechnen“), Bauten, die
# ein Neustart von PHP-FPM beendete, Pläne ohne Kacheln. Nicht die Pläne einer
# älteren Kachelfassung: Bringt das Update eine neue (FASSUNG), wären das alle
# – auf einem kleinen LXC eine Stunde und mehr neben InfluxDB. Die bleiben, wie
# sie sind („neu rechnen empfohlen“), bis die nächtliche Wartung (20 min je
# Nacht, nie in das Fenster der automatischen Updates) oder „Plan neu rechnen“
# sie dran nimmt.
kacheln_anstossen() {
    local php
    [[ "$OHNE_DIENSTE" == "nein" ]] || return 0
    if systemctl is-active --quiet powermanagement-kacheln.service 2>/dev/null; then
        echo "    Fehlende Kacheln: powermanagement-kacheln rechnet noch"
        return 0
    fi
    php="$(command -v php 2>/dev/null || true)"
    if [[ -n "$php" ]] && systemd-run --quiet --no-block --collect --unit=powermanagement-kacheln \
            --description="PowerManagement: fehlende Kacheln und offene Aufträge nachrechnen" \
            --uid="$APP_USER" --gid="$APP_USER" --working-directory="$ZIEL" -p Nice=10 -p IOSchedulingClass=idle \
            "$php" "$ZIEL/bin/power" plan:kacheln --nachholen >/dev/null 2>&1; then
        echo "    Fehlende Kacheln rechnet jetzt powermanagement-kacheln nach, dazu offene Aufträge (journalctl -u powermanagement-kacheln)"
        echo "    Pläne einer älteren Kachelfassung bleiben bis zur nächtlichen Wartung oder „Plan neu rechnen“."
    else
        hinweis "Fehlende Kacheln ließen sich nicht anstoßen – die nächtliche Wartung rechnet sie nach."
        WARNUNGEN+=(kacheln)
    fi
    return 0
}

# Nach einem Abbruch oder Zurückrollen ebenso – sonst holte niemand ab, was
# unter der Schreibsperre stehen blieb: Aufträge („Plan neu rechnen“, ein
# Upload kurz davor) und der Rest eines Prozesses, der wegen des Updates
# aufhörte. Die Karte meldete nach einer Minute „abgebrochen“, gerechnet würde
# erst in der nächsten Nacht. Nur, wenn dieser Lauf die Sperre eben aufgehoben
# hat (wartung_aus), und nicht beim Herunterfahren. Ein alter Stand ohne
# --nachholen rechnet einfach die fehlenden Kacheln.
kacheln_nach_abbruch() {
    [[ "$SPERRE_AUFGEHOBEN" == "ja" ]] || return 0
    [[ "$(systemctl is-system-running 2>/dev/null)" != "stopping" ]] || return 0
    kacheln_anstossen
}

# Ohne den Update-Dienst gestartet (Konsole): ihn einmal anstoßen, damit
# Stand und Verlauf für die Oberfläche stimmen. --no-block: nie warten, und
# aus dem Dienst selbst heraus wird das nie aufgerufen.
dienst_anstossen() {
    if [[ -z "${PM_UPDATE_AUSLOESER:-}" && -f /etc/systemd/system/powermanagement-update.service ]]; then
        systemctl start --no-block powermanagement-update.service >/dev/null 2>&1 || true
    fi
    return 0
}

# ================================================================ Abbrechen und Zurückrollen

zurueck_protokoll() {
    install -d -m 750 /var/log/powermanagement 2>/dev/null
    install -d -m 700 "$PROTOKOLLE" 2>/dev/null
    printf '%s' "$PROTOKOLLE/$(date -u +%Y%m%d-%H%M%S)-zurueckrollen.log"
}

# Vor der Marke ist außerhalb von storage/backups nichts verändert: nur
# aufräumen (Schnappschuss, Sammler, Schreibsperre). PHP-FPM wird nicht
# neu gestartet (N11). Bei einem Fehler im Abgleich ohne neuen Code
# (Nachholen) gilt dasselbe – ein zweiter Lauf holt ihn nach.
# Nur über die Traps erreichbar:
# shellcheck disable=SC2317
abbrechen() {   # abbrechen <grund> [<zeile>]
    [[ "$BASHPID" == "$$" ]] || exit 1
    [[ -n "$ABBRUCH" ]] && return 0
    ABBRUCH=1
    set +e
    trap - ERR
    trap '' INT TERM HUP
    local grund="$1"
    [[ "$SIGNAL" == "HUP" ]] && exec >>"$(zurueck_protokoll)" 2>&1 </dev/null
    if [[ -n "$SIGNAL" ]]; then
        hinweis "Abgebrochen ($SIGNAL)."
    else
        hinweis "Fehler (Zeile ${2:-?}, Schritt „$SCHRITT_NAME“)."
    fi
    schnappschuss_loeschen
    if [[ "$SAMMLER_ANGEHALTEN" == "ja" ]]; then
        systemctl start powermanagement-sammler.service && rm -f -- "$ZUSTAND/sammler-angehalten"
    fi
    wartung_aus
    kacheln_nach_abbruch
    if [[ "$ABGLEICH_BEGONNEN" == "ja" ]]; then
        hinweis "Der Abgleich blieb unvollständig – derselbe Aufruf holt ihn nach. Der Code ist unverändert."
    else
        hinweis "Das Update wurde nicht begonnen – verändert wurde nichts."
    fi
    ergebnis nicht_begonnen "$grund"
    exit 1
}

# Zurückrollen (B17–B20, B22, B23, N11): nach der Marke in Stufe 1, in Stufe 2
# und bei --wiederaufnehmen. Nichts unterbricht es (set +e, Signale aus).
# Bis zur Phase „checkout“ ist außerhalb von git nichts angefasst – dann nur
# git zurück. Sonst vollständig: Code und Zweig, Rechte, Datenbank (nur wenn
# migriert), neu angelegte Dateien weg, dann die ALTE Fassung mit
# --nur-abgleichen: sie rollt ihre Einheiten, Pool, Logrotate, Site,
# Mosquitto und Node-RED neu aus (Datei = Merker → „ersetzt“ → Datei und
# Merker wieder alt, B18/B19).
zurueckrollen() {   # zurueckrollen <grund> [<zeile>]
    [[ "$BASHPID" == "$$" ]] || exit 1
    [[ -n "$ZURUECK" ]] && return 0
    ZURUECK=1
    set +e
    trap - ERR
    trap '' INT TERM HUP
    local grund="$1" luecke="nein" herunter="nein" phase="$PHASE" f opt=() code_zurueck="ja"
    # Terminal weg: EIO träfe sonst jeden Schritt.
    [[ "$SIGNAL" == "HUP" ]] && exec >>"$(zurueck_protokoll)" 2>&1 </dev/null
    case "$grund" in
        abgebrochen) hinweis "Abgebrochen (${SIGNAL:-Signal}) – der alte Stand wird wiederhergestellt." ;;
        unterbrochen) hinweis "Ein früheres Update wurde unterbrochen – der alte Stand wird wiederhergestellt." ;;
        *) hinweis "Das Update ist fehlgeschlagen ($grund${2:+, Zeile $2}) – der alte Stand wird wiederhergestellt." ;;
    esac
    SCHRITT_NAME="Zurückrollen"
    fortschritt_schreiben
    [[ "$(systemctl is-system-running 2>/dev/null)" == "stopping" ]] && herunter="ja"
    marke_setzen phase zurueck
    systemctl stop powermanagement-sammler.service

    # Git: Commit UND Zweig (B22)
    if [[ "$ALT_ZWEIG" == "-" ]]; then
        g checkout -q -f --detach "$ALT" || code_zurueck="nein"
    else
        g checkout -q -f -B "$ALT_ZWEIG" "$ALT" || code_zurueck="nein"
    fi
    [[ "$(g rev-parse HEAD 2>/dev/null)" == "$ALT" ]] || code_zurueck="nein"
    [[ "$code_zurueck" == "ja" ]] || luecke="ja"
    if [[ "$ZWEIG" != "$ALT_ZWEIG" ]]; then
        if [[ "$ZWEIG_REF_VORHER" == "-" ]]; then
            g branch -q -D -- "$ZWEIG" || luecke="ja"
        else
            g update-ref "refs/heads/$ZWEIG" "$ZWEIG_REF_VORHER" || luecke="ja"
        fi
    fi

    if [[ "$phase" == "stufe1" || "$phase" == "checkout" ]]; then
        # Außerhalb von git noch nichts angefasst (etwa die neue Fassung
        # besteht bash -n nicht): kein Neustart von PHP-FPM (N11).
        [[ "$herunter" == "nein" ]] && systemctl start powermanagement-sammler.service
        wartung_aus
        # Nur mit dem alten Code zurück – sonst rechnete er mit dem, was git liegen ließ.
        [[ "$code_zurueck" == "ja" ]] && kacheln_nach_abbruch
        case "$grund" in abgebrochen|unterbrochen) ;; *) gescheitert_merken "$NEU" "$grund" ;; esac
        if [[ "$luecke" == "nein" ]]; then
            schnappschuss_loeschen
            marke_loeschen
            rm -f -- "$ZUSTAND/alt/update-debian.sh"
            hinweis "Zurückgerollt auf $(g log -1 --format='%h %s')."
            [[ "$herunter" == "nein" ]] && dienst_anstossen
            ergebnis zurueckgerollt "$grund"
            exit 2
        fi
        hinweis "Git ließ sich nicht zurücksetzen – Handarbeit nötig (docs/installation-debian-lxc.md, Zurückrollen von Hand)."
        [[ "$herunter" == "nein" ]] && dienst_anstossen
        ergebnis unvollstaendig "$grund" "Handarbeit nötig"
        exit 3
    fi

    if [[ "$code_zurueck" == "nein" ]]; then
        # Der alte Code ist nicht zurück (etwa eine verwaiste .git/index.lock
        # nach einem Absturz mitten im Checkout). Dann weder die Datenbank
        # zurücklegen (neuer Code auf altem Schema) noch mit dem ausgecheckten
        # – neuen – Skript abgleichen (es legte die eben entfernten Einheiten
        # wieder an), nichts neu starten, und die Schreibsperre bleibt. Die
        # Marke bleibt: --wiederaufnehmen räumt verwaiste git-Sperren weg und
        # versucht es erneut.
        hinweis "Git ließ sich nicht auf $(kurz "$ALT") zurücksetzen – Datenbank, Einheiten und Dienste bleiben, wie sie sind; die Oberfläche bleibt gesperrt."
        hinweis "Handarbeit nötig – docs/installation-debian-lxc.md (Zurückrollen von Hand). Die Marke bleibt: $MARKE"
        case "$grund" in abgebrochen|unterbrochen) ;; *) gescheitert_merken "$NEU" "$grund" ;; esac
        [[ "$herunter" == "nein" ]] && dienst_anstossen
        ergebnis unvollstaendig "$grund" "Handarbeit nötig"
        exit 3
    fi

    rechte_setzen
    FPM_VERSION="$(fpm_version)"
    local fpm_angehalten="nein" daten_luecke="nein" flows_zurueck="nein" v
    if [[ "$MIGRIERT" == "ja" ]]; then
        if [[ "$SCHNAPPSCHUSS" != "-" && -f "$SCHNAPPSCHUSS" && ! -L "$SCHNAPPSCHUSS" ]]; then
            # PHP-FPM dafür anhalten: offene SQLite-Verbindungen (WAL)
            # schrieben sonst in die ersetzte Datei.
            if [[ -n "$FPM_VERSION" ]]; then
                systemctl stop "php$FPM_VERSION-fpm"
                fpm_angehalten="ja"
            fi
            if db_zuruecklegen; then
                echo "    Datenbank auf den Stand vor dem Update zurückgesetzt"
                # Bleibt die Marke (etwas weiter unten fehlt), legt ein
                # weiterer Versuch sie nicht noch einmal zurück – was danach
                # gespeichert wurde, ginge sonst verloren.
                MIGRIERT="nein"
                marke_setzen migriert nein && sync -- "$MARKE" "$ZUSTAND"
            else
                luecke="ja"
                daten_luecke="ja"
                hinweis "Die Datenbank ließ sich nicht zurücklegen. Der Schnappschuss liegt unter $SCHNAPPSCHUSS –"
                hinweis "nur als $APP_USER zurücklegen (docs/installation-debian-lxc.md, Zurückrollen von Hand)."
            fi
        elif ! g diff --quiet "$ALT" "$NEU" -- database/migrations 2>/dev/null; then
            luecke="ja"
            daten_luecke="ja"
            hinweis "Die Datenbank ist migriert, aber es gibt keine Kopie zum Zurücklegen (kein SQLite?) – von Hand aus der Sicherung."
        fi
    fi

    # Von der neuen Fassung neu angelegte Dateien (die alte kennt sie nicht)
    local neu_angelegt=()
    mapfile -t neu_angelegt < <(marke_werte neu_angelegt)
    for f in ${neu_angelegt[@]+"${neu_angelegt[@]}"}; do
        neu_angelegt_erlaubt "$f" || continue
        if [[ "$f" == /etc/systemd/system/* ]]; then
            systemctl disable --now --quiet -- "${f##*/}" 2>/dev/null
        fi
        rm -f -- "$f" && echo "    entfernt: $f"
    done
    systemctl daemon-reload

    if hat_schnittstelle "$ZIEL/deploy/update-debian.sh"; then
        # Die ALTE Fassung gleicht ab; sie erbt die Sperre (fd 9) und die
        # ignorierten Signale. Beim Herunterfahren nichts starten.
        [[ "$herunter" == "ja" ]] && opt=(--ohne-dienste)
        echo
        echo "    Abgleich mit der alten Fassung:"
        env -u PM_UPDATE_STUFE PM_UPDATE_SPERRE=geerbt /bin/bash "$ZIEL/deploy/update-debian.sh" \
            --ziel="$ZIEL" --nur-abgleichen ${opt[@]+"${opt[@]}"} || luecke="ja"
    else
        luecke="ja"
        hinweis "Die alte Fassung kennt --nur-abgleichen nicht – Einheiten und Vorlagen von Hand prüfen."
    fi

    # Mit --flows-ersetzen beiseitegelegte eigene Flows zurück an ihren Platz.
    # Erst nach dem Abgleich: der hat die ausgelieferten alten Flows samt
    # Merker eingespielt – mit der eigenen Fassung darüber ist es wie vorher.
    while IFS= read -r v; do
        verschoben_erlaubt "$v" && [[ -f "$v" && ! -L "$v" ]] || continue
        if mv -fT -- "$v" "${v%.vor-*}"; then
            echo "    eigene Fassung zurück: ${v%.vor-*}"
            flows_zurueck="ja"
        else
            luecke="ja"
        fi
    done < <(marke_werte verschoben)

    if [[ "$herunter" == "nein" ]]; then
        if [[ "$flows_zurueck" == "ja" && "$MIT_NODERED" == "ja" ]]; then
            systemctl try-restart powermanagement-nodered.service || hinweis "Node-RED ließ sich nicht neu starten."
        fi
        if [[ -n "$FPM_VERSION" ]]; then
            # Den alten Code laden (OPcache) bzw. nach dem Zurücklegen starten
            if [[ "$fpm_angehalten" == "ja" ]]; then
                systemctl start "php$FPM_VERSION-fpm" || luecke="ja"
            else
                systemctl reload-or-restart "php$FPM_VERSION-fpm" || luecke="ja"
            fi
        fi
        systemctl start powermanagement-sammler.service
        if [[ "$LOGIN_VORHER" == 200 ]]; then
            pruef_adresse
            if ! login_200; then
                luecke="ja"
                hinweis "Die Anmeldeseite antwortet auch nach dem Zurückrollen mit HTTP $LOGIN_CODE."
            fi
        fi
    fi
    # Liegt die Datenbank nicht zurück, läuft alter Code auf dem neuen Schema –
    # dann bleibt die Oberfläche gesperrt, bis ein weiterer Versuch gelingt.
    if [[ "$daten_luecke" == "nein" ]]; then
        wartung_aus
        # Auch Bauten, die das Anhalten von PHP-FPM beendete (abgebrochen).
        kacheln_nach_abbruch
    else
        hinweis "Die Schreibsperre der Oberfläche bleibt, bis die Datenbank zurückliegt."
    fi
    case "$grund" in abgebrochen|unterbrochen) ;; *) gescheitert_merken "$NEU" "$grund" ;; esac
    if [[ "$luecke" == "nein" ]]; then
        schnappschuss_loeschen   # B23
        marke_loeschen
        rm -f -- "$ZUSTAND/alt/update-debian.sh"
        hinweis "Zurückgerollt auf $(g log -1 --format='%h %s')."
        [[ "$SICHERUNG" != "-" ]] && hinweis "Sicherung: $SICHERUNG"
        # Von der Konsole: Stand und Verlauf der Oberfläche gleich nachziehen.
        [[ "$herunter" == "nein" ]] && dienst_anstossen
        ergebnis zurueckgerollt "$grund"
        exit 2
    fi
    hinweis "Handarbeit nötig – docs/installation-debian-lxc.md (Zurückrollen von Hand). Die Marke bleibt: $MARKE"
    [[ "$SICHERUNG" != "-" ]] && hinweis "Sicherung: $SICHERUNG"
    [[ "$herunter" == "nein" ]] && dienst_anstossen
    ergebnis unvollstaendig "$grund" "Handarbeit nötig"
    exit 3
}

# marke_werte <schlüssel>: alle Werte eines mehrfachen Schlüssels
# (neu_angelegt, verschoben), so wie die Marke jetzt ist
marke_werte() {
    local zeile
    [[ -f "$MARKE" ]] || return 0
    while IFS= read -r zeile; do
        if [[ "$zeile" == "$1 "* ]]; then
            printf '%s\n' "${zeile#"$1 "}"
        fi
    done < "$MARKE"
    return 0
}

traps_vor_marke() {
    trap 'abbrechen "$SCHRITT_GRUND" "$LINENO"' ERR
    trap 'SIGNAL=INT; abbrechen abgebrochen' INT
    trap 'SIGNAL=TERM; abbrechen abgebrochen' TERM
    trap 'SIGNAL=HUP; abbrechen abgebrochen' HUP
}
# bash führt den Trap erst nach dem laufenden Vordergrundbefehl aus (apt,
# Migration) – gewollt: der Schritt endet, dann wird zurückgerollt.
traps_nach_marke() {
    trap 'zurueckrollen "$SCHRITT_GRUND" "$LINENO"' ERR
    trap 'SIGNAL=INT; zurueckrollen abgebrochen' INT
    trap 'SIGNAL=TERM; zurueckrollen abgebrochen' TERM
    trap 'SIGNAL=HUP; zurueckrollen abgebrochen' HUP
}

# ================================================================ Stufe 1 (die laufende Fassung)

stufe1() {
    local lokal abruf_rc abruf_meldung remote_url migrationen anzahl linear="ja" backup_dir stempel treiber kandidaten
    local zum_sichern alte datei frei db_groesse noetig einheit
    herkunft_lesen
    sperre_nehmen || besetzt
    traps_vor_marke

    if [[ -f "$MARKE" && "$NUR_ZEIGEN" == "nein" ]]; then
        hinweis "Ein früheres Update wurde unterbrochen: erst zurückrollen mit"
        hinweis "  $ZIEL/deploy/update-debian.sh --wiederaufnehmen"
        hinweis "(der Update-Dienst macht das nach dem Start selbst)."
        ergebnis nicht_begonnen offen
        exit 1
    fi
    [[ -f "$MARKE" ]] && hinweis "Ein früheres Update wurde unterbrochen ($MARKE) – nur nachsehen geht trotzdem."

    if [[ ! -d "$ZIEL/.git" ]]; then
        echo "In $ZIEL liegt kein Git-Arbeitsverzeichnis." >&2
        echo "Dieses Skript aktualisiert nur Installationen, die per git geholt wurden." >&2
        ergebnis nicht_begonnen intern
        exit 1
    fi
    if [[ ! -f "$ZIEL/bin/power" ]]; then
        echo "In $ZIEL steckt keine PowerManagement-Installation." >&2
        ergebnis nicht_begonnen intern
        exit 1
    fi

    ALT="$(g rev-parse HEAD)"
    ALT_ZWEIG="$(g symbolic-ref -q --short HEAD || echo -)"
    if [[ -z "$ZWEIG" ]]; then
        if [[ "$ALT_ZWEIG" == "-" ]]; then
            hinweis "In $ZIEL ist kein Zweig ausgecheckt (abgetrennter Stand) – nichts verändert."
            hinweis "Mit --zweig=<name> einen Zweig wählen."
            ergebnis nicht_begonnen kein_zweig
            exit 1
        fi
        if ! zweig_ok "$ALT_ZWEIG"; then
            hinweis "Der ausgecheckte Zweig hat einen Namen, den das Update nicht verarbeitet – mit --zweig=<name> wählen."
            ergebnis nicht_begonnen kein_zweig
            exit 1
        fi
        ZWEIG="$ALT_ZWEIG"
    fi
    ZWEIG_REF_VORHER="$(g rev-parse -q --verify "refs/heads/$ZWEIG" || echo -)"

    schritt 1 "Stand prüfen" intern
    echo "    Verzeichnis: $ZIEL"
    echo "    Zweig:       $ZWEIG"
    echo "    Aktuell:     $(g log -1 --format='%h %s')"

    # --no-optional-locks: status schriebe sonst den Index neu (--pruefen ändert nichts)
    lokal="$(g --no-optional-locks status --porcelain --untracked-files=no)"
    if [[ -n "$lokal" ]]; then
        hinweis "In $ZIEL gibt es lokale Änderungen:"
        printf '%s\n' "$lokal" | sed 's/^/      /' >&2
        if [[ "$NUR_ZEIGEN" == "ja" ]]; then
            hinweis "(--pruefen: nur angezeigt – ein Update bräche ohne --erzwingen hier ab.)"
        elif [[ "$ERZWINGEN" != "ja" ]]; then
            hinweis "Abbruch. Mit --erzwingen werden sie verworfen – sonst vorher sichern."
            ergebnis nicht_begonnen lokal_geaendert
            exit 1
        else
            hinweis "--erzwingen: die Änderungen werden verworfen."
        fi
    fi

    schritt 2 "Änderungen holen" abruf
    if [[ -z "$GIT_CRED_FILE" ]]; then
        GIT_CRED_FILE="$(zugangsdatei_finden || true)"
    fi
    if [[ -n "$GIT_CRED_FILE" ]]; then
        zugangsdaten_lesen "$GIT_CRED_FILE"
        git_zugang_ablegen "$(g remote get-url origin 2>/dev/null || true)"
    fi
    git_helper_setzen

    # GIT_TERMINAL_PROMPT=0 (oben): ohne gültiges Token mit einer Meldung
    # abbrechen, statt auf eine Eingabe zu warten, die niemand tippt. timeout:
    # ein hängender Abruf hielte sonst die Sperre (und den Update-Dienst) fest.
    # Ausgegeben wird ohne Benutzerteil in Adressen (ohne_zugangsdaten).
    abruf_rc=0
    abruf_meldung="$(LC_ALL=C timeout 600 git -c safe.directory="$ZIEL" -c core.hooksPath=/dev/null -C "$ZIEL" \
        fetch --quiet origin "$ZWEIG" 2>&1 >/dev/null)" || abruf_rc=$?
    if [[ "$abruf_rc" -ne 0 ]]; then
        if [[ -n "$abruf_meldung" ]]; then
            printf '%s\n' "$abruf_meldung" | ohne_zugangsdaten | sed 's/^/      /' >&2
        fi
        [[ "$abruf_rc" -eq 124 ]] && hinweis "Der Abruf brach nach 600 s ab."
        remote_url="$(g remote get-url origin 2>/dev/null | ohne_zugangsdaten || echo '?')"
        hinweis "Die Änderungen ließen sich nicht holen (Remote: ${remote_url:-?})."
        if [[ "$remote_url" == http* ]]; then
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
        if grep -qiE 'authentication failed|could not read (username|password)|returned error: 40[13]|repository not found' \
            <<< "$abruf_meldung"; then
            ergebnis nicht_begonnen kein_zugang
        else
            ergebnis nicht_begonnen abruf
        fi
        exit 1
    fi

    # Ziel: die Spitze des Zweigs oder genau --bis. Nur vorwärts (N2): der
    # installierte Stand muss Vorfahre des Ziels sein – sonst wäre es ein
    # Rückschritt, ein umgeschriebener Verlauf oder ein eigener Commit.
    if [[ -n "$BIS" ]]; then
        if ! g rev-parse -q --verify --end-of-options "$BIS^{commit}" >/dev/null \
            || ! g merge-base --is-ancestor "$BIS" "origin/$ZWEIG"; then
            hinweis "--bis=$BIS liegt nicht auf origin/$ZWEIG – nichts verändert."
            ergebnis nicht_begonnen ziel_ungueltig
            exit 1
        fi
        # Nur Nachfahren auf der Erst-Eltern-Linie: ein Commit mitten aus
        # einem zusammengeführten Zweig ist so nicht wählbar. Beides getrennt
        # erfragen – „--first-parent --ancestry-path“ zusammen verliert den
        # Merge, in dem ein installierter Stand eines Seitenzweigs aufging
        # (dieselben Kandidaten zeigt der Update-Dienst in ist/stand).
        kandidaten="$(g rev-list --first-parent "$ALT..origin/$ZWEIG")"
        if [[ "$ERZWINGEN" != "ja" && "$BIS" != "$ALT" ]] \
            && ! { grep -qx "$BIS" <<< "$kandidaten" \
                   && grep -qx "$BIS" <<< "$(g rev-list --ancestry-path "$ALT..origin/$ZWEIG")"; }; then
            hinweis "--bis=$BIS liegt nicht auf der Erst-Eltern-Linie zwischen dem installierten Stand und origin/$ZWEIG – nichts verändert."
            ergebnis nicht_begonnen ziel_ungueltig
            exit 1
        fi
        NEU="$BIS"
    else
        NEU="$(g rev-parse "origin/$ZWEIG^{commit}")"
    fi
    g merge-base --is-ancestor "$ALT" "$NEU" || linear="nein"

    mqtt_nachholen_pruefen
    einrichtung_nachholen_pruefen

    if [[ "$ALT" == "$NEU" ]]; then
        echo "    Bereits auf dem neuesten Stand ($(g log -1 --format='%h %s'))."
        nachholen_ohne_neuen_code
    fi

    echo "    Neu:         $(g log -1 --format='%h %s' "$NEU")"
    echo "    Linear:      $linear"
    if [[ "$linear" == "ja" ]]; then
        echo
        g log --oneline --no-decorate "$ALT..$NEU" | sed 's/^/      /'
    fi
    migrationen="$(g diff --name-only "$ALT" "$NEU" -- database/migrations || true)"
    if [[ -n "$migrationen" ]]; then
        echo
        echo "    Neue Migrationen:"
        printf '%s\n' "$migrationen" | sed 's/^/      /'
    fi
    if ! g diff --quiet "$ALT" "$NEU" -- deploy/node-red/flows.json; then
        echo
        echo "    Die Node-RED-Flows haben sich geändert."
    fi

    if [[ "$linear" == "nein" ]]; then
        hinweis "Der Stand im Repository liegt nicht vorwärts vom installierten (umgeschriebener Verlauf,"
        hinweis "eigener Commit oder anderer Zweig)."
        if [[ "$NUR_ZEIGEN" == "nein" && "$ERZWINGEN" != "ja" ]]; then
            hinweis "Nichts verändert. Mit --erzwingen ginge es trotzdem."
            ergebnis nicht_begonnen nicht_linear
            exit 1
        fi
    fi

    if [[ "$NUR_ZEIGEN" == "ja" ]]; then
        echo
        echo "    --pruefen: holt per git, ändert sonst nichts."
        anzahl="$(g rev-list --count --no-merges "$ALT..$NEU" 2>/dev/null || echo 0)"
        ergebnis geprueft - "$anzahl neue Commits"
        exit 0
    fi

    # ------------------------------------------------ Vorbereitung
    schritt 3 "Sicherung anlegen" sicherung
    backup_dir="$ZIEL/storage/backups"
    app_verzeichnis "$backup_dir"
    # Schnappschüsse früherer Läufe (abgebrochen, zurückgerollt) – keine
    # Marke steht, also gehört keiner mehr zu einem Update (Befund 23).
    as_app find "$backup_dir" -mindepth 1 -maxdepth 1 -type d -name '.schnappschuss-*' -exec rm -rf -- {} +
    db_groesse=0
    [[ -f "$DB" ]] && db_groesse="$(stat -c %s -- "$DB")"
    frei="$(df -B1 --output=avail -- "$ZIEL/storage" | tail -n 1 | tr -dc '0-9')"
    noetig=$(( 2 * db_groesse + 200 * 1024 * 1024 ))
    if (( ${frei:-0} < noetig )); then
        hinweis "In $ZIEL/storage sind $(( ${frei:-0} / 1024 / 1024 )) MiB frei, gebraucht werden $(( noetig / 1024 / 1024 )) MiB – nichts verändert."
        ergebnis nicht_begonnen platz
        exit 1
    fi
    treiber="$(as_app php -r '$c = require $argv[1]; echo $c["database"]["driver"] ?? "sqlite";' "$ZIEL/config/config.php" 2>/dev/null || echo sqlite)"
    if [[ "$treiber" != "sqlite" && -n "$migrationen" ]]; then
        hinweis "Datenbank ist $treiber – ein Zurückrollen kann sie nicht zurücklegen (vorher selbst sichern)."
    fi

    # Vorher: was lief, und wie antwortete die Oberfläche? Danach muss
    # mindestens dasselbe gelten.
    pruef_adresse
    LOGIN_VORHER="$(login_code)"
    PRUEFUNG_VORHER="fehler"
    if as_app timeout 60 php "$ZIEL/bin/power" pruefen >/dev/null 2>&1; then
        PRUEFUNG_VORHER="ok"
    fi
    AKTIV_VORHER=()
    for einheit in powermanagement-sammler powermanagement-nodered mosquitto; do
        if systemctl is-active --quiet "$einheit.service"; then
            AKTIV_VORHER+=("$einheit")
        fi
    done
    echo "    Vorher: Anmeldeseite HTTP $LOGIN_VORHER, bin/power pruefen $PRUEFUNG_VORHER, aktiv: ${AKTIV_VORHER[*]:-–}"

    wartung_an
    # Der Sammler schreibt Zustände der Datenquellen in die Datenbank – vor
    # der Sicherung anhalten, sonst fehlte beim Zurücklegen, was er danach schrieb.
    # Stirbt das Update vor der Marke (SIGKILL), startet ihn der Update-Dienst
    # wieder („sammler-angehalten“, update-helfer.sh); danach das Zurückrollen.
    if systemctl is-active --quiet powermanagement-sammler.service; then
        SAMMLER_ANGEHALTEN="ja"
        install -d -m 700 "$ZUSTAND"
        : > "$ZUSTAND/sammler-angehalten"
    fi
    systemctl stop powermanagement-sammler.service >/dev/null 2>&1 || true

    stempel="$(date +%Y%m%d-%H%M%S)"
    zum_sichern=()
    if [[ "$treiber" == "sqlite" && -f "$DB" ]]; then
        # VACUUM INTO liefert eine in sich stimmige Kopie, auch während die
        # Oberfläche auf die Datenbank zugreift (WAL-Modus) – ein einfaches
        # cp könnte eine halb geschriebene Seite erwischen.
        app_verzeichnis "$backup_dir/.schnappschuss-$stempel" 700
        SCHNAPPSCHUSS="$backup_dir/.schnappschuss-$stempel/database.sqlite"
        as_app php -r '
            $pdo = new PDO("sqlite:" . $argv[1]);
            $pdo->exec("VACUUM INTO " . $pdo->quote($argv[2]));
        ' "$DB" "$SCHNAPPSCHUSS"
        zum_sichern+=(-C "$backup_dir/.schnappschuss-$stempel" database.sqlite)
    elif [[ "$treiber" != "sqlite" ]]; then
        hinweis "Datenbank ist $treiber – die sichert dieses Skript nicht mit (mysqldump o. Ä. vorher selbst)."
    fi
    SICHERUNG="$backup_dir/vor-update-$stempel.tar.gz"
    as_app tar czf "$SICHERUNG" -C "$ZIEL" config/config.php ${zum_sichern[@]+"${zum_sichern[@]}"}
    as_app chmod 600 -- "$SICHERUNG"
    echo "    $SICHERUNG ($(du -h "$SICHERUNG" | cut -f1))"

    # Ältere Sicherungen aufräumen
    # Die Namen erzeugt dieses Skript selbst (nur Ziffern und -), ls ist hier sicher.
    # shellcheck disable=SC2012
    mapfile -t alte < <(ls -1t "$backup_dir"/vor-update-*.tar.gz 2>/dev/null | tail -n "+$((BEHALTEN + 1))" || true)
    for datei in ${alte[@]+"${alte[@]}"}; do
        [[ -n "$datei" ]] && as_app rm -f -- "$datei"
    done

    # Die eigene Fassung (Stand ALT, nicht BASH_SOURCE – die Datei ersetzt
    # gleich der Checkout): mit ihr rollt der Update-Dienst ein unterbrochenes
    # Update zurück.
    install -d -m 700 "$ZUSTAND" "$ZUSTAND/alt"
    datei="$(mktemp "$ZUSTAND/alt/.update-debian.XXXXXX")"
    g show "$ALT:deploy/update-debian.sh" > "$datei"
    chmod 700 "$datei"
    mv -f -- "$datei" "$ZUSTAND/alt/update-debian.sh"

    # Erst die Traps, dann die Marke: ab hier rollt jeder Abbruch zurück
    # (bis zum Checkout nur git, also nichts).
    PHASE="stufe1"
    traps_nach_marke
    marke_schreiben
    rm -f -- "$ZUSTAND/sammler-angehalten"
    # Auf die Platte, bevor git etwas ändert: nach einem Stromausfall müssen
    # Marke, alte Fassung und Schnappschuss lesbar sein (eine neu angelegte
    # Datei kann auf ext4 sonst leer zurückbleiben).
    if [[ "$SCHNAPPSCHUSS" != "-" ]]; then
        as_app sync -- "$SCHNAPPSCHUSS"
    fi
    sync -- "$ZUSTAND/alt/update-debian.sh" "$ZUSTAND/alt" "$MARKE" "$ZUSTAND"

    # ------------------------------------------------ Quellcode
    schritt 4 "Quellcode aktualisieren" checkout
    if [[ "$ERZWINGEN" == "ja" ]]; then
        g reset --hard --quiet
        if [[ "$NEU" == "$(g rev-parse "origin/$ZWEIG^{commit}")" ]]; then
            g checkout --quiet -B "$ZWEIG" "origin/$ZWEIG"
        else
            g checkout --quiet -B "$ZWEIG" "$NEU"
        fi
    elif [[ "$ZWEIG_REF_VORHER" == "-" ]]; then
        # Neuer lokaler Zweig: genau auf das Ziel, mit origin als Gegenstück
        g checkout --quiet -b "$ZWEIG" "$NEU"
        g branch --quiet --set-upstream-to="origin/$ZWEIG" "$ZWEIG"
    else
        g checkout --quiet "$ZWEIG"
        g merge --ff-only --quiet "$NEU"
    fi
    if [[ "$(g rev-parse HEAD)" != "$NEU" ]]; then
        hinweis "Nach dem Auschecken steht $ZWEIG auf $(g rev-parse --short HEAD), nicht auf $(kurz "$NEU")."
        false
    fi
    echo "    Jetzt auf $(g log -1 --format='%h %s')"
    marke_setzen phase checkout

    # ------------------------------------------------ neue Fassung übernimmt
    # bash liest ein laufendes Skript Stück für Stück – der Checkout hat
    # diese Datei eben ersetzt. Die Schritte ab hier gehören zum neuen Code:
    # dessen Fassung übernimmt per exec (Befund 21). Sperre (fd 9), stdout
    # und stderr gehen mit; die Marke trägt alles Weitere.
    if hat_schnittstelle "$ZIEL/deploy/update-debian.sh"; then
        if ! bash -n "$ZIEL/deploy/update-debian.sh"; then
            hinweis "Die neue Fassung von deploy/update-debian.sh ist fehlerhaft (bash -n)."
            zurueckrollen neue_fassung
        fi
        echo "    Die neue Fassung des Update-Skripts übernimmt."
        arbeit_aufraeumen
        trap - EXIT
        shopt -s execfail
        # shellcheck disable=SC2093  # execfail: scheitert exec, geht es unten weiter
        exec env PM_UPDATE_STUFE=2 PM_UPDATE_SPERRE=geerbt /bin/bash "$ZIEL/deploy/update-debian.sh" \
            --ziel="$ZIEL" --stufe=2
        hinweis "Die neue Fassung ließ sich nicht starten."
        zurueckrollen neue_fassung
    fi
    # Ohne Schnittstelle (nur nach --erzwingen auf einen alten Stand möglich):
    # Stufe 2 in diesem Prozess.
    stufe2
}

# ALT == NEU: nichts Neues. Fehlt aber Einrichtung (Update-Funktion,
# Einheiten, Ordner – ein älteres Skript hat den Code geholt) oder sollen die
# Flows ersetzt werden, wird im selben Prozess abgeglichen, ohne Sicherung
# und Migration; neu gestartet wird nur Geändertes.
nachholen_ohne_neuen_code() {
    local satz=""
    if [[ "$NUR_ZEIGEN" == "ja" ]]; then
        if [[ "$EINRICHTUNG_NACHHOLEN" == "ja" || "$MQTT_NACHHOLEN" == "ja" ]]; then
            echo "    Einrichtung unvollständig – ein Lauf ohne --pruefen holt sie nach."
        fi
        echo
        echo "    --pruefen: holt per git, ändert sonst nichts."
        ergebnis geprueft - "keine neuen Commits"
        exit 0
    fi
    if [[ "$FLOWS_ERSETZEN" != "ja" && "$MQTT_NACHHOLEN" != "ja" && "$EINRICHTUNG_NACHHOLEN" != "ja" ]]; then
        ergebnis aktuell -
        exit 0
    fi
    if [[ "$FLOWS_ERSETZEN" == "ja" ]]; then
        echo "    --flows-ersetzen: nur die Node-RED-Flows werden neu eingespielt."
        satz="Node-RED-Flows neu eingespielt"
    fi
    if [[ "$MQTT_NACHHOLEN" == "ja" ]]; then
        echo "    Eigene MQTT-Zugänge sind noch nicht eingerichtet – wird nachgeholt."
        satz="MQTT-Zugänge eingerichtet"
        WARNUNGEN+=(nachgeholt)
    fi
    if [[ "$EINRICHTUNG_NACHHOLEN" == "ja" ]]; then
        echo "    Einrichtung unvollständig (Update-Funktion, Einheiten oder storage/-Ordner) – wird nachgeholt."
        satz="Update-Funktion eingerichtet"
        WARNUNGEN+=(nachgeholt)
    fi
    schritt 8 "Dienste und Konfiguration abgleichen" einrichtung
    ABGLEICH_BEGONNEN="ja"
    ordner_anlegen
    abgleichen
    schritt 9 "Dienste starten" dienste
    dienste_starten geaendert
    set +e
    trap - ERR
    trap '' INT TERM HUP
    dienst_anstossen
    flows_behalten_melden
    ergebnis aktuell - "$satz"
    exit 0
}

# ================================================================ Stufe 2 (die neue Fassung)

# Eintritt per exec aus Stufe 1: nur mit PM_UPDATE_STUFE=2 und der geerbten
# Sperre, und nur mit einer lesbaren Marke in Phase „checkout“.
stufe2_eintritt() {
    local sperre_fehlte="nein"
    if [[ "${PM_UPDATE_STUFE:-}" != 2 || "${PM_UPDATE_SPERRE:-}" != "geerbt" ]]; then
        echo "--stufe=2 ist intern: nur die vorige Stufe ruft es per exec auf." >&2
        exit 1
    fi
    if flock -n 9 2>/dev/null; then
        SPERRE_GEHALTEN="ja"
    else
        # Die Sperre kam nicht mit. Hält sie ein anderer, läuft dort ein
        # Update – dann nichts anfassen. Sonst ist die Marke verwaist.
        sperre_fehlte="ja"
        unset PM_UPDATE_SPERRE
        sperre_nehmen || besetzt
    fi
    if ! marke_lesen; then
        hinweis "Die Marke $MARKE fehlt oder ist unlesbar – Stufe 2 kann nicht weiter."
        ergebnis unvollstaendig intern "Handarbeit nötig"
        exit 3
    fi
    marke_uebernehmen
    AUSLOESER="$M_AUSLOESER"
    VON="$M_VON"
    if [[ "$M_ERGEBNIS_DATEI" != "-" ]] && ausgabe_pfad_ok "$M_ERGEBNIS_DATEI"; then
        ERGEBNIS_DATEI="$M_ERGEBNIS_DATEI"
    fi
    if [[ "$M_FORTSCHRITT_DATEI" != "-" ]] && ausgabe_pfad_ok "$M_FORTSCHRITT_DATEI"; then
        FORTSCHRITT_DATEI="$M_FORTSCHRITT_DATEI"
    fi
    if [[ "$sperre_fehlte" == "ja" || "$PHASE" != "checkout" ]]; then
        hinweis "Stufe 2 ohne geerbte Sperre oder in Phase „$PHASE“."
        zurueckrollen intern
    fi
    stufe2
}

stufe2() {
    # exec hat die Traps gelöscht – neu setzen, dann zählt die Marke als Stufe 2.
    traps_nach_marke
    marke_setzen phase stufe2
    NEU_MERKEN="ja"

    schritt 5 "Rechte und Ordner" einrichtung
    rechte_setzen

    schritt 6 "Migrationen ausführen" migration
    marke_setzen migriert ja
    MIGRIERT="ja"
    # Erst auf der Platte, dann migrieren: nach einem Stromausfall muss die
    # Marke sagen, dass die Datenbank zurück muss.
    sync -- "$MARKE" "$ZUSTAND"
    as_app php "$ZIEL/bin/power" migrate
    local treiber
    treiber="$(as_app php -r '$c = require $argv[1]; echo $c["database"]["driver"] ?? "sqlite";' "$ZIEL/config/config.php" 2>/dev/null || echo sqlite)"
    if [[ "$treiber" != "sqlite" ]] && ! g diff --quiet "$ALT" "$NEU" -- database/migrations; then
        WARNUNGEN+=(treiber)
    fi

    schritt 7 "Fehlende Pakete nachziehen" pakete
    pakete_nachziehen

    schritt 8 "Dienste und Konfiguration abgleichen" einrichtung
    abgleichen

    schritt 9 "Dienste starten" dienste
    dienste_starten voll

    # Fehlende Kacheln nicht hier, unter der Schreibsperre – erst nach dem
    # Punkt ohne Rückweg, in einer eigenen Einheit (kacheln_anstossen).
    schritt 10 "Grundrisse kacheln" intern
    echo "    Fehlende Kacheln und offene Aufträge rechnet nach der Prüfung eine eigene Einheit nach (powermanagement-kacheln)."

    schritt 11 "Prüfen" pruefung
    if ! pflichtpruefung; then
        PRUEFUNG="fehler"
        zurueckrollen pruefung
    fi
    # Die Pflichtprüfung ist bestanden: ab hier rollt nichts mehr zurück –
    # erst dann die Schreibsperre aufheben. Was danach gespeichert wird,
    # nähme ein Zurückrollen sonst mit (die Wunschprüfung wartet bis zu 10 min).
    eingespielt_festhalten
    wartung_aus
    kacheln_anstossen
    wunschpruefung

    schritt 12 "Fertig" intern
    erfolg
}

# Der Punkt ohne Rückweg: keine Traps mehr, die zurückrollen, erst die Marke
# weg (auf die Platte), dann Schnappschuss und Kopie der alten Fassung. Ein
# Signal danach überspringt nur noch die Wunschprüfung.
eingespielt_festhalten() {
    set +e
    trap - ERR
    trap 'NACH_SIGNAL=ja' INT TERM HUP
    marke_loeschen
    sync -- "$ZUSTAND" 2>/dev/null
    schnappschuss_loeschen
    rm -f -- "$ZUSTAND/alt/update-debian.sh"
    return 0
}

pakete_nachziehen() {
    local gebraucht fehlt p
    # Neuere Stände brauchen womöglich Pakete, die ältere Installationen
    # nicht haben. Nachinstalliert wird nur, was fehlt – Aktualisierungen
    # vorhandener Pakete bleiben apt bzw. unattended-upgrades überlassen.
    gebraucht=(nginx php-fpm php-cli php-sqlite3 php-gd php-curl php-mbstring php-xml
               poppler-utils git curl ca-certificates gnupg openssl logrotate
               wireguard-tools mosquitto-clients)
    [[ "$MIT_MOSQUITTO" == "ja" ]] && gebraucht+=(mosquitto)
    [[ "$MIT_NODERED" == "ja" ]] && gebraucht+=(nodejs npm)
    [[ "$MIT_INFLUX" == "ja" ]] && gebraucht+=(influxdb2 influxdb2-cli)
    fehlt=()
    for p in "${gebraucht[@]}"; do
        [[ "$(dpkg-query -W -f='${Status}' "$p" 2>/dev/null || true)" == *"install ok installed"* ]] || fehlt+=("$p")
    done
    if [[ ${#fehlt[@]} -gt 0 ]]; then
        echo "    Nachinstallieren: ${fehlt[*]}"
        export DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1
        apt_holen
        "${APT[@]}" install -y --no-install-recommends "${fehlt[@]}" >/dev/null
    else
        echo "    Alles vorhanden."
    fi
    if [[ "$MIT_NODERED" == "ja" && ! -f "$NODERED_APP/node_modules/node-red/package.json" ]]; then
        echo "    Node-RED fehlt in $NODERED_APP – wird installiert …"
        install -d -m 755 "$NODERED_APP"
        npm install --prefix "$NODERED_APP" --omit=dev --no-audit --no-fund --loglevel=error "node-red@4" >/dev/null
    fi
    return 0
}

erfolg() {
    local erg="eingespielt" zweig_befehl
    # Das Update ist eingespielt – nichts rollt mehr zurück, und ein Signal
    # unterbricht das Aufräumen nicht.
    eingespielt_festhalten
    trap '' INT TERM HUP
    [[ ${#WARNUNGEN[@]} -gt 0 ]] && erg="eingespielt_mit_warnung"

    if [[ "$ALT_ZWEIG" == "-" ]]; then
        zweig_befehl="git -C $ZIEL checkout -q -f --detach $ALT"
    else
        zweig_befehl="git -C $ZIEL checkout -q -f -B $ALT_ZWEIG $ALT"
    fi
    cat <<ZUSAMMENFASSUNG

  Vorher:    $(g log -1 --format='%h' "$ALT")
  Jetzt:     $(g log -1 --format='%h %s')
ZUSAMMENFASSUNG
    [[ ${#WARNUNGEN[@]} -gt 0 ]] && echo "  Hinweise:  ${WARNUNGEN[*]}"
    flows_behalten_melden
    if [[ "$SICHERUNG" != "-" ]]; then
        cat <<ZUSAMMENFASSUNG
  Sicherung: $SICHERUNG

  Zurückrollen, falls nötig – die Datenbank aus der Sicherung nur als
  $APP_USER lesen und schreiben (storage/ gehört der Anwendung; als root
  folgten install und tar dort einem Verweis):
    systemctl stop powermanagement-sammler ${FPM_VERSION:+php$FPM_VERSION-fpm}
    $zweig_befehl
    runuser -u $APP_USER -- sh -c 'tar -xzOf "\$1" database.sqlite > "\$2.zurueck" && [ -s "\$2.zurueck" ] && rm -f -- "\$2-wal" "\$2-shm" && mv -f -- "\$2.zurueck" "\$2"' sh $SICHERUNG $DB
    $ZIEL/deploy/update-debian.sh --nur-abgleichen
    systemctl start ${FPM_VERSION:+php$FPM_VERSION-fpm} powermanagement-sammler

ZUSAMMENFASSUNG
    fi
    dienst_anstossen
    ergebnis "$erg" -
    exit 0
}

# ================================================================ --nur-abgleichen
# Für die Konsole und für das Zurückrollen (als Kind der neueren Fassung,
# mit geerbter Sperre): kein git, keine Migration, keine Sicherung, keine
# Marke, kein eigenes Zurückrollen – ein Fehler endet mit rc ≠ 0.
nur_abgleichen() {
    sperre_nehmen || besetzt
    if [[ -f "$MARKE" && "${PM_UPDATE_SPERRE:-}" != "geerbt" ]]; then
        hinweis "Ein früheres Update wurde unterbrochen: erst $ZIEL/deploy/update-debian.sh --wiederaufnehmen"
        exit 1
    fi
    if [[ ! -f "$ZIEL/bin/power" ]]; then
        echo "In $ZIEL steckt keine PowerManagement-Installation." >&2
        exit 1
    fi
    trap 'hinweis "Der Abgleich brach ab (Zeile $LINENO, Schritt „$SCHRITT_NAME“)."; exit 1' ERR
    trap 'hinweis "Abgebrochen."; exit 1' INT TERM HUP
    schritt 8 "Dienste und Konfiguration abgleichen" einrichtung
    rechte_setzen
    abgleichen
    schritt 9 "Dienste starten" dienste
    dienste_starten geaendert
    trap - ERR INT TERM HUP
    echo "    Abgeglichen: $(g log -1 --format='%h %s' 2>/dev/null || echo "$ZIEL")"
    exit 0
}

# ================================================================ --wiederaufnehmen
# Stirbt git mitten im Checkout (SIGKILL, Strom weg), bleiben Sperrdateien
# liegen, an denen jeder weitere checkout scheitert. Hier hält dieser Lauf
# update.lock – kein Update und keine Installation arbeitet gerade im Checkout.
git_sperren_raeumen() {
    local f z
    for f in index.lock HEAD.lock ORIG_HEAD.lock packed-refs.lock config.lock; do
        if [[ -f "$ZIEL/.git/$f" && ! -L "$ZIEL/.git/$f" ]]; then
            rm -f -- "$ZIEL/.git/$f" && echo "    verwaiste git-Sperre entfernt: .git/$f"
        fi
    done
    for z in "$ZWEIG" "$ALT_ZWEIG"; do
        [[ "$z" != "-" ]] && zweig_ok "$z" || continue
        f="$ZIEL/.git/refs/heads/$z.lock"
        if [[ -f "$f" && ! -L "$f" ]]; then
            rm -f -- "$f" && echo "    verwaiste git-Sperre entfernt: .git/refs/heads/$z.lock"
        fi
    done
    return 0
}

# Ein Update, das ohne Zurückrollen endete (SIGKILL, Strom weg): mit den
# Werten der Marke zurückrollen. Ergebnis- und Fortschrittsdatei gelten aus
# der Kommandozeile, nicht aus der Marke.
wiederaufnehmen() {
    herkunft_lesen
    sperre_nehmen || besetzt
    if [[ ! -f "$MARKE" ]]; then
        echo "    Kein unterbrochenes Update – nichts zu tun."
        ergebnis aktuell - "nichts zu tun"
        exit 0
    fi
    if ! marke_lesen; then
        hinweis "Die Marke $MARKE ist unlesbar – von Hand zurückrollen (docs/installation-debian-lxc.md)."
        ergebnis unvollstaendig intern "Handarbeit nötig"
        exit 3
    fi
    marke_uebernehmen
    echo "    Unterbrochen in Phase „$PHASE“: $(kurz "$ALT") → $(kurz "$NEU") (Zweig $ZWEIG)"
    git_sperren_raeumen
    trap 'zurueckrollen unterbrochen "$LINENO"' ERR
    trap 'SIGNAL=INT; zurueckrollen unterbrochen' INT
    trap 'SIGNAL=TERM; zurueckrollen unterbrochen' TERM
    trap 'SIGNAL=HUP; zurueckrollen unterbrochen' HUP
    # Ein abgerissenes apt/dpkg zuerst zu Ende bringen
    if [[ -n "$(dpkg --audit 2>/dev/null || true)" ]]; then
        echo "    dpkg war mitten in einer Installation – wird abgeschlossen"
        DEBIAN_FRONTEND=noninteractive dpkg --configure -a --force-confdef --force-confold || true
    fi
    zurueckrollen unterbrochen
}

# ================================================================ main

main() {
    vorgaben
    installation_lesen
    farben
    optionen "$@"
    DB="$ZIEL/storage/database.sqlite"
    umgebung_pruefen
    case "$MODUS" in
        stufe2)          stufe2_eintritt ;;
        nur_abgleichen)  nur_abgleichen ;;
        wiederaufnehmen) wiederaufnehmen ;;
        *)               stufe1 ;;
    esac
}
main "$@"; exit $?
