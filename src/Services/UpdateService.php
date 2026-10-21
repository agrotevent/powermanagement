<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Berichte\Kundenmodus;
use App\Kunden\Kundenbereich;
use App\Support\Config;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\Settings;

/**
 * Update aus der Oberfläche – die Seite der Anwendung.
 *
 * Die Anwendung startet nie selbst ein Update (kein exec, sudo, systemctl).
 * Sie legt einen Auftrag nach storage/update/soll/auftrag; der root-Helfer
 * deploy/update-helfer.sh (powermanagement-update.path) liest ihn als
 * App-Benutzer, prüft ihn und ruft deploy/update-debian.sh. Was er tut,
 * schreibt er als App-Benutzer nach storage/update/ist: Lebenszeichen
 * (helfer), Stand aus git (stand), den laufenden bzw. letzten Lauf (lauf),
 * das Protokoll und den Verlauf. Hier wird das nur gelesen.
 *
 * Alle Dateien haben eine Kopfzeile mit Formatnummer und je Zeile
 * „schlüssel wert“; neue Felder kommen nur dazu. Unbekannte Zeilen werden
 * überlesen – nach einem Update fragt noch das alte update.js den neuen
 * Server, und der neue Code liest die Dateien des alten Helfers.
 *
 * Alles verträgt ein fehlendes storage/update (Lauf 1 des Umstiegs,
 * Demo-Gelände): dann gilt „nicht eingerichtet“, ohne Warnung.
 */
final class UpdateService
{
    public const KOPF_AUFTRAG = '# powermanagement update-auftrag 1';
    public const KOPF_LAUF = '# powermanagement update-lauf 1';
    public const KOPF_STAND = '# powermanagement update-stand 1';
    public const KOPF_PROTOKOLL = '# powermanagement update-protokoll 1';
    public const KOPF_VERLAUF = '# powermanagement update-verlauf 1';
    public const KOPF_HELFER = '# powermanagement update-helfer 1';
    public const KOPF_WARTUNG = '# powermanagement update-wartung 1';

    public const AKTIONEN = ['pruefen', 'aktualisieren'];
    public const AUSLOESER = ['hand', 'automatik', 'konsole'];

    /** Puls älter: „keine Rückmeldung“; Auftrag nicht übernommen: „hängt“; Helfer still; Lauf verschwunden. */
    public const PULS_GRENZE = 90, HAENGT = 120, STILL = 2700, VERSCHWUNDEN = 1800;
    /** Die Schreibsperre wirkt, solange sie jünger ist – oder der Lauf noch pulst. */
    public const WARTUNG_MAX = 3600, WARTUNG_PULS = 180;
    /** Suchen höchstens so oft (der Helfer drosselt zusätzlich auf 60 s). */
    public const SUCHEN_ABSTAND = 30;
    /**
     * In storage/update: Schreibende Anfragen halten diese Datei geteilt
     * gesperrt, solange sie laufen (anfrageAnmelden()) – update-debian.sh
     * wartet nach dem Setzen der Schreibsperre exklusiv darauf (wartung_an).
     */
    public const ANFRAGEN = '.anfragen';
    /** Wer erst beim Schreiben merkt, dass ein Update begonnen hat (LocationUebertrag::anwenden()). */
    public const NICHTS_UEBERNOMMEN = 'Gerade läuft ein Update – nichts übernommen, bitte in etwa einer Minute noch einmal.';
    /** … dasselbe vor dem Hochladen ins Repository (LocationSync, VerteilerSync): nichts geschoben, nichts eingetragen. */
    public const NICHTS_HOCHGELADEN = 'Gerade läuft ein Update – nichts hochgeladen, bitte in etwa einer Minute noch einmal.';
    /** … und nach dem Hochladen: Das Repository hat es, der Vermerk hier fehlt (LocationSync, VerteilerSync). */
    public const VERMERK_FEHLT = 'Während des Hochladens hat hier ein Update begonnen – der Vermerk, was abgeglichen ist, fehlt deshalb noch. Nach dem Update noch einmal hochladen: Es meldet dann „schon derselbe Stand“ und trägt ihn nach.';
    /** Abgewiesene Änderung (wartungsSperre()) – für das Personal … */
    public const GESPERRT = 'Gerade läuft ein Update – Änderungen sind in etwa einer Minute wieder möglich.';
    /** … und im Kundenbereich (Kundenzugänge, Einrichtungslink): im Ton des Kundenbereichs, ohne Technik. */
    public const GESPERRT_KUNDE = 'Gerade wird das System aktualisiert – nichts gespeichert. Bitte versuchen Sie es in etwa einer Minute noch einmal.';

    /** Zuletzt ins Audit übernommene Verlaufszeilen (JSON-Liste, keine Migration). */
    public const VERMERKT = 'update.vermerkt';

    private const SHA = '/^[0-9a-f]{40}$/';
    private const KENNUNG = '/^[0-9a-f]{32}$/';
    private const VON = '/^[A-Za-z0-9._@-]{1,64}$/';
    private const CODE = '/^[a-z_]{1,40}$/';

    /** @var array<string,mixed> Je Anfrage gelesen (Seite, Navigation, Kopfleiste) */
    private static array $merker = [];

    /** @var resource|null Die Anmeldung dieser Anfrage (anfrageAnmelden()) */
    private static $anfrage = null;

    // ================================================================ Orte

    /** storage/update – in Prüfungen umgebogen über „update.verzeichnis“. */
    public static function verzeichnis(): string
    {
        $v = (string) Config::get('update.verzeichnis', '');
        return rtrim($v !== '' ? $v : APP_ROOT . '/storage/update', '/');
    }

    private static function ist(string $datei): string
    {
        return self::verzeichnis() . '/ist/' . $datei;
    }

    /** Für Prüfskripte: gelesene Dateien vergessen. */
    public static function vergessen(): void
    {
        self::$merker = [];
    }

    // ================================================================ Lesen

    /**
     * Eine Datei mit Kopfzeile lesen: höchstens $max Byte, sonst null.
     *
     * @return list<array{0:string,1:string}>|null Zeilen „schlüssel wert“ (Werte UTF-8-bereinigt)
     */
    private static function zeilen(string $pfad, string $kopf, int $max): ?array
    {
        clearstatcache(true, $pfad);
        if (!@is_file($pfad)) {
            return null;
        }
        $groesse = @filesize($pfad);
        if ($groesse === false || $groesse > $max) {
            return null;
        }
        $inhalt = @file_get_contents($pfad, false, null, 0, $max + 1);
        if (!is_string($inhalt) || strlen($inhalt) > $max) {
            return null;
        }
        $roh = explode("\n", $inhalt);
        if (rtrim($roh[0], "\r") !== $kopf) {
            return null;
        }
        $out = [];
        foreach (array_slice($roh, 1) as $zeile) {
            if (preg_match('/^([a-z_]{1,40}) (.*)$/s', rtrim($zeile, "\r"), $m) !== 1) {
                continue;
            }
            $out[] = [$m[1], self::text($m[2])];
        }
        return $out;
    }

    /** UTF-8 ohne Steuerzeichen (TAB wird zum Leerzeichen). */
    private static function text(string $wert): string
    {
        $wert = mb_scrub($wert, 'UTF-8');
        return (string) preg_replace('/[\x00-\x1f\x7f]/u', '', str_replace("\t", ' ', $wert));
    }

    /** @param list<array{0:string,1:string}> $zeilen @return array<string,string> je Schlüssel der erste Wert */
    private static function felder(array $zeilen): array
    {
        $f = [];
        foreach ($zeilen as [$k, $v]) {
            $f[$k] ??= $v;
        }
        return $f;
    }

    private static function zahl(?string $wert): ?int
    {
        return $wert !== null && preg_match('/^[0-9]{1,12}$/', $wert) === 1 ? (int) $wert : null;
    }

    private static function sha(?string $wert): ?string
    {
        return $wert !== null && preg_match(self::SHA, $wert) === 1 ? $wert : null;
    }

    private static function code(?string $wert): ?string
    {
        return $wert !== null && $wert !== '-' && preg_match(self::CODE, $wert) === 1 ? $wert : null;
    }

    private static function gemerkt(string $schluessel, callable $lesen): mixed
    {
        if (!array_key_exists($schluessel, self::$merker)) {
            self::$merker[$schluessel] = $lesen();
        }
        return self::$merker[$schluessel];
    }

