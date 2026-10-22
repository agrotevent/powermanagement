#!/usr/bin/env bash
# update-debian-schnittstelle: 1
#
# Attrappe für deploy/update-debian.sh (tests/update-helfer.php).
#
# Der Test kopiert sie je Fall in einen eigenen Ordner und legt daneben die
# Datei „fall“ (je Zeile „schlüssel wert“). Die Attrappe schreibt argv,
# Umgebung und umask nach „aufruf“, gibt Farben, Umlaute und eine Adresse mit
# Zugangsdaten aus, schreibt Fortschritt und Ergebnisdatei und endet mit dem
# vorgegebenen Exit-Code.
#
#   ergebnis <code>      Ergebnis (fehlt: keine Ergebnisdatei)
#   grund <code>         Grund (Vorgabe -)
#   alt / neu <sha40>    Stände (Vorgabe -)
#   sicherung <name>     Name der Sicherung (Vorgabe -)
#   pruefung <code>      ok | warnung | fehler | - (Vorgabe -)
#   warnung <code>       eine Warnung (höchstens eine Zeile)
#   rc <n>               Exit-Code (Vorgabe 0)
#   gross ja             2 MiB Ausgabe (prüft: kein SIGPIPE)
#   lang ja              bis 60 s warten; TERM → zurueckgerollt abgebrochen, Exit 2
#   ziel <dir>           mit --pruefen: echtes „git fetch“ in diesem Klon
#   verlauf <datei>      eine Zeile im Format des Verlaufs anhängen
#   marke_loeschen <f>   diese Datei löschen (Zurückrollen nach Wiederaufnehmen)
#   marke_vorlage <f>    am Ende nach marke_ziel kopieren (wie Stufe 1 die Marke anlegt)
#   marke_ziel <f>
#
# Läuft wie das echte Skript ohne Terminal und mit leerer Umgebung (env -i).

set -uo pipefail
HIER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
declare -A F=()
while IFS= read -r zeile || [[ -n "$zeile" ]]; do
    if [[ "$zeile" =~ ^([a-z_]+)\ (.*)$ ]]; then
        F[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
    fi
done < "$HIER/fall"

ERGEBNIS_DATEI=""
FORTSCHRITT_DATEI=""
MODUS="update"
for a in "$@"; do
    case "$a" in
        --ergebnis-datei=*)    ERGEBNIS_DATEI="${a#*=}" ;;
        --fortschritt-datei=*) FORTSCHRITT_DATEI="${a#*=}" ;;
        --pruefen)             MODUS="pruefen" ;;
        --wiederaufnehmen)     MODUS="wiederaufnehmen" ;;
    esac
done

{
    echo "# aufruf"
    for a in "$@"; do
        printf 'arg %s\n' "$a"
    done
    printf 'umask %s\n' "$(umask)"
    printf 'modus %s\n' "$MODUS"
    env | LC_ALL=C sort | sed 's/^/env /'
} > "$HIER/aufruf"

fortschritt() {
    [[ -n "$FORTSCHRITT_DATEI" ]] || return 0
    printf 'schritt %s 12 %s\n' "$1" "$2" > "$FORTSCHRITT_DATEI.neu" && mv -f "$FORTSCHRITT_DATEI.neu" "$FORTSCHRITT_DATEI"
}

ergebnis() {   # ergebnis <ergebnis> <grund>
    [[ -n "$ERGEBNIS_DATEI" ]] || return 0
    {
        echo "# powermanagement update-ergebnis 1"
        echo "ergebnis $1"
        echo "grund $2"
        echo "alt ${F[alt]:--}"
        echo "neu ${F[neu]:--}"
        echo "zweig main"
        echo "sicherung ${F[sicherung]:--}"
        echo "pruefung ${F[pruefung]:--}"
        [[ -n "${F[warnung]:-}" ]] && echo "warnung ${F[warnung]}"
        echo "zukunft unbekannter-schluessel-einer-neueren-fassung"
    } > "$ERGEBNIS_DATEI.neu"
    mv -f "$ERGEBNIS_DATEI.neu" "$ERGEBNIS_DATEI"
}

printf '\n\033[1;34m==> Stand prüfen\033[0m\n'
echo "    Grüße aus der Attrappe: Änderungen, Übergabe, ß"
echo "    Remote: https://x-access-token:ghp_PRUEFUNG0123456789abcdef@github.com/x/y"
printf 'Zeile mit Wagenrücklauf\r und Steuerzeichen \001 Ende\n'
fortschritt 1 "Stand prüfen"

if [[ "${F[gross]:-}" == "ja" ]]; then
    head -c 2097152 /dev/zero | tr '\0' 'x' | fold -w 100
    echo
fi

if [[ "$MODUS" == "pruefen" && -n "${F[ziel]:-}" ]]; then
    fortschritt 2 "Änderungen holen"
    git -c safe.directory="${F[ziel]}" -C "${F[ziel]}" fetch -q origin || { ergebnis nicht_begonnen abruf; exit 1; }
fi

if [[ "${F[lang]:-}" == "ja" ]]; then
    trap 'echo "TERM erhalten"; echo TERM > "$HIER/signal"; ergebnis zurueckgerollt abgebrochen; exit 2' TERM
    fortschritt 6 "Migrationen ausführen"
    : > "$HIER/laeuft"
    for _ in $(seq 1 300); do
        sleep 0.2
    done
fi

fortschritt 12 "Fertig"
if [[ -n "${F[verlauf]:-}" ]]; then
    printf 'eintrag %s %s %s %s %s aktualisieren %s %s %s %s %s\n' "$(date +%s)" "$(date +%s)" \
        "${PM_UPDATE_KENNUNG:--}" "${PM_UPDATE_AUSLOESER:-konsole}" "${PM_UPDATE_VON:-root}" \
        "${F[ergebnis]:-nicht_begonnen}" "${F[grund]:--}" "${F[alt]:--}" "${F[neu]:--}" "${F[sicherung]:--}" \
        >> "${F[verlauf]}"
fi
if [[ -n "${F[marke_loeschen]:-}" ]]; then
    rm -f -- "${F[marke_loeschen]}"
fi
if [[ -n "${F[marke_vorlage]:-}" && -n "${F[marke_ziel]:-}" ]]; then
    cp -- "${F[marke_vorlage]}" "${F[marke_ziel]}"
fi
if [[ -n "${F[ergebnis]:-}" ]]; then
    ergebnis "${F[ergebnis]}" "${F[grund]:--}"
fi
printf '\n==> Ergebnis: %s\n' "${F[ergebnis]:-keins}"
echo "fertig" > "$HIER/fertig"
exit "${F[rc]:-0}"