    /**
     * Lebenszeichen des Helfers (ist/helfer).
     *
     * @return array{zeit:int, fassung:?string, oberflaeche:bool, automatik:bool, offen:string, pfad:string}|null
     */
    public static function helfer(): ?array
    {
        return self::gemerkt('helfer', static function (): ?array {
            $z = self::zeilen(self::ist('helfer'), self::KOPF_HELFER, 4096);
            if ($z === null) {
                return null;
            }
            $f = self::felder($z);
            $zeit = self::zahl($f['zeit'] ?? null);
            if ($zeit === null) {
                return null;
            }
            $offen = $f['offen'] ?? 'nein';
            $pfad = self::code($f['pfad'] ?? null) ?? 'ok';
            return [
                'zeit'        => $zeit,
                'fassung'     => self::sha($f['fassung'] ?? null),
                // Nur „ja“ schaltet ein – was der Helfer nicht sagt, gilt als an.
                'oberflaeche' => ($f['oberflaeche'] ?? 'ja') !== 'nein',
                'automatik'   => ($f['automatik'] ?? 'ja') !== 'nein',
                'offen'       => in_array($offen, ['nein', 'ja', 'aufgegeben'], true) ? $offen : 'ja',
                'pfad'        => $pfad,
            ];
        });
    }

    /**
     * Der laufende bzw. letzte Lauf (ist/lauf). Abgelehnte Läufe tragen „-“
     * in Aktion, Auslöser, Von und Ziel – das wird null.
     *
     * @return array<string,mixed>|null
     */
    public static function lauf(): ?array
    {
        return self::gemerkt('lauf', static function (): ?array {
            $z = self::zeilen(self::ist('lauf'), self::KOPF_LAUF, 8192);
            if ($z === null) {
                return null;
            }
            $f = self::felder($z);
            $kennung = $f['kennung'] ?? '';
            $zustand = $f['zustand'] ?? '';
            if (preg_match(self::KENNUNG, $kennung) !== 1 || !in_array($zustand, ['laeuft', 'fertig', 'fehler'], true)) {
                return null;
            }
            $schritt = ['nr' => 0, 'gesamt' => 12, 'name' => ''];
            if (preg_match('/^([0-9]{1,2}) ([0-9]{1,2}) (.+)$/u', (string) ($f['schritt'] ?? ''), $m) === 1) {
                $name = trim($m[3]);
                $schritt = ['nr' => (int) $m[1], 'gesamt' => max(1, (int) $m[2]), 'name' => $name === '-' ? '' : mb_substr($name, 0, 200)];
            }
            $warnungen = [];
            foreach ($z as [$k, $v]) {
                if ($k === 'warnung' && preg_match(self::CODE, $v) === 1 && !in_array($v, $warnungen, true) && count($warnungen) < 20) {
                    $warnungen[] = $v;
                }
            }
            $aktion = $f['aktion'] ?? '-';
            $ausloeser = $f['ausloeser'] ?? '-';
            $von = $f['von'] ?? '-';
            $pruefung = $f['pruefung'] ?? '-';
            $sicherung = $f['sicherung'] ?? '-';
            $protokoll = $f['protokoll'] ?? '-';
            return [
                'kennung'    => $kennung,
                'aktion'     => in_array($aktion, ['pruefen', 'aktualisieren', 'wiederaufnehmen'], true) ? $aktion : null,
                'ausloeser'  => in_array($ausloeser, ['hand', 'automatik', 'konsole', 'system'], true) ? $ausloeser : null,
                'von'        => $von !== '-' && preg_match(self::VON, $von) === 1 ? $von : null,
                'zustand'    => $zustand,
                'ergebnis'   => self::code($f['ergebnis'] ?? null),
                'grund'      => self::code($f['grund'] ?? null),
                'schritt'    => $schritt['nr'],
                'gesamt'     => $schritt['gesamt'],
                'schritt_name' => $schritt['name'],
                'beginn'     => self::zahl($f['beginn'] ?? null),
                'puls'       => self::zahl($f['puls'] ?? null),
                'ende'       => self::zahl($f['ende'] ?? null),
                'alt'        => self::sha($f['alt'] ?? null),
                'neu'        => self::sha($f['neu'] ?? null),
                'ziel'       => self::sha($f['ziel'] ?? null),
                'sicherung'  => preg_match('/^vor-update-[0-9-]+\.tar\.gz$/', $sicherung) === 1 ? $sicherung : null,
                'pruefung'   => in_array($pruefung, ['ok', 'warnung', 'fehler'], true) ? $pruefung : null,
                'warnungen'  => $warnungen,
                'invocation' => preg_match(self::KENNUNG, (string) ($f['invocation'] ?? '')) === 1 ? (string) $f['invocation'] : null,
                'protokoll'  => preg_match('/^[0-9]{8}-[0-9]{6}-[A-Za-z0-9-]+\.log$/', $protokoll) === 1 ? $protokoll : null,
            ];
        });
    }

    /**
     * Stand aus git, wie ihn der Helfer zuletzt gesehen hat (ist/stand).
     *
     * @return array<string,mixed>|null
     */
    public static function stand(): ?array
    {
        return self::gemerkt('stand', static function (): ?array {
            $z = self::zeilen(self::ist('stand'), self::KOPF_STAND, 262144);
            if ($z === null) {
                return null;
            }
            $f = self::felder($z);
            $erzeugt = self::zahl($f['erzeugt'] ?? null);
            if ($erzeugt === null) {
                return null;
            }
            $paar = static function (?string $wert): ?array {
                return $wert !== null && preg_match('/^([0-9a-f]{40}) ([0-9]{1,12})$/', $wert, $m) === 1
                    ? ['commit' => $m[1], 'zeit' => (int) $m[2]] : null;
            };
            $kandidaten = $commits = $hinweise = $gescheitert = [];
            foreach ($z as [$k, $v]) {
                if ($k === 'ziel_kandidat' && count($kandidaten) < 200 && ($p = $paar($v)) !== null) {
                    $kandidaten[] = $p;
                } elseif ($k === 'commit' && count($commits) < 100
                    && preg_match('/^([0-9a-f]{40}) ([0-9]{1,12})(?: (.*))?$/su', $v, $m) === 1) {
                    $commits[] = ['commit' => $m[1], 'zeit' => (int) $m[2], 'betreff' => mb_substr(trim($m[3] ?? ''), 0, 200)];
                } elseif ($k === 'hinweis' && count($hinweise) < 50 && preg_match('/^([0-9a-f]{7}) (.+)$/su', $v, $m) === 1) {
                    $hinweise[] = ['kurz' => $m[1], 'text' => mb_substr(trim($m[2]), 0, 200)];
                } elseif ($k === 'gescheitert' && count($gescheitert) < 50 && self::sha($v) !== null) {
                    $gescheitert[] = $v;
                }
            }
            $zweig = $f['zweig'] ?? '-';
            $quelle = $f['quelle'] ?? '-';
            $abruf = $f['abruf'] ?? 'nie';
            $betreff = trim($f['installiert_betreff'] ?? '');
            return [
                'erzeugt'         => $erzeugt,
                'abruf'           => in_array($abruf, ['ok', 'abruf', 'kein_zugang', 'nie'], true) ? $abruf : 'nie',
                'abruf_zeit'      => self::zahl($f['abruf_zeit'] ?? null),
                'zweig'           => $zweig !== '-' && preg_match('#^[A-Za-z0-9._/+-]{1,200}$#', $zweig) === 1 ? $zweig : null,
                // Nur eine geprüfte GitHub-Adresse ohne Zugangsdaten – daraus werden Links.
                'quelle'          => preg_match('#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $quelle) === 1 ? $quelle : null,
                'installiert'     => $paar($f['installiert'] ?? null),
                'installiert_betreff' => $betreff === '-' ? '' : mb_substr($betreff, 0, 200),
                'verfuegbar'      => $paar($f['verfuegbar'] ?? null),
                'neu'             => self::zahl($f['neu'] ?? null) ?? 0,
                'linear'          => ($f['linear'] ?? 'nein') === 'ja',
                'lokal_geaendert' => ($f['lokal_geaendert'] ?? 'nein') === 'ja',
                'migrationen'     => ($f['migrationen'] ?? 'nein') === 'ja',
                'einrichtung'     => ($f['einrichtung'] ?? 'nein') === 'ja',
                'flows'           => ($f['flows'] ?? 'nein') === 'ja',
                'kandidaten'      => $kandidaten,
                'commits'         => $commits,
                'weitere'         => self::zahl($f['weitere'] ?? null) ?? 0,
                'hinweise'        => $hinweise,
                'gescheitert'     => array_values(array_unique($gescheitert)),
            ];
        });
    }

    /**
     * Die letzten Läufe (ist/verlauf), neueste zuerst – ohne reine Suchen.
     *
     * @return list<array<string,mixed>>
     */
    public static function verlauf(int $max = 30): array
    {
        $alle = self::gemerkt('verlauf', static function (): array {
            $z = self::zeilen(self::ist('verlauf'), self::KOPF_VERLAUF, 65536);
            if ($z === null) {
                return [];
            }
            $out = [];
            foreach ($z as [$k, $v]) {
                if ($k !== 'eintrag') {
                    continue;
                }
                $t = explode(' ', $v);
                if (count($t) < 11) {
                    continue;
                }
                [$beginn, $ende, $kennung, $ausloeser, $von, $aktion, $ergebnis, $grund, $alt, $neu, $sicherung] = $t;
                if (self::zahl($beginn) === null || self::zahl($ende) === null || self::code($ergebnis) === null
                    || !in_array($aktion, ['aktualisieren', 'wiederaufnehmen'], true)) {
                    continue;
                }
                $out[] = [
                    'beginn'    => (int) $beginn,
                    'ende'      => (int) $ende,
                    'dauer'     => max(0, (int) $ende - (int) $beginn),
                    'kennung'   => preg_match(self::KENNUNG, $kennung) === 1 ? $kennung : null,
                    'ausloeser' => in_array($ausloeser, ['hand', 'automatik', 'konsole', 'system'], true) ? $ausloeser : 'konsole',
                    'von'       => preg_match(self::VON, $von) === 1 ? $von : '',
                    'aktion'    => $aktion,
                    'ergebnis'  => $ergebnis,
                    'grund'     => self::code($grund),
                    'alt'       => self::sha($alt),
                    'neu'       => self::sha($neu),
                    'sicherung' => preg_match('/^vor-update-[0-9-]+\.tar\.gz$/', $sicherung) === 1 ? $sicherung : null,
                ];
            }
            return array_reverse($out);
        });
        return array_slice($alle, 0, max(0, $max));
    }

    /**
     * Das bereinigte Protokoll des letzten Laufs (ist/protokoll).
     *
     * @return array{kennung:?string, text:string, stand:string}|null
     */
    public static function protokollDatei(int $maxBytes = 65536): ?array
    {
        $pfad = self::ist('protokoll');
        clearstatcache(true, $pfad);
        if (!@is_file($pfad)) {
            return null;
        }
        $groesse = (int) @filesize($pfad);
        $inhalt = @file_get_contents($pfad, false, null, 0, 66000);
        if (!is_string($inhalt)) {
            return null;
        }
        $teile = explode("\n", $inhalt, 3);
        if ($teile[0] !== self::KOPF_PROTOKOLL) {
            return null;
        }
        $kennung = preg_match('/^kennung ([0-9a-f]{32})$/', $teile[1] ?? '', $m) === 1 ? $m[1] : null;
        $text = substr(ltrim($teile[2] ?? '', "\n"), -$maxBytes);
        // Der Helfer schwärzt schon – doppelt hält besser: keine Zugangsdaten
        // in Adressen, keine Token-Muster, keine Steuerzeichen außer Zeilenende.
        $text = (string) preg_replace(['#(://)[^/@\s]+@#', '/(gh[pousr]_|github_pat_)[A-Za-z0-9_]{10,}/'], ['$1***@', '$1***'], mb_scrub($text, 'UTF-8'));
        $text = (string) preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/u', '', str_replace("\t", '    ', $text));
        return ['kennung' => $kennung, 'text' => $text, 'stand' => @filemtime($pfad) . '-' . $groesse];
    }

    public static function protokoll(int $maxBytes = 65536): string
    {
        return self::protokollDatei($maxBytes)['text'] ?? '';
    }

    /**
     * Ein Auftrag, den der Helfer noch nicht übernommen hat (soll/auftrag).
     * Auch Unlesbares zählt – der Helfer holt es ab und lehnt es ab.
     *
     * @return array{kennung:?string, aktion:?string, zeit:?int, alter:int}|null
     */
    public static function auftrag(?int $jetzt = null): ?array
    {
        $jetzt ??= time();
        $pfad = self::verzeichnis() . '/soll/auftrag';
        clearstatcache(true, $pfad);
        $info = @lstat($pfad);
        if ($info === false) {
            return null;
        }
        $kennung = $aktion = null;
        $zeit = null;
        // Nur eine gewöhnliche Datei lesen – eine FIFO hielte die Anfrage auf.
        if (!is_link($pfad) && is_file($pfad)) {
            $inhalt = (string) @file_get_contents($pfad, false, null, 0, 1025);
            $kennung = preg_match('/^kennung ([0-9a-f]{32})$/m', $inhalt, $m) === 1 ? $m[1] : null;
            $aktion = preg_match('/^aktion (pruefen|aktualisieren)$/m', $inhalt, $m) === 1 ? $m[1] : null;
            $zeit = preg_match('/^zeit ([0-9]{10})$/m', $inhalt, $m) === 1 ? (int) $m[1] : null;
        }
        return ['kennung' => $kennung, 'aktion' => $aktion, 'zeit' => $zeit, 'alter' => max(0, $jetzt - (int) ($info['mtime'] ?? $jetzt))];
    }

    /**
     * Der zuletzt gestellte Auftrag (storage/update/.angefordert, nur von
     * anfordern() geschrieben) – solange er höchstens HAENGT alt ist.
     *
     * @return array{kennung:string, zeit:int}|null
     */
    private static function angefordert(int $jetzt): ?array
    {
        $pfad = self::verzeichnis() . '/.angefordert';
        clearstatcache(true, $pfad);
        if (is_link($pfad) || !@is_file($pfad)) {
            return null;
        }
        $inhalt = (string) @file_get_contents($pfad, false, null, 0, 128);
        if (preg_match('/^([0-9a-f]{32}) ([0-9]{1,12})\n?$/', $inhalt, $m) !== 1) {
            return null;
        }
        $alter = $jetzt - (int) $m[2];
        return $alter >= -self::HAENGT && $alter <= self::HAENGT ? ['kennung' => $m[1], 'zeit' => (int) $m[2]] : null;
    }

    // ================================================================ Zustand

    /**
     * Der Zustand der Update-Funktion, aus den Dateien abgeleitet.
     *
     * zustand: demo | nicht_eingerichtet | bereit | angefordert | laeuft | fertig | fehler
     *
     * @return array<string,mixed>
     */
    public static function zustand(?int $jetzt = null): array
    {
        $jetzt ??= time();
        $z = [
            'zustand' => 'bereit', 'haengt' => false, 'keine_rueckmeldung' => false, 'verschwunden' => false,
            'still' => false, 'wartung' => false, 'abgeschaltet' => false, 'automatik_root' => false, 'offen' => 'nein',
            'helfer' => null, 'lauf' => null, 'stand' => null, 'auftrag' => null, 'helfer_alter' => null, 'puls_alter' => null,
        ];
        if (Config::isDemo()) {
            $z['zustand'] = 'demo';
            return $z;
        }
        $helfer = self::helfer();
        if ($helfer === null) {
            $z['zustand'] = 'nicht_eingerichtet';
            return $z;
        }
        $lauf = self::lauf();
        $auftrag = self::auftrag($jetzt);
        $angefordert = self::angefordert($jetzt);
        $z['helfer'] = $helfer;
        $z['lauf'] = $lauf;
        $z['stand'] = self::stand();
        $z['auftrag'] = $auftrag;
        $z['helfer_alter'] = max(0, $jetzt - $helfer['zeit']);
        $z['abgeschaltet'] = !$helfer['oberflaeche'];
        $z['automatik_root'] = !$helfer['automatik'];
        $z['offen'] = $helfer['offen'];
        $z['wartung'] = self::wartungAktiv($jetzt);

        $pulsAlter = $lauf !== null ? max(0, $jetzt - (int) ($lauf['puls'] ?? $lauf['beginn'] ?? 0)) : null;
        $z['puls_alter'] = $pulsAlter;
        $pulst = $lauf !== null && $lauf['zustand'] === 'laeuft' && $pulsAlter <= self::PULS_GRENZE;
        // Das Lebenszeichen schreibt der Helfer zu Beginn und am Ende eines
        // Laufs – ein Update über 45 min pulst dazwischen nur im Lauf.
        $z['still'] = $z['helfer_alter'] > self::STILL && !$pulst;
        if ($lauf !== null && $lauf['zustand'] === 'laeuft' && $pulsAlter <= self::VERSCHWUNDEN) {
            $z['zustand'] = 'laeuft';
            $z['keine_rueckmeldung'] = $pulsAlter > self::PULS_GRENZE;
        } elseif ($auftrag !== null && ($lauf === null || $auftrag['kennung'] === null || $lauf['kennung'] !== $auftrag['kennung'])) {
            $z['zustand'] = 'angefordert';
            $z['haengt'] = $auftrag['alter'] > self::HAENGT;
        } elseif ($angefordert !== null && ($lauf === null
            || ($lauf['kennung'] !== $angefordert['kennung'] && (int) ($lauf['beginn'] ?? 0) <= $angefordert['zeit']))) {
            // Der Helfer hat den Auftrag schon weggeräumt, den Lauf dazu aber
            // noch nicht geschrieben (er prüft ihn gerade): ist/lauf zeigt
            // noch den vorigen Lauf – der ist nicht das Ergebnis.
            $z['zustand'] = 'angefordert';
        } elseif ($lauf !== null && $lauf['zustand'] === 'laeuft') {
            // Seit einer halben Stunde kein Puls: der Helfer ist weg. Der
            // Zeitgeber rollt beim nächsten Wecken zurück.
            $z['zustand'] = 'fehler';
            $z['verschwunden'] = true;
        } elseif ($lauf !== null) {
            $z['zustand'] = $lauf['zustand'];
        }
        return $z;
    }

    /**
     * Läuft das als root, während storage/update (bzw. storage/) jemand
     * anderem gehört? Dann schreibt PHP weder Aufträge noch ins Audit –
     * Dateien und SQLite-Nebendateien (-wal, -shm) gehörten sonst root.
     *
     * lstat statt fileowner: ein Verweis zählt immer als fremd. Ersetzte
     * www-data storage/update durch einen Verweis auf einen Ordner von root
     * (etwa /tmp), gölte root sonst nicht mehr als fremd. Ebenso der
     * Elternordner (storage/).
     */
    public static function fremderRoot(): bool
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return false;
        }
        $dir = self::verzeichnis();
        clearstatcache();
        foreach ([$dir, dirname($dir)] as $i => $pfad) {
            $info = @lstat($pfad);
            if ($info === false && $i === 0) {
                continue;   // storage/update fehlt: dann zählt storage/
            }
            if ($info === false || is_link($pfad) || (int) $info['uid'] !== 0) {
                return true;
            }
        }
        return false;
    }

    /** Läuft ein Update (oder wartet ein Auftrag) – dann fragt die Seite ab. */
    public static function aktiv(array $zustand): bool
    {
        return in_array($zustand['zustand'], ['angefordert', 'laeuft'], true);
    }

    /**
     * Der installierte Stand – ohne git-Prozess.
     *
     * .git/HEAD ist maßgeblich (nach einem Update von der Konsole oder kurz
     * vor dem neuen ist/stand ist die Datei des Helfers noch alt); Zeit und
     * Betreff kommen aus ist/stand, soweit es den Commit kennt.
     *
     * @return array{commit:?string, kurz:?string, zeit:?int, betreff:string, zweig:?string, quelle:?string}
     */
    public static function installiert(): array
    {
        return self::gemerkt('installiert', static function (): array {
            $stand = self::stand();
            $git = self::ausGit();
            $commit = $git['commit'] ?? $stand['installiert']['commit'] ?? null;
            $out = ['commit' => $commit, 'kurz' => $commit !== null ? substr($commit, 0, 7) : null, 'zeit' => null, 'betreff' => '',
                // Ein abgetrennter HEAD hat keinen Zweig – auch wenn ist/stand noch einen nennt.
                'zweig' => $git !== null && $git['commit'] !== null ? $git['zweig'] : ($stand['zweig'] ?? null),
                'quelle' => $git !== null && $git['commit'] !== null ? 'git' : ($commit !== null ? 'stand' : null)];
            if ($commit === null || $stand === null) {
                return $out;
            }
            if (($stand['installiert']['commit'] ?? null) === $commit) {
                $out['zeit'] = $stand['installiert']['zeit'];
                $out['betreff'] = $stand['installiert_betreff'];
                return $out;
            }
            foreach ($stand['commits'] as $c) {
                if ($c['commit'] === $commit) {
                    $out['zeit'] = $c['zeit'];
                    $out['betreff'] = $c['betreff'];
                    return $out;
                }
            }
            foreach ($stand['kandidaten'] as $c) {
                if ($c['commit'] === $commit) {
                    $out['zeit'] = $c['zeit'];
                    return $out;
                }
            }
            return $out;
        });
    }

    /**
     * HEAD aus .git lesen – Dateien, kein Prozess: HEAD → refs/heads/<zweig>
     * → packed-refs. Auch eine Arbeitskopie mit „.git“ als Datei (worktree).
     *
     * @return array{commit:?string, zweig:?string}|null
     */
    private static function ausGit(): ?array
    {
        $git = (string) Config::get('update.git_verzeichnis', APP_ROOT . '/.git');
        $klein = static fn (string $pfad, int $max = 1024): string => @is_file($pfad) ? trim((string) @file_get_contents($pfad, false, null, 0, $max)) : '';
        if (@is_file($git)) {
            if (preg_match('#^gitdir: (/[^\n]+)$#', $klein($git), $m) !== 1) {
                return null;
            }
            $git = $m[1];
        }
        if (!@is_dir($git)) {
            return null;
        }
        $gemeinsam = $git;
        if (($c = $klein($git . '/commondir')) !== '' && !str_contains($c, "\n")) {
            $gemeinsam = str_starts_with($c, '/') ? $c : $git . '/' . $c;
        }
        $head = $klein($git . '/HEAD');
        if (preg_match(self::SHA, $head) === 1) {
            return ['commit' => $head, 'zweig' => null];
        }
        // Nur ein Zweig unter refs/heads – kein Weg aus .git hinaus.
        if (preg_match('#^ref: (refs/heads/[A-Za-z0-9._/-]{1,200})$#', $head, $m) !== 1
            || str_contains($m[1], '..') || str_contains($m[1], '//') || str_ends_with($m[1], '/')) {
            return ['commit' => null, 'zweig' => null];
        }
        $ref = $m[1];
        $zweig = substr($ref, strlen('refs/heads/'));
        foreach (array_unique([$git, $gemeinsam]) as $dir) {
            $wert = $klein($dir . '/' . $ref, 128);
            if (preg_match(self::SHA, $wert) === 1) {
                return ['commit' => $wert, 'zweig' => $zweig];
            }
        }
        $gepackt = $klein($gemeinsam . '/packed-refs', 4 * 1024 * 1024);
        foreach (explode("\n", $gepackt) as $zeile) {
            if (preg_match('/^([0-9a-f]{40}) (\S+)$/', trim($zeile), $p) === 1 && $p[2] === $ref) {
                return ['commit' => $p[1], 'zweig' => $zweig];
            }
        }
        return ['commit' => null, 'zweig' => $zweig];
    }

    /** Läßt sich der Stand einspielen (ohne Blick auf laufende Aufträge)? */
    private static function einspielbar(?array $stand, bool $abgeschaltet): bool
    {
        return $stand !== null && !$abgeschaltet && $stand['neu'] > 0 && $stand['linear'] && !$stand['lokal_geaendert']
            && $stand['zweig'] !== null && $stand['verfuegbar'] !== null;
    }

    /**
     * Warum eine Aktion gerade nicht geht – oder null.
     */
    public static function sperrgrund(string $aktion, ?string $ziel = null, ?int $jetzt = null): ?string
    {
        $jetzt ??= time();
        $z = self::zustand($jetzt);
        $grund = match (true) {
            $z['zustand'] === 'demo'               => 'Im Demo-Modus werden keine Updates eingespielt.',
            $z['zustand'] === 'nicht_eingerichtet' => 'Die Update-Funktion ist auf diesem System noch nicht eingerichtet – einmal auf der Konsole umsteigen (Anleitung unten).',
            $z['abgeschaltet']                     => 'Updates aus der Oberfläche sind auf diesem System abgeschaltet (/etc/powermanagement/update.conf).',
            $z['still']                            => 'Der Update-Dienst meldet sich nicht – ein Auftrag bliebe liegen.',
            $z['offen'] === 'aufgegeben'           => 'Ein unterbrochenes Update ließ sich nicht zurückrollen – erst auf der Konsole nachsehen.',
            $z['zustand'] === 'laeuft'             => 'Gerade läuft ' . (($z['lauf']['aktion'] ?? '') === 'pruefen' ? 'eine Suche.' : 'ein Update.'),
            $z['zustand'] === 'angefordert'        => 'Ein Auftrag wartet schon auf den Update-Dienst.',
            $z['wartung']                          => 'Gerade läuft ein Update.',
            default                                => null,
        };
        if ($grund !== null) {
            return $grund;
        }
        if ($aktion === 'pruefen') {
            $letzter = @filemtime(self::verzeichnis() . '/.letzter');
            if ($letzter !== false && $jetzt - $letzter >= 0 && $jetzt - $letzter < self::SUCHEN_ABSTAND) {
                return 'Eben erst gesucht – bitte einen Moment warten.';
            }
            return null;
        }
        if ($aktion !== 'aktualisieren') {
            return 'Unbekannte Aktion.';
        }
        $stand = $z['stand'];
        $installiert = self::installiert()['commit'];
        return match (true) {
            $z['offen'] === 'ja'                    => 'Ein unterbrochenes Update wird gerade zurückgerollt – danach erneut versuchen.',
            $stand === null                         => 'Noch kein Stand bekannt – bitte erst suchen.',
            $stand['zweig'] === null                => 'Es ist kein Zweig ausgecheckt – nur über die Konsole.',
            $stand['lokal_geaendert']               => 'Im Code gibt es lokale Änderungen – nur über die Konsole (update-debian.sh --erzwingen).',
            !$stand['linear']                       => 'Der Verlauf ist nicht linear – nur über die Konsole.',
            $stand['verfuegbar'] === null           => 'Kein verfügbarer Stand bekannt – bitte erst suchen.',
            $ziel !== null && $ziel === $installiert => 'Dieser Stand ist schon installiert.',
            $ziel === null && $stand['neu'] === 0   => 'PowerManagement ist auf dem neuesten Stand.',
            $ziel !== null && !self::zielBekannt($stand, $ziel) => 'Dieser Stand ist nicht (mehr) einspielbar – bitte neu suchen.',
            default                                 => null,
        };
    }

    /** Liegt das Ziel unter den einspielbaren Ständen (verfügbar oder Kandidat)? */
    private static function zielBekannt(array $stand, string $ziel): bool
    {
        if (($stand['verfuegbar']['commit'] ?? null) === $ziel) {
            return true;
        }
        foreach ($stand['kandidaten'] as $k) {
            if ($k['commit'] === $ziel) {
                return true;
            }
        }
        return false;
    }

    // ================================================================ Auftrag

    /**
     * Der Auftrag als Text – genau das Format, das der Helfer annimmt.
     *
     * Wirft \InvalidArgumentException mit dem Code, mit dem der Helfer den
     * Auftrag ablehnen würde (wert, fehlt, verboten) – nie ein halber Auftrag.
     */
    public static function auftragText(string $kennung, string $aktion, string $ausloeser, string $von, int $zeit, ?string $ziel): string
    {
        $fehler = match (true) {
            preg_match(self::KENNUNG, $kennung) !== 1,
            !in_array($aktion, self::AKTIONEN, true),
            !in_array($ausloeser, self::AUSLOESER, true),
            preg_match(self::VON, $von) !== 1,
            $zeit < 1_000_000_000 || $zeit > 9_999_999_999,
            $ziel !== null && preg_match(self::SHA, $ziel) !== 1 => 'wert',
            $aktion === 'pruefen' && $ziel !== null              => 'verboten',
            $aktion === 'aktualisieren' && $ziel === null        => 'fehlt',
            default                                              => null,
        };
        if ($fehler !== null) {
            throw new \InvalidArgumentException($fehler);
        }
        return self::KOPF_AUFTRAG . "\n"
            . 'kennung ' . $kennung . "\n"
            . 'aktion ' . $aktion . "\n"
            . 'ausloeser ' . $ausloeser . "\n"
            . 'von ' . $von . "\n"
            . 'zeit ' . $zeit . "\n"
            . ($ziel !== null ? 'ziel ' . $ziel . "\n" : '');
    }

    /** Nur Zeichen, die der Helfer in „von“ annimmt; höchstens 64, leer → unbekannt. */
    public static function vonBereinigen(string $von): string
    {
        $von = substr((string) preg_replace('/[^A-Za-z0-9._@-]/', '', $von), 0, 64);
        return $von !== '' ? $von : 'unbekannt';
    }

    /**
     * Einen Auftrag an den Update-Dienst stellen.
     *
     * Kein Prozess, kein sudo: eine Datei nach soll/auftrag (Temp-Datei
     * außerhalb des beobachteten Ordners, 0640, umbenennen). Den Audit-Eintrag
     * schreibt der Aufrufer.
     *
     * @return array{ok:bool, message:string, kennung?:string, bestaetigen?:bool, hindernisse?:list<array{art:string,text:string}>}
     */
    public static function anfordern(string $aktion, ?string $ziel, string $ausloeser, string $von, bool $trotzBetrieb = false): array
    {
        if (!in_array($aktion, self::AKTIONEN, true) || !in_array($ausloeser, self::AUSLOESER, true)) {
            return ['ok' => false, 'message' => 'Unbekannte Aktion oder unbekannter Auslöser.'];
        }
        if ($aktion === 'pruefen') {
            $ziel = null;
        } elseif ($ziel === null || preg_match(self::SHA, $ziel) !== 1) {
            return ['ok' => false, 'message' => 'Welcher Stand eingespielt werden soll, fehlt.'];
        }
        self::vergessen();
        if (($grund = self::sperrgrund($aktion, $ziel)) !== null) {
            return ['ok' => false, 'message' => $grund];
        }
        $dir = self::verzeichnis();
        // Gehört storage/update jemand anderem (root ruft bin/power auf), läge
        // der Auftrag mit falschem Besitzer da – und der Helfer liest ihn als
        // App-Benutzer.
        if (function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid()) {
            throw new \RuntimeException('storage/update gehört einem anderen Benutzer – bitte als dessen Besitzer ausführen: runuser -u www-data -- php bin/power …');
        }
        if (!is_dir($dir . '/soll') || is_link($dir . '/soll')) {
            return ['ok' => false, 'message' => 'storage/update/soll fehlt – die Update-Funktion ist nicht vollständig eingerichtet.'];
        }
        $schloss = @fopen($dir . '/.lock', 'c');
        if ($schloss === false || !flock($schloss, LOCK_EX)) {
            return ['ok' => false, 'message' => 'storage/update/.lock lässt sich nicht sperren.'];
        }
        try {
            // Unter der Sperre noch einmal: kein Auftrag, kein Lauf.
            self::vergessen();
            $z = self::zustand();
            if (self::aktiv($z)) {
                return ['ok' => false, 'message' => $z['zustand'] === 'laeuft' ? 'Gerade läuft ein Update.' : 'Ein Auftrag wartet schon auf den Update-Dienst.'];
            }
            $hindernisse = $aktion === 'aktualisieren' ? UpdateAutomatik::hindernisse(time()) : [];
            if ($hindernisse !== [] && !$trotzBetrieb) {
                return [
                    'ok' => false, 'bestaetigen' => true, 'hindernisse' => $hindernisse,
                    'message' => 'Gerade ist Betrieb: ' . implode(' ', array_column($hindernisse, 'text'))
                        . ' Ein Update unterbricht Messung und Schalten für 1–3 Minuten. Bewusst trotzdem: „Trotzdem jetzt aktualisieren“.',
                ];
            }
            $kennung = bin2hex(random_bytes(16));
            $text = self::auftragText($kennung, $aktion, $ausloeser, self::vonBereinigen($von), time(), $ziel);
            $temp = @tempnam($dir, '.auftrag-');
            if ($temp === false || dirname($temp) !== $dir) {
                if ($temp !== false) {
                    @unlink($temp);
                }
                return ['ok' => false, 'message' => 'In storage/update lässt sich nicht schreiben.'];
            }
            if (@file_put_contents($temp, $text) !== strlen($text) || !@chmod($temp, 0640) || !@rename($temp, $dir . '/soll/auftrag')) {
                @unlink($temp);
                return ['ok' => false, 'message' => 'Der Auftrag ließ sich nicht ablegen.'];
            }
            // Für zustand(): der Helfer räumt soll/auftrag weg, bevor er den
            // Lauf dazu schreibt – bis dahin gilt der Auftrag als angefordert.
            $merk = @tempnam($dir, '.angefordert-');
            if ($merk !== false && dirname($merk) === $dir && @file_put_contents($merk, $kennung . ' ' . time() . "\n") !== false
                && @chmod($merk, 0640) && @rename($merk, $dir . '/.angefordert')) {
                $merk = false;
            }
            if ($merk !== false) {
                @unlink($merk);
            }
            if ($aktion === 'pruefen') {
                @touch($dir . '/.letzter');
            }
            self::vergessen();
            $erg = [
                'ok'      => true,
                'kennung' => $kennung,
                'message' => $aktion === 'pruefen'
                    ? 'Suche angefordert – der Update-Dienst holt gleich die Änderungen.'
                    : 'Update auf ' . substr((string) $ziel, 0, 7) . ' angefordert – der Update-Dienst beginnt gleich.',
            ];
            if ($hindernisse !== []) {
                $erg['hindernisse'] = $hindernisse;
            }
            return $erg;
        } finally {
            flock($schloss, LOCK_UN);
            fclose($schloss);
        }
    }

    // ================================================================ Schreibsperre

    /**
     * Läuft gerade ein Update (Sicherung bis Prüfung)? Dann nimmt die
     * Oberfläche keine Änderungen an – ein Zurückrollen spielte die
     * Datenbank von vorher zurück und verlöre sie.
     *
     * Wirksam, solange ist/wartung steht und jünger als 60 min ist – oder
     * der Lauf noch pulst (apt und npm können länger dauern).
     */
    public static function wartungAktiv(?int $jetzt = null): bool
    {
        if (Config::isDemo()) {
            return false;
        }
        $pfad = self::ist('wartung');
        clearstatcache(true, $pfad);
        if (!@is_file($pfad)) {
            return false;
        }
        $jetzt ??= time();
        $inhalt = (string) @file_get_contents($pfad, false, null, 0, 512);
        $seit = preg_match('/^seit ([0-9]{1,12})$/m', $inhalt, $m) === 1 ? (int) $m[1] : (int) @filemtime($pfad);
        if ($jetzt - $seit < self::WARTUNG_MAX) {
            return true;
        }
        $lauf = self::lauf();
        return $lauf !== null && $lauf['zustand'] === 'laeuft' && $jetzt - (int) ($lauf['puls'] ?? 0) <= self::WARTUNG_PULS;
    }

    /**
     * In public/index.php vor dispatch(): während der Schreibsperre keine
     * POSTs (außer An- und Abmelden). Wer JSON will (Accept), bekommt 503
     * zum Wiederholen; Formulare eine Meldung und zurück. Kosten: eine
     * geteilte Dateisperre (anfrageAnmelden()) und ein is_file je POST.
     *
     * Was der Router ohnehin abweist, beantwortet weiter er – geschrieben
     * wird so oder so nichts: Was der Kundenmodus sperrt (423 bzw. zur
     * Kundenseite; sonst bekäme ein anderer Reiter während des Updates 503
     * statt 423 und zeigte weiter, was er geladen hat), und für
     * Kundenzugänge alles außerhalb des Kundenbereichs (das Tor: 403 bzw.
     * zum Kundenbereich – ein Kunde bekäme sonst zu einer Adresse des
     * Personals eine Meldung über ein Update). Ebenso ein Körper über
     * post_max_size (ein zu großer Grundriss): Die Größe ist die Meldung,
     * die weiterhilft, und ohne Körper gibt es nichts zu schreiben.
     *
     * Im Kundenbereich (Kundenzugänge, Einrichtungslink) steht die Meldung
     * im Ton des Kundenbereichs (GESPERRT_KUNDE) in dessen Layout – die
     * Seiten dort zeigen Meldungen wie jede andere. Ein Kennwort, das so
     * nicht gespeichert wurde, gibt man noch einmal ein; aufgehoben wird es
     * nirgends.
     *
     * $router: wohin „zurück“ führt (zurueckNachSperre()). Ohne ihn wie
     * früher dorthin, wo die Anfrage herkam.
     */
    public static function wartungsSperre(?Router $router = null): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return;
        }
        // Größer als post_max_size: PHP hat den Körper samt Token und Datei
        // schon verworfen – schreiben kann diese Anfrage nichts mehr. Dann
        // weder anmelden (das Update wartete umsonst auf sie) noch „in etwa
        // einer Minute wieder möglich“ melden: Der zweite Versuch schickte
        // dieselben 100 MB und scheiterte an der Größe. Die nennt der Router
        // (nach Anmeldung und Recht, wie ohne Update).
        if ((new Request())->koerperVerworfen() !== null) {
            return;
        }
        // Erst anmelden, dann fragen: Wer vor der Sperre hereinkam, auf den
        // wartet das Update, bevor es sichert – auch wenn er lange auf eine
        // andere Instanz, git oder ein Gerät wartet und erst danach schreibt.
        self::anfrageAnmelden();
        if (!self::wartungAktiv()) {
            return;   // angemeldet bis zum Ende der Anfrage
        }
        // Abgewiesen (oder An- und Abmelden während der Sperre): darauf wartet das Update nicht.
        self::anfrageAbmelden();
        $pfad = (new Request())->path();
        if (in_array($pfad, ['/login', '/logout'], true)) {
            return;
        }
        $kundenzugang = Auth::check() && Kundenbereich::istKundenzugang();
        if ($kundenzugang ? !Kundenbereich::erlaubt($pfad) : Kundenmodus::sperrt('POST', $pfad) !== null) {
            return;
        }
        $text = $kundenzugang || str_starts_with($pfad, Kundenbereich::PFAD . '/') ? self::GESPERRT_KUNDE : self::GESPERRT;
        header('Cache-Control: no-store');
        // Nur der Accept-Kopf, wie im Router: Formulare mit data-laeuft
        // (fetch ohne Accept) sollen Meldung und Weiterleitung bekommen.
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            header('Retry-After: 30');
            Response::json(['ok' => false, 'wartung' => true, 'message' => $text], 503);
            exit;
        }
        Flash::warning($text);
        Response::redirect($router === null ? Response::zurueckZiel($kundenzugang ? Kundenbereich::PFAD : '/')
            : self::zurueckNachSperre($router, $pfad, $kundenzugang));
        exit;
    }

    /**
     * Wohin nach einer abgewiesenen Änderung: auf die Seite, von der sie
     * kam (Referer) – wenn es sie als Seite gibt. Eine Antwort auf ein
     * Formular ist oft keine (Treffer der Eventworx-Suche, Dateien am
     * Vorgang, eine Rückfrage, eine Kennwort-Anzeige); deren GET führte auf
     * 404. Dann die nächste Seite darüber: /kunden/7/eventworx/suche →
     * /kunden/7. Ohne Referer – der Kundenbereich schickt keinen
     * (Referrer-Policy: no-referrer) – vom Ziel des Formulars aus: das
     * Kennwort im Konto → /kundenbereich/konto, ein Einrichtungslink → derselbe
     * Link (er bleibt gültig, eingelöst ist nichts). Ein Kundenzugang nur in
     * den Kundenbereich, sonst dorthin bzw. zur Übersicht.
     */
    private static function zurueckNachSperre(Router $router, string $pfad, bool $kundenzugang): string
    {
        $kandidaten = array_values(array_filter([Response::zurueckZiel(''), $pfad], static fn (string $z): bool => $z !== ''));
        foreach ($kandidaten as $ziel) {
            [$weg, $abfrage] = array_pad(explode('?', $ziel, 2), 2, null);
            $abfrage = $abfrage !== null ? '?' . $abfrage : '';
            while (true) {
                $weg = $weg === '' ? '/' : $weg;
                $sicher = Response::sichererPfad($weg . $abfrage);
                if ($sicher !== null && $router->hatSeite($weg) && (!$kundenzugang || Kundenbereich::erlaubt($weg))) {
                    return $sicher;
                }
                if ($weg === '/') {
                    break;
                }
                // Eine Seite darüber – ohne die Abfrage der unteren.
                $weg = substr($weg, 0, (int) strrpos($weg, '/'));
                $abfrage = '';
            }
        }
        return $kundenzugang ? Kundenbereich::PFAD : '/';
    }

    /**
     * Eine Anfrage, die schreiben kann (POST der Oberfläche, schreibende
     * Methoden der Schnittstelle), meldet sich an, BEVOR sie nach der
     * Schreibsperre fragt: storage/update/.anfragen geteilt gesperrt, bis die
     * Anfrage endet – PHP gibt die Sperre am Ende der Anfrage frei, ein
     * abgestürzter Prozess mit ihm. update-debian.sh setzt erst die
     * Schreibsperre und wartet dann exklusiv auf dieselbe Datei
     * (wartung_an, höchstens ANFRAGEN_WARTEN Sekunden): Was vorher
     * hereinkam, schreibt vor der Sicherung zu Ende, und was danach kommt,
     * sieht die Sperre. Ein fester Abstand reichte nicht – eine Übernahme
     * von einer anderen Instanz wartet Minuten, bevor sie schreibt.
     *
     * Nie wartend (LOCK_NB): Hält das Update die Datei gerade exklusiv,
     * steht die Schreibsperre schon. Geht die Datei nicht auf (storage/update
     * fehlt, Demo), bleibt es bei der Prüfung an der Tür. Mit close-on-exec
     * („e“): git oder pdftoppm, die die Anfrage startet, erben die Sperre
     * nicht – liefe einer weiter, hielte er sie fest.
     */
    public static function anfrageAnmelden(): void
    {
        if (self::$anfrage !== null || Config::isDemo()) {
            return;
        }
        $h = @fopen(self::verzeichnis() . '/' . self::ANFRAGEN, 'ce');
        if ($h === false) {
            return;
        }
        if (!@flock($h, LOCK_SH | LOCK_NB)) {
            fclose($h);
            return;
        }
        self::$anfrage = $h;
    }

    /** Die Anmeldung vor dem Ende der Anfrage zurückgeben – wer abgewiesen wird, schreibt nichts mehr. */
    public static function anfrageAbmelden(): void
    {
        if (self::$anfrage !== null) {
            @flock(self::$anfrage, LOCK_UN);
            @fclose(self::$anfrage);
            self::$anfrage = null;
        }
    }

    // ================================================================ Anzeige

    private static function iso(?int $ts): ?string
    {
        return $ts === null ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * Status für update.js (GET /system/update/stand) – stabil, nur additiv.
     * Keine Pfade aus /etc, keine Tokens. Mit $protokollStand (vom letzten
     * Abruf) entfallen die Zeilen, wenn sich nichts geändert hat.
     *
     * @return array<string,mixed>
     */
    public static function statusJson(?int $jetzt = null, ?string $protokollStand = null): array
    {
        $jetzt ??= time();
        $z = self::zustand($jetzt);
        $lauf = $z['lauf'];
        $out = [
            'format'             => 1,
            'zustand'            => $z['zustand'],
            'aktiv'              => self::aktiv($z),
            'haengt'             => $z['haengt'],
            'keine_rueckmeldung' => $z['keine_rueckmeldung'],
            'verschwunden'       => $z['verschwunden'],
            'still'              => $z['still'],
            'wartung'            => $z['wartung'],
            'abgeschaltet'       => $z['abgeschaltet'],
            'offen'              => $z['offen'],
            'lauf'               => null,
            'installiert'        => in_array($z['zustand'], ['demo', 'nicht_eingerichtet'], true) ? null : self::installiert()['commit'],
            'protokoll'          => null,
        ];
        if ($lauf !== null) {
            $ende = $lauf['zustand'] === 'laeuft' ? null : $lauf['ende'];
            $out['lauf'] = [
                'kennung'       => $lauf['kennung'],
                'aktion'        => $lauf['aktion'],
                'ausloeser'     => $lauf['ausloeser'],
                'von'           => $lauf['von'],
                'zustand'       => $lauf['zustand'],
                'ergebnis'      => $lauf['ergebnis'],
                'ergebnis_text' => $lauf['ergebnis'] !== null ? self::ergebnisText($lauf['ergebnis']) : '',
                'grund'         => $lauf['grund'],
                'grund_text'    => $lauf['grund'] !== null ? self::grundText($lauf['grund']) : '',
                'schritt'       => $lauf['schritt'],
                'gesamt'        => $lauf['gesamt'],
                'schritt_name'  => $lauf['schritt_name'],
                'beginn'        => self::iso($lauf['beginn']),
                'ende'          => self::iso($ende),
                'dauer'         => $lauf['beginn'] !== null ? max(0, ($ende ?? $jetzt) - $lauf['beginn']) : null,
                'puls_alter'    => $z['puls_alter'],
                'alt'           => $lauf['alt'] !== null ? substr($lauf['alt'], 0, 7) : null,
                'neu'           => $lauf['neu'] !== null ? substr($lauf['neu'], 0, 7) : null,
                'neu_voll'      => $lauf['neu'],
                'ziel'          => $lauf['ziel'] !== null ? substr($lauf['ziel'], 0, 7) : null,
                'pruefung'      => $lauf['pruefung'],
                'warnungen'     => $lauf['warnungen'],
                'warnungen_text' => array_map([self::class, 'warnungText'], $lauf['warnungen']),
            ];
        }
        $p = in_array($z['zustand'], ['demo', 'nicht_eingerichtet'], true) ? null : self::protokollDatei();
        if ($p !== null) {
            $out['protokoll'] = ['kennung' => $p['kennung'], 'stand' => $p['stand']];
            if ($protokollStand !== $p['stand']) {
                $zeilen = explode("\n", rtrim($p['text'], "\n"));
                $out['protokoll']['zeilen'] = implode("\n", array_slice($zeilen, -200));
            }
        }
        return $out;
    }

    /**
     * Abzeichen für Navigation und Kopfleiste – je Anfrage einmal, nie im Demo.
     *
     * @return array{stufe:string, text:string, zahl?:int}|null
     */
    public static function navHinweis(?int $jetzt = null): ?array
    {
        if (Config::isDemo()) {
            return null;
        }
        return self::gemerkt('navHinweis', static function () use ($jetzt): ?array {
            try {
                return self::hinweisBestimmen($jetzt ?? time());
            } catch (\Throwable) {
                return null;
            }
        });
    }

    private static function hinweisBestimmen(int $jetzt): ?array
    {
        $z = self::zustand($jetzt);
        if ($z['zustand'] === 'nicht_eingerichtet') {
            // Nur, wenn die Installation per git läuft – sonst gibt es nichts einzurichten.
            $git = (string) Config::get('update.git_verzeichnis', APP_ROOT . '/.git');
            return file_exists($git) ? ['stufe' => 'warn', 'text' => 'Update-Funktion nicht eingerichtet'] : null;
        }
        if ($z['offen'] === 'aufgegeben') {
            return ['stufe' => 'bad', 'text' => 'Ein unterbrochenes Update ließ sich nicht zurückrollen'];
        }
        // Das letzte echte Update ist gescheitert (7 Tage, ohne späteren Erfolg).
        foreach (self::verlauf(100) as $e) {
            if ($e['ergebnis'] === 'nicht_begonnen') {
                continue;
            }
            if (in_array($e['ergebnis'], ['zurueckgerollt', 'unvollstaendig'], true) && $jetzt - $e['ende'] <= 7 * 86400) {
                return ['stufe' => 'bad', 'text' => 'Das letzte Update wurde ' . ($e['ergebnis'] === 'unvollstaendig' ? 'nicht vollständig ' : '') . 'zurückgerollt'];
            }
            break;
        }
        if ($z['still']) {
            return ['stufe' => 'warn', 'text' => 'Der Update-Dienst meldet sich nicht'];
        }
        if ($z['haengt']) {
            return ['stufe' => 'warn', 'text' => 'Der Update-Auftrag wird nicht übernommen'];
        }
        if (UpdateAutomatik::zeitplanStill($jetzt, $z)) {
            return ['stufe' => 'warn', 'text' => 'Der Update-Zeitplan läuft nicht'];
        }
        if (UpdateAutomatik::langeVerschoben($jetzt)) {
            return ['stufe' => 'warn', 'text' => 'Automatische Updates werden seit über 14 Tagen verschoben'];
        }
        $stand = $z['stand'];
        if (self::einspielbar($stand, $z['abgeschaltet'])) {
            $n = (int) $stand['neu'];
            return ['stufe' => 'primary', 'text' => $n === 1 ? '1 Änderung verfügbar' : $n . ' Änderungen verfügbar', 'zahl' => $n];
        }
        return null;
    }

    /**
     * Eine Zeile für bin/power pruefen. Wirft nie – die Zeile ist nur
     * Auskunft, und bin/power pruefen gehört zur Prüfung nach einem Update.
     */
    public static function kurzStand(?int $jetzt = null): string
    {
        try {
            return self::kurzStandBestimmen($jetzt ?? time());
        } catch (\Throwable $e) {
            return 'unbekannt (' . $e->getMessage() . ')';
        }
    }

    private static function kurzStandBestimmen(int $jetzt): string
    {
        $z = self::zustand($jetzt);
        if ($z['zustand'] === 'demo') {
            return 'Demo-Modus – keine Updates';
        }
        if ($z['zustand'] === 'nicht_eingerichtet') {
            return 'nicht eingerichtet – Umstieg siehe docs/installation-debian-lxc.md (Update)';
        }
        $teile = ['eingerichtet'];
        $stand = $z['stand'];
        if ($z['still']) {
            $teile[] = 'Dienst meldet sich seit ' . self::dauerText($z['helfer_alter']) . ' nicht';
        }
        if ($stand !== null) {
            $teile[] = match (true) {
                in_array($stand['abruf'], ['abruf', 'kein_zugang'], true) => 'Suche fehlgeschlagen (' . $stand['abruf'] . ')',
                $stand['neu'] > 0 => $stand['neu'] . ' Änderung' . ($stand['neu'] === 1 ? '' : 'en') . ' verfügbar',
                default => 'aktuell',
            } . ($stand['abruf_zeit'] !== null ? ' (gesucht ' . date('d.m. H:i', $stand['abruf_zeit']) . ')' : '');
        }
        if (self::aktiv($z)) {
            $teile[] = 'läuft gerade';
        }
        if ($z['offen'] !== 'nein') {
            $teile[] = $z['offen'] === 'aufgegeben' ? 'unterbrochenes Update – Handarbeit nötig' : 'unterbrochenes Update wird zurückgerollt';
        }
        return implode(', ', $teile);
    }

    /** „3:12 min“, „2 Std.“ – Dauer in Sekunden lesbar. */
    public static function dauerText(?int $sekunden): string
    {
        if ($sekunden === null) {
            return '–';
        }
        return match (true) {
            $sekunden < 3600   => sprintf('%d:%02d min', intdiv($sekunden, 60), $sekunden % 60),
            $sekunden < 172800 => (int) round($sekunden / 3600) . ' Std.',
            default            => (int) round($sekunden / 86400) . ' Tagen',
        };
    }

    /** Wie lange das letzte eingespielte Update dauerte (für „dauerte zuletzt …“). */
    public static function dauerZuletzt(): ?int
    {
        foreach (self::verlauf(100) as $e) {
            if (in_array($e['ergebnis'], ['eingespielt', 'eingespielt_mit_warnung'], true)) {
                return $e['dauer'];
            }
        }
        return null;
    }

    // ================================================================ Audit

    /**
     * Jede Zeile aus ist/verlauf einmal als system.update.ergebnis ins Audit
     * (gemerkt in update.vermerkt). Nie während der Schreibsperre – ein
     * Zurückrollen nähme den Eintrag mit –, nie als root (root-eigene
     * -wal/-shm neben der SQLite).
     */
    public static function vermerken(): int
    {
        if (Config::isDemo() || self::wartungAktiv() || self::fremderRoot()) {
            return 0;
        }
        $eintraege = array_reverse(self::verlauf(100));
        if ($eintraege === []) {
            return 0;
        }
        $schloss = @fopen(self::verzeichnis() . '/.lock', 'c');
        if ($schloss === false || !flock($schloss, LOCK_EX)) {
            return 0;
        }
        try {
            Settings::vergessen();
            $vermerkt = json_decode((string) Settings::get(self::VERMERKT, '[]'), true);
            $vermerkt = is_array($vermerkt) ? array_values(array_filter($vermerkt, 'is_string')) : [];
            $neu = 0;
            foreach ($eintraege as $e) {
                $schluessel = $e['beginn'] . '-' . ($e['kennung'] ?? '-');
                if (in_array($schluessel, $vermerkt, true)) {
                    continue;
                }
                Database::insert('audit_log', [
                    'user_id'     => null,
                    'username'    => 'system',
                    'action'      => 'system.update.ergebnis',
                    'target_type' => 'update',
                    'target_id'   => (string) ($e['kennung'] ?? ''),
                    'details'     => json_encode([
                        'ausloeser' => $e['ausloeser'], 'von' => $e['von'], 'aktion' => $e['aktion'], 'ergebnis' => $e['ergebnis'],
                        'grund' => $e['grund'], 'alt7' => $e['alt'] !== null ? substr($e['alt'], 0, 7) : null,
                        'neu7' => $e['neu'] !== null ? substr($e['neu'], 0, 7) : null,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'ip'          => php_sapi_name() === 'cli' ? 'cli' : (new Request())->ip(),
                    'created_at'  => gmdate('c'),
                ]);
                $vermerkt[] = $schluessel;
                $neu++;
            }
            if ($neu > 0) {
                Settings::put(self::VERMERKT, (string) json_encode(array_slice($vermerkt, -100)));
            }
            return $neu;
        } finally {
            flock($schloss, LOCK_UN);
            fclose($schloss);
        }
    }

    // ================================================================ Texte

    public static function ergebnisText(string $ergebnis): string
    {
        return match ($ergebnis) {
            'geprueft'                => 'Gesucht',
            'aktuell'                 => 'Schon aktuell',
            'eingespielt'             => 'Eingespielt',
            'eingespielt_mit_warnung' => 'Eingespielt, mit Hinweisen',
            'nicht_begonnen'          => 'Nicht begonnen – nichts verändert',
            'zurueckgerollt'          => 'Zurückgerollt – der alte Stand läuft',
            'unvollstaendig'          => 'Nicht vollständig zurückgerollt – Handarbeit nötig',
            'abgelehnt'               => 'Abgelehnt',
            'abgebrochen'             => 'Abgebrochen',
            default                   => $ergebnis,
        };
    }

    /** Stufe eines Ergebnisses für Abzeichen: ok | warn | bad | info */
    public static function ergebnisStufe(?string $ergebnis): string
    {
        return match ($ergebnis) {
            'geprueft', 'aktuell', 'eingespielt' => 'ok',
            'eingespielt_mit_warnung', 'nicht_begonnen', 'abgelehnt' => 'warn',
            'zurueckgerollt', 'unvollstaendig', 'abgebrochen' => 'bad',
            default => 'info',
        };
    }

    /** Exit-Code wie update-debian.sh: 0 in Ordnung, 1 nichts verändert, 2 zurückgerollt, 3 Handarbeit. */
    public static function exitCode(?string $ergebnis): int
    {
        return match ($ergebnis) {
            'geprueft', 'aktuell', 'eingespielt', 'eingespielt_mit_warnung' => 0,
            'nicht_begonnen', 'abgelehnt' => 1,
            'zurueckgerollt' => 2,
            default => 3,
        };
    }

    public static function grundText(string $code): string
    {
        return match ($code) {
            // nichts verändert
            'besetzt'         => 'Gerade lief schon ein anderes Update (etwa von der Konsole).',
            'offen'           => 'Ein früheres Update wurde unterbrochen und ist noch nicht zurückgerollt – das holt der Update-Dienst nach.',
            'lokal_geaendert' => 'Im Code gibt es lokale Änderungen – nur über die Konsole (update-debian.sh --erzwingen).',
            'abruf'           => 'Die Änderungen ließen sich nicht holen – GitHub nicht erreichbar?',
            'kein_zugang'     => 'Kein Zugriff auf das Repository – Token in /etc/powermanagement/git.conf abgelaufen oder ohne Leserecht.',
            'kein_zweig'      => 'Es ist kein Zweig ausgecheckt – nur über die Konsole.',
            'nicht_linear'    => 'Der Verlauf ist nicht linear (der installierte Stand ist kein Vorgänger) – nur über die Konsole.',
            'ziel_ungueltig'  => 'Der gewählte Stand liegt nicht vorwärts auf dem Zweig – bitte neu suchen.',
            'platz'           => 'Zu wenig freier Speicherplatz für Sicherung und Update.',
            'sicherung'       => 'Die Sicherung ließ sich nicht anlegen.',
            'abgebrochen'     => 'Abgebrochen (Stopp, Strg+C oder Herunterfahren).',
            // zurückgerollt oder unvollständig
            'checkout'        => 'Der neue Stand ließ sich nicht auschecken.',
            'neue_fassung'    => 'Die neue Fassung des Update-Skripts ist fehlerhaft.',
            'migration'       => 'Eine Migration der Datenbank ist fehlgeschlagen.',
            'pakete'          => 'Fehlende Pakete ließen sich nicht installieren.',
            'einrichtung'     => 'Rechte, Ordner oder Dienste ließen sich nicht abgleichen.',
            'nginx'           => 'Die nginx-Konfiguration ist fehlerhaft.',
            'dienste'         => 'Ein Dienst startete nicht.',
            'pruefung'        => 'Die Prüfung danach schlug fehl (Anmeldeseite, Dienste oder Datenbank).',
            'zeitueberschreitung' => 'Das Update dauerte länger als 60 Minuten.',
            'unterbrochen'    => 'Das Update wurde unterbrochen (Absturz oder Strom weg) und danach zurückgerollt.',
            'intern'          => 'Ein interner Fehler im Update-Skript.',
            // abgelehnt
            'kopf', 'zeichen', 'groesse', 'format', 'schluessel', 'doppelt_schluessel', 'wert', 'fehlt', 'verboten'
                              => 'Der Auftrag hatte nicht die erwartete Form (' . $code . ').',
            'veraltet'        => 'Der Auftrag war zu alt, als der Update-Dienst ihn sah.',
            'zukunft'         => 'Der Auftrag trug eine Zeit in der Zukunft – Uhr des Systems prüfen.',
            'doppelt'         => 'Diesen Auftrag gab es schon einmal.',
            'zu_oft'          => 'Zu kurz nach dem letzten Update.',
            'abgeschaltet'    => 'Auf diesem System abgeschaltet (/etc/powermanagement/update.conf).',
            'gescheitert_vorher' => 'Dieser Stand ist schon einmal gescheitert – automatisch wird er nicht wiederholt.',
            'wiederhergestellt' => 'Eben wurde ein unterbrochenes Update zurückgerollt – bitte erst nachsehen, dann neu starten.',
            'soll_ungueltig'  => 'Der Auftragsordner storage/update/soll ist ungültig.',
            'keine_datei'     => 'Der Auftrag war keine gewöhnliche Datei.',
            // sonst
            'helfer'          => 'Der Update-Dienst endete mitten im Lauf – ein unterbrochenes Update rollt er beim nächsten Start zurück.',
            default           => 'Grund: ' . $code,
        };
    }

    public static function warnungText(string $code): string
    {
        return match ($code) {
            'kacheln'        => 'Fehlende Kacheln der Grundrisse ließen sich nach dem Update nicht anstoßen – die nächtliche Wartung rechnet sie nach.',
            'pruefung'       => 'bin/power pruefen meldet Fehler (InfluxDB, MQTT oder Node-RED) – das Update selbst ist eingespielt.',
            'flows_behalten' => 'Im Editor geänderte Node-RED-Flows bleiben; die neue Fassung liegt daneben (flows.powermanagement-neu.json). Tanksensoren und eigene MQTT-Zugänge brauchen sie – im Editor importieren oder auf der Konsole update-debian.sh --flows-ersetzen.',
            'site_behalten'  => 'Die von Hand veränderte nginx-Site bleibt; die neue Fassung liegt daneben. Fehlt der Site der Block für Grundrisse bis 100 MB (location = /standorte/grundriss), nennt das Protokoll es – ohne ihn lehnt nginx große Pläne ab.',
            'acl_behalten'   => 'Die von Hand veränderte Mosquitto-ACL bleibt; die neue Fassung liegt daneben.',
            'mqtt_helfer'    => 'Der Abgleich der MQTT-Zugänge meldete Fehler.',
            'nachgeholt'     => 'Fehlende Einrichtung wurde nachgeholt.',
            'treiber'        => 'Die Datenbank ist nicht SQLite – zurück nur mit eigener Sicherung.',
            default          => 'Hinweis: ' . $code,
        };
    }
}
