<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Audit;
use App\Support\Config;
use App\Support\Database;
use App\Support\Settings;
use App\Support\Zeit;

/**
 * Automatische Updates: suchen und im Zeitfenster einspielen.
 *
 * Drei Modi: aus · nur suchen und anzeigen (Vorgabe) · automatisch
 * einspielen. Entschieden wird hier, in PHP, als Anwendungsbenutzer
 * (bin/power update:zeitplan, alle fünf Minuten über
 * powermanagement-update-zeitplan.timer) – nur hier sieht man Aufbau und
 * Schaltvorgänge. Das Ergebnis ist ein gewöhnlicher Auftrag an den
 * Update-Dienst (UpdateService::anfordern, Auslöser „automatik“, von
 * „zeitplan“); root liest nie die Datenbank und bremst nur nach (Alter,
 * Kennung, 20 h, gescheiterte Stände, AUTOMATIK=nein in update.conf).
 *
 * Ein Update startet die Dienste neu: Messung und Schalten sind 1–3 Minuten
 * unterbrochen, die Shellys kurz getrennt. Deshalb nie während eines Aufbaus
 * und nicht kurz nach einem Schaltvorgang (über alle Standorte – ein Update
 * trifft jeden). Von Hand fragt die Oberfläche nach („Trotzdem jetzt
 * aktualisieren“), auf der Konsole heißt das --trotz-betrieb.
 *
 * Einstellungen in `settings` (keine Migration, nicht in OVERRIDABLE),
 * Zeiten dort als ISO 8601 in UTC; Beginn und Wochentage als Wanduhr in
 * Zeit::zone(). Das Lebenszeichen des Zeitplans ist die mtime von
 * storage/update/zeitplan – keine Schreibvorgänge im Fünf-Minuten-Takt.
 */
final class UpdateAutomatik
{
    public const MODUS = 'update.modus', TAGE = 'update.tage', BEGINN = 'update.beginn', DAUER = 'update.dauer',
                 KARENZ = 'update.karenz', PAUSE = 'update.pause_bis', VERSATZ = 'update.versatz',
                 LETZTER = 'update.auto_letzter', VERSCHOBEN = 'update.verschoben_seit';

    public const MODI = ['aus', 'suchen', 'einspielen'];
    /** Dauer des Fensters in Minuten, Karenz in Stunden ab Commit-Zeit. */
    public const DAUERN = [60, 120, 240];
    public const KARENZEN = [0, 6, 24, 72];
    /** 04:00: nicht 02–03 (Zeitumstellung), nicht 06–07 (apt-daily-upgrade). */
    public const VORGABE_BEGINN = '04:00', VORGABE_DAUER = 120, VORGABE_KARENZ = 24;

    /** Geschaltet in den letzten so vielen Sekunden: Betrieb. */
    public const SCHALTEN_ABSTAND = 1800;
    /** Stand älter (dazu bis zu 59 min Versatz je Installation): suchen. */
    public const SUCHEN_ALLE = 21600;
    /** Im Fenster nur mit einem Stand, der höchstens so alt ist – sonst erst suchen. */
    public const FRISCH_IM_FENSTER = 1800;
    /** Eine Suche des Zeitplans höchstens so oft (ohne Netz schlägt sie fehl). */
    public const SUCHE_WIEDERHOLEN = 1800;
    /** So lange vor dem Ende des Fensters beginnt nichts mehr. */
    public const FENSTER_RAND = 900;
    /** Ohne Lebenszeichen des Zeitplans so lange: „Zeitplan still“. */
    public const ZEITPLAN_STILL = 900;
    /** Ab so langem Verschieben: Abzeichen und Banner. */
    public const VERSCHOBEN_LANG = 14 * 86400;
    public const PAUSE_MAX_TAGE = 90;

    private const TAG_NAMEN = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    // ================================================================ Einstellungen

    /**
     * @return array{modus:string, tage:list<int>, beginn:string, dauer:int, karenz:int, pause_bis:?int, versatz:?int}
     */
    public static function einstellungen(): array
    {
        $modus = (string) Settings::get(self::MODUS, 'suchen');
        $tage = Settings::get(self::TAGE);
        $beginn = (string) Settings::get(self::BEGINN, self::VORGABE_BEGINN);
        $dauer = (int) Settings::get(self::DAUER, (string) self::VORGABE_DAUER);
        $karenz = (string) Settings::get(self::KARENZ, (string) self::VORGABE_KARENZ);
        $versatz = Settings::get(self::VERSATZ);
        return [
            'modus'     => in_array($modus, self::MODI, true) ? $modus : 'suchen',
            'tage'      => $tage === null ? [1, 2, 3, 4, 5, 6, 7] : self::tageLesen($tage),
            'beginn'    => self::beginnGueltig($beginn) ? $beginn : self::VORGABE_BEGINN,
            'dauer'     => in_array($dauer, self::DAUERN, true) ? $dauer : self::VORGABE_DAUER,
            'karenz'    => preg_match('/^[0-9]{1,2}$/', $karenz) === 1 && in_array((int) $karenz, self::KARENZEN, true) ? (int) $karenz : self::VORGABE_KARENZ,
            'pause_bis' => Zeit::ts(Settings::get(self::PAUSE)),
            'versatz'   => $versatz !== null && preg_match('/^[0-9]{1,4}$/', $versatz) === 1 ? (int) $versatz : null,
        ];
    }

    /** @return list<int> ISO-Wochentage 1 (Montag) … 7 (Sonntag), sortiert */
    private static function tageLesen(string $wert): array
    {
        $tage = [];
        foreach (explode(',', $wert) as $t) {
            if (preg_match('/^[1-7]$/', trim($t)) === 1) {
                $tage[(int) $t] = (int) $t;
            }
        }
        ksort($tage);
        return array_values($tage);
    }

    private static function beginnGueltig(string $beginn): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $beginn) === 1;
    }

    /**
     * Einstellungen speichern. $pauseBis ist ein Datum (JJJJ-MM-TT, Ende
     * des Tages in Zeit::zone()) oder leer; $versatz (Minuten) nur für
     * Prüfungen – sonst wählt der Zeitplan ihn einmal selbst.
     *
     * @param list<int|string> $tage
     * @return array{ok:bool, message:string, werte?:array<string,mixed>}
     */
    public static function speichern(string $modus, array $tage, string $beginn, int $dauer, int $karenz, ?string $pauseBis, ?int $versatz = null): array
    {
        $fehler = static fn (string $text): array => ['ok' => false, 'message' => $text];
        if (!in_array($modus, self::MODI, true)) {
            return $fehler('Unbekannter Modus – erlaubt sind aus, suchen und einspielen.');
        }
        $gewaehlt = [];
        foreach ($tage as $t) {
            if (preg_match('/^[1-7]$/', (string) $t) !== 1) {
                return $fehler('Wochentage nur von 1 (Montag) bis 7 (Sonntag).');
            }
            $gewaehlt[(int) $t] = (int) $t;
        }
        ksort($gewaehlt);
        $gewaehlt = array_values($gewaehlt);
        if ($modus === 'einspielen' && $gewaehlt === []) {
            return $fehler('Zum automatischen Einspielen mindestens einen Wochentag wählen.');
        }
        if (!self::beginnGueltig($beginn)) {
            return $fehler('Beginn als Uhrzeit HH:MM (00:00 bis 23:59).');
        }
        if (!in_array($dauer, self::DAUERN, true)) {
            return $fehler('Dauer des Fensters: 60, 120 oder 240 Minuten.');
        }
        if (!in_array($karenz, self::KARENZEN, true)) {
            return $fehler('Karenzzeit: 0, 6, 24 oder 72 Stunden.');
        }
        $pause = null;
        $pauseBis = trim((string) $pauseBis);
        if ($pauseBis !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pauseBis) !== 1 || ($pause = Zeit::ausEingabe($pauseBis, true)) === null) {
                return $fehler('„Pausieren bis“ als Datum JJJJ-MM-TT.');
            }
            $heute = (new \DateTimeImmutable('now', Zeit::zone()))->setTime(0, 0);
            $tag = new \DateTimeImmutable($pauseBis . ' 00:00:00', Zeit::zone());
            if ($tag < $heute) {
                return $fehler('„Pausieren bis“ liegt in der Vergangenheit.');
            }
            if ($tag > $heute->modify('+' . self::PAUSE_MAX_TAGE . ' days')) {
                return $fehler('Pausieren höchstens ' . self::PAUSE_MAX_TAGE . ' Tage voraus.');
            }
        }
        if ($versatz !== null && ($versatz < 0 || $versatz > max(self::DAUERN) - 30)) {
            return $fehler('Versatz in Minuten: 0 bis ' . (max(self::DAUERN) - 30) . '.');
        }
        Settings::put(self::MODUS, $modus);
        Settings::put(self::TAGE, implode(',', $gewaehlt));
        Settings::put(self::BEGINN, $beginn);
        Settings::put(self::DAUER, (string) $dauer);
        Settings::put(self::KARENZ, (string) $karenz);
        if ($pause === null) {
            Settings::forget(self::PAUSE);
        } else {
            Settings::put(self::PAUSE, $pause);
        }
        if ($versatz !== null) {
            Settings::put(self::VERSATZ, (string) $versatz);
        }
        // Nicht mehr einspielen: dann wird auch nichts mehr verschoben.
        if ($modus !== 'einspielen') {
            Settings::forget(self::VERSCHOBEN);
        }
        Settings::vergessen();
        return [
            'ok'      => true,
            'message' => 'Automatische Updates: ' . self::modusText($modus) . '.',
            'werte'   => ['modus' => $modus, 'tage' => implode(',', $gewaehlt), 'beginn' => $beginn, 'dauer' => $dauer,
                'karenz' => $karenz, 'pause_bis' => $pause],
        ];
    }

    /**
     * Versatz je Installation in Minuten: einmal zufällig gewählt (nie aus
     * dem Schlüssel abgeleitet), damit nicht alle Container eines Hosts in
     * derselben Minute holen und neu starten.
     *
     * @param array{dauer:int, versatz:?int} $e
     */
    private static function versatz(array $e, bool $anlegen): int
    {
        if ($e['versatz'] !== null) {
            return $e['versatz'];
        }
        $v = random_int(0, max(0, $e['dauer'] - 30));
        if ($anlegen) {
            Settings::put(self::VERSATZ, (string) $v);
        }
        return $v;
    }

    /**
     * Das zuletzt Entschiedene (update.auto_letzter).
     *
     * @return array{tag:string, zeit:int, ergebnis:string, grund:string, ziel:?string, text:string, gemeldet:list<string>}|null
     */
    public static function letzter(): ?array
    {
        $j = json_decode((string) Settings::get(self::LETZTER, ''), true);
        if (!is_array($j) || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($j['tag'] ?? '')) !== 1
            || !in_array($j['ergebnis'] ?? '', ['angefordert', 'verschoben', 'nichts_neu', 'gesucht'], true)) {
            return null;
        }
        $grund = (string) ($j['grund'] ?? '');
        $ziel = (string) ($j['ziel'] ?? '');
        return [
            'tag'      => (string) $j['tag'],
            'zeit'     => is_int($j['zeit'] ?? null) ? $j['zeit'] : 0,
            'ergebnis' => (string) $j['ergebnis'],
            'grund'    => preg_match('/^[a-z_]{0,40}$/', $grund) === 1 ? $grund : '',
            'ziel'     => preg_match('/^[0-9a-f]{40}$/', $ziel) === 1 ? $ziel : null,
            'text'     => mb_substr(mb_scrub((string) ($j['text'] ?? ''), 'UTF-8'), 0, 200),
            'gemeldet' => array_values(array_filter((array) ($j['gemeldet'] ?? []), static fn ($g) => is_string($g) && preg_match('/^[a-z_]{1,40}$/', $g) === 1)),
        ];
    }

    /** Seit wann automatische Updates verschoben werden (Unix) – oder null. */
    public static function verschobenSeit(): ?int
    {
        return Zeit::ts(Settings::get(self::VERSCHOBEN));
    }

    // ================================================================ Fenster

    /** Der lokale Kalendertag eines Zeitpunkts (Zeit::zone()), mittags. */
    private static function tagVon(int $ts): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(Zeit::zone())->setTime(12, 0);
    }

    /**
     * Das Fenster eines Tages: beginn/ende nominal (Wanduhr), ab/bis, wann
     * der Zeitplan einspielen darf (ab Beginn + Versatz, bis 15 min vor dem
     * Ende). Absolute Zeiten aus DateTimeImmutable – in einer Lücke der
     * Sommerzeit schiebt PHP vor (02:30 → 03:30), die Dauer ist echte Zeit.
     *
     * @param array{beginn:string, dauer:int, versatz:?int} $e
     * @return array{tag:string, beginn:int, ende:int, ab:int, bis:int}
     */
    private static function fensterAm(string $tag, array $e): array
    {
        $beginn = (new \DateTimeImmutable($tag . ' ' . $e['beginn'] . ':00', Zeit::zone()))->getTimestamp();
        $versatz = (int) ($e['versatz'] ?? 0) % (max(0, $e['dauer'] - 30) + 1);
        return [
            'tag'    => $tag,
            'beginn' => $beginn,
            'ende'   => $beginn + $e['dauer'] * 60,
            'ab'     => $beginn + $versatz * 60,
            'bis'    => $beginn + $e['dauer'] * 60 - self::FENSTER_RAND,
        ];
    }

    /**
     * Das Fenster, in dem jetzt eingespielt werden darf – heute oder (über
     * Mitternacht) von gestern; es gehört zum Starttag. Sonst null.
     *
     * @param array<string,mixed>|null $e wie einstellungen()
     * @return array{tag:string, beginn:int, ende:int, ab:int, bis:int}|null
     */
    public static function fenster(int $jetzt, ?array $e = null): ?array
    {
        $e ??= self::einstellungen();
        if ($e['tage'] === []) {
            return null;
        }
        $heute = self::tagVon($jetzt);
        foreach ([$heute->modify('-1 day'), $heute] as $tag) {
            if (!in_array((int) $tag->format('N'), $e['tage'], true)) {
                continue;
            }
            $f = self::fensterAm($tag->format('Y-m-d'), $e);
            if ($jetzt >= $f['ab'] && $jetzt < $f['bis']) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Das nächste Fenster, das noch offen ist oder kommt (nach einer Pause).
     *
     * @param array<string,mixed>|null $e wie einstellungen()
     * @return array{tag:string, beginn:int, ende:int, ab:int, bis:int}|null
     */
    public static function naechstesFenster(int $jetzt, ?array $e = null): ?array
    {
        $e ??= self::einstellungen();
        if ($e['tage'] === []) {
            return null;
        }
        $ab = max($jetzt, (int) ($e['pause_bis'] ?? 0));
        $tag = self::tagVon($jetzt)->modify('-1 day');
        for ($i = 0; $i < self::PAUSE_MAX_TAGE + 10; $i++, $tag = $tag->modify('+1 day')) {
            if (!in_array((int) $tag->format('N'), $e['tage'], true)) {
                continue;
            }
            $f = self::fensterAm($tag->format('Y-m-d'), $e);
            if ($f['bis'] > $ab) {
                return $f;
            }
        }
        return null;
    }

    /** Wann frühestens wieder automatisch eingespielt werden darf (Unix) – oder null. */
    public static function naechsterTermin(int $jetzt): ?int
    {
        $e = self::einstellungen();
        if ($e['modus'] !== 'einspielen') {
            return null;
        }
        $f = self::naechstesFenster($jetzt, $e);
        return $f === null ? null : max($f['ab'], (int) ($e['pause_bis'] ?? 0), $jetzt);
    }

    // ================================================================ Betrieb

    /**
     * @return list<array{art:string, text:string}> aufbau (jeder Aufbau, der nicht beendet ist), geschaltet
     */
    public static function hindernisse(int $jetzt): array
    {
        $liste = [];
        try {
            $aufbauten = Database::select(
                "SELECT a.name, a.status, s.name AS standort FROM aufbauten a LEFT JOIN sites s ON s.id = a.site_id
                  WHERE a.status <> 'beendet' ORDER BY a.gestartet_am, a.id"
            );
            foreach ($aufbauten as $a) {
                $liste[] = ['art' => 'aufbau', 'text' => sprintf(
                    '%s „%s“%s läuft.',
                    $a['status'] === 'betrieb' ? 'Betrieb' : 'Aufbau',
                    (string) $a['name'],
                    (string) ($a['standort'] ?? '') !== '' ? ' (Standort ' . $a['standort'] . ')' : ''
                )];
            }
            $geschaltet = (int) Database::scalar('SELECT COUNT(*) FROM schaltvorgaenge WHERE created_at >= ?', [gmdate('c', $jetzt - self::SCHALTEN_ABSTAND)]);
            if ($geschaltet > 0) {
                $liste[] = ['art' => 'geschaltet', 'text' => 'In den letzten 30 Minuten wurde geschaltet ('
                    . ($geschaltet === 1 ? 'ein Schaltvorgang' : $geschaltet . ' Schaltvorgänge') . ').'];
            }
        } catch (\Throwable) {
            // Tabellen fehlen (vor der Migration) – dann gibt es auch keinen Betrieb.
        }
        return $liste;
    }

    // ================================================================ Entscheiden

    /**
     * Alles, was die Entscheidung braucht – aus Dateien, Einstellungen und
     * Datenbank. Legt beim ersten Bedarf den Versatz an.
     *
     * @return array<string,mixed>
     */
    public static function lage(int $jetzt, bool $versatzAnlegen = true): array
    {
        $z = UpdateService::zustand($jetzt);
        $e = self::einstellungen();
        if (!in_array($z['zustand'], ['demo', 'nicht_eingerichtet'], true)) {
            $e['versatz'] = self::versatz($e, $versatzAnlegen);
        }
        $pfad = UpdateService::verzeichnis() . '/.letzter';
        clearstatcache(true, $pfad);
        $letzteSuche = @filemtime($pfad);
        return [
            'einstellungen'  => $e,
            'zustand'        => $z['zustand'],
            'aktiv'          => UpdateService::aktiv($z),
            'wartung'        => $z['wartung'],
            'abgeschaltet'   => $z['abgeschaltet'],
            'automatik_root' => $z['automatik_root'],
            'offen'          => $z['offen'],
            'stand'          => $z['stand'],
            'letzte_suche'   => $letzteSuche === false ? null : $letzteSuche,
            'letzter'        => self::letzter(),
            'hindernisse'    => $e['modus'] === 'einspielen' ? self::hindernisse($jetzt) : [],
            'treiber'        => Database::driver(),
        ];
    }

    /**
     * Was jetzt zu tun ist – rein, ohne Seiteneffekte (urteil §10).
     *
     * grund: demo | nicht_eingerichtet | wartung | laeuft | aus | abgeschaltet
     *   | suchen | suche_wartet | nur_suchen | ausserhalb | erledigt | suchen_vorher
     *   | nichts_neu | einspielen | verschoben:<pausiert|nicht_linear|lokal_geaendert
     *   |kein_zweig|abgeschaltet|offen|karenz|gescheitert|aufbau|geschaltet|treiber>
     *
     * @param array<string,mixed> $lage wie lage()
     * @return array{aktion:?string, ziel:?string, grund:string, tag:?string, text:string}
     */
    public static function entscheiden(int $jetzt, array $lage): array
    {
        $e = $lage['einstellungen'];
        $ergebnis = static fn (?string $aktion, string $grund, ?string $tag = null, ?string $ziel = null, string $text = ''): array =>
            ['aktion' => $aktion, 'ziel' => $ziel, 'grund' => $grund, 'tag' => $tag, 'text' => $text];
        $zustand = (string) ($lage['zustand'] ?? 'bereit');
        if ($zustand === 'demo' || $zustand === 'nicht_eingerichtet') {
            return $ergebnis(null, $zustand);
        }
        if (!empty($lage['wartung'])) {
            return $ergebnis(null, 'wartung');
        }
        if (!empty($lage['aktiv'])) {
            return $ergebnis(null, 'laeuft');
        }
        if ($e['modus'] === 'aus') {
            return $ergebnis(null, 'aus');
        }
        // OBERFLAECHE=nein: der Update-Dienst nähme keinen Auftrag an.
        if (!empty($lage['abgeschaltet'])) {
            return $ergebnis(null, 'abgeschaltet');
        }
        $stand = $lage['stand'] ?? null;
        $abruf = $stand['abruf_zeit'] ?? null;
        $alter = $abruf === null ? PHP_INT_MAX : $jetzt - (int) $abruf;
        $letzteSuche = $lage['letzte_suche'] ?? null;
        // Ohne Netz schlägt die Suche fehl, der Stand bleibt alt – dann
        // nicht alle fünf Minuten erneut holen.
        $sucheFrei = $letzteSuche === null || $jetzt - (int) $letzteSuche >= self::SUCHE_WIEDERHOLEN || $jetzt < (int) $letzteSuche;
        if ($stand === null || $alter > self::SUCHEN_ALLE + ((int) ($e['versatz'] ?? 0) % 60) * 60) {
            return $sucheFrei ? $ergebnis('pruefen', 'suchen') : $ergebnis(null, 'suche_wartet');
        }
        if ($e['modus'] !== 'einspielen') {
            return $ergebnis(null, 'nur_suchen');
        }
        $f = self::fenster($jetzt, $e);
        if ($f === null) {
            return $ergebnis(null, 'ausserhalb');
        }
        $tag = $f['tag'];
        // Höchstens ein Lauf je Tag – auch wenn er scheitert.
        $l = $lage['letzter'] ?? null;
        if ($l !== null && $l['tag'] === $tag && in_array($l['ergebnis'], ['angefordert', 'nichts_neu'], true)) {
            return $ergebnis(null, 'erledigt', $tag);
        }
        if ($e['pause_bis'] !== null && $jetzt < $e['pause_bis']) {
            return $ergebnis(null, 'verschoben:pausiert', $tag);
        }
        // Erst mit frischem Stand entscheiden – der nächste Takt tut es.
        if ($alter > self::FRISCH_IM_FENSTER) {
            return $sucheFrei ? $ergebnis('pruefen', 'suchen_vorher', $tag) : $ergebnis(null, 'suche_wartet', $tag);
        }
        if ((int) $stand['neu'] <= 0 || $stand['verfuegbar'] === null) {
            return $ergebnis(null, 'nichts_neu', $tag);
        }
        $nicht = match (true) {
            !$stand['linear']                     => 'nicht_linear',
            $stand['lokal_geaendert']             => 'lokal_geaendert',
            $stand['zweig'] === null              => 'kein_zweig',
            !empty($lage['automatik_root'])       => 'abgeschaltet',
            ($lage['offen'] ?? 'nein') !== 'nein' => 'offen',
            default                               => null,
        };
        if ($nicht !== null) {
            return $ergebnis(null, 'verschoben:' . $nicht, $tag);
        }
        // Ziel: der neueste Kandidat, der älter als die Karenz ist und nicht
        // schon einmal gescheitert – automatisch nie dasselbe Ziel erneut.
        $kandidaten = $stand['kandidaten'] !== [] ? $stand['kandidaten'] : [$stand['verfuegbar']];
        $grenze = $jetzt - (int) $e['karenz'] * 3600;
        $ziel = null;
        $gescheitert = false;
        foreach ($kandidaten as $k) {
            if ($k['zeit'] > $grenze) {
                continue;
            }
            if (in_array($k['commit'], $stand['gescheitert'], true)) {
                $gescheitert = true;
                continue;
            }
            $ziel = $k['commit'];
            break;
        }
        if ($ziel === null) {
            return $ergebnis(null, $gescheitert ? 'verschoben:gescheitert' : 'verschoben:karenz', $tag);
        }
        // Veranstaltungsschutz – hart, über alle Standorte.
        foreach (['aufbau', 'geschaltet'] as $art) {
            foreach ((array) ($lage['hindernisse'] ?? []) as $h) {
                if (($h['art'] ?? '') === $art) {
                    return $ergebnis(null, 'verschoben:' . $art, $tag, $ziel, (string) ($h['text'] ?? ''));
                }
            }
        }
        // Zurück kommt eine Datenbank nur als SQLite-Schnappschuss.
        if (($lage['treiber'] ?? 'sqlite') !== 'sqlite' && $stand['migrationen']) {
            return $ergebnis(null, 'verschoben:treiber', $tag, $ziel);
        }
        return $ergebnis('aktualisieren', 'einspielen', $tag, $ziel);
    }

    // ================================================================ Zeitplan

    /**
     * bin/power update:zeitplan, alle fünf Minuten als Anwendungsbenutzer.
     * Schreibt Auftrag, Einstellungen und Audit; gibt zurück, was geschah
     * (meldung leer: nichts zu tun).
     *
     * @return array{aktion:?string, grund:string, meldung:string, kennung:?string, ziel:?string}
     */
    public static function zeitplan(?int $jetzt = null): array
    {
        $jetzt ??= time();
        $aus = static fn (?string $aktion, string $grund, string $meldung = '', ?string $kennung = null, ?string $ziel = null): array =>
            ['aktion' => $aktion, 'grund' => $grund, 'meldung' => $meldung, 'kennung' => $kennung, 'ziel' => $ziel];
        UpdateService::vergessen();
        if (Config::isDemo()) {
            return $aus(null, 'demo');
        }
        if (UpdateService::helfer() === null) {
            return $aus(null, 'nicht_eingerichtet');
        }
        // Während der Schreibsperre nichts schreiben: ein Zurückrollen spielte
        // die Datenbank von vorher zurück. Auch keine offene Verbindung halten.
        if (UpdateService::wartungAktiv($jetzt)) {
            Database::trennen();
            return $aus(null, 'wartung');
        }
        @touch(UpdateService::verzeichnis() . '/zeitplan');
        UpdateService::vermerken();
        UpdateService::vergessen();
        Settings::vergessen();
        $lage = self::lage($jetzt);
        $e = self::entscheiden($jetzt, $lage);
        $tag = $e['tag'] ?? self::tagVon($jetzt)->format('Y-m-d');

        if ($e['aktion'] !== null) {
            try {
                $r = UpdateService::anfordern($e['aktion'], $e['ziel'], 'automatik', 'zeitplan');
            } catch (\RuntimeException $ex) {
                $r = ['ok' => false, 'message' => $ex->getMessage()];
            }
            if (!$r['ok']) {
                // Kam zwischen Entscheidung und Auftrag Betrieb dazu: verschoben.
                $h = $r['hindernisse'][0] ?? null;
                if (!empty($r['bestaetigen']) && $h !== null) {
                    return self::verschieben($jetzt, $tag, (string) $h['art'], $e['ziel'], (string) $h['text'], $aus);
                }
                return $aus(null, $e['grund'], 'Kein Auftrag: ' . $r['message']);
            }
            $kennung = (string) $r['kennung'];
            if ($e['aktion'] === 'pruefen') {
                if ($e['grund'] === 'suchen_vorher') {
                    self::letzterSetzen($jetzt, $tag, 'gesucht', '', null, '');
                }
                return $aus('pruefen', $e['grund'], 'Suche angefordert (Auftrag ' . substr($kennung, 0, 8) . ').', $kennung);
            }
            self::letzterSetzen($jetzt, $tag, 'angefordert', '', $e['ziel'], '');
            Settings::forget(self::VERSCHOBEN);
            Audit::log('system.update.automatisch', 'update', $kennung, ['aktion' => 'aktualisieren', 'kennung' => $kennung, 'ziel7' => substr((string) $e['ziel'], 0, 7)]);
            return $aus('aktualisieren', $e['grund'], 'Update auf ' . substr((string) $e['ziel'], 0, 7) . ' angefordert (Auftrag ' . substr($kennung, 0, 8) . ').', $kennung, $e['ziel']);
        }
        if (str_starts_with($e['grund'], 'verschoben:')) {
            return self::verschieben($jetzt, $tag, substr($e['grund'], strlen('verschoben:')), $e['ziel'], $e['text'], $aus);
        }
        if ($e['grund'] === 'nichts_neu') {
            Settings::forget(self::VERSCHOBEN);
            $neu = self::letzterSetzen($jetzt, $tag, 'nichts_neu', '', null, '');
            return $aus(null, 'nichts_neu', $neu ? 'Nichts Neues zum Einspielen.' : '');
        }
        return $aus(null, $e['grund']);
    }

    /**
     * Verschoben: der Tag bleibt offen (beim nächsten Takt im Fenster wieder),
     * „seit“ merken, ins Audit höchstens einmal je Tag und Grund.
     *
     * @return array{aktion:?string, grund:string, meldung:string, kennung:?string, ziel:?string}
     */
    private static function verschieben(int $jetzt, string $tag, string $grund, ?string $ziel, string $text, callable $aus): array
    {
        $grund = preg_match('/^[a-z_]{1,40}$/', $grund) === 1 ? $grund : 'unbekannt';
        $neu = self::letzterSetzen($jetzt, $tag, 'verschoben', $grund, $ziel, $text);
        $l = self::letzter();
        if ($l !== null && $l['tag'] === $tag && $l['ergebnis'] === 'verschoben') {
            if (self::verschobenSeit() === null) {
                Settings::put(self::VERSCHOBEN, Zeit::iso($jetzt));
            }
            if (!in_array($grund, $l['gemeldet'], true)) {
                Audit::log('system.update.verschoben', 'update', '', ['grund' => $grund]);
                $l['gemeldet'][] = $grund;
                Settings::put(self::LETZTER, (string) json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
        return $aus(null, 'verschoben:' . $grund, $neu ? 'Verschoben: ' . self::grundText($grund, $text) : '', null, $ziel);
    }

    /**
     * update.auto_letzter setzen – nur, wenn sich etwas ändert (nicht alle
     * fünf Minuten schreiben). Ein erledigter Tag (angefordert, nichts Neues)
     * wird nicht mehr überschrieben. true: geändert.
     */
    private static function letzterSetzen(int $jetzt, string $tag, string $ergebnis, string $grund, ?string $ziel, string $text): bool
    {
        $alt = self::letzter();
        $selberTag = $alt !== null && $alt['tag'] === $tag;
        if ($selberTag && in_array($alt['ergebnis'], ['angefordert', 'nichts_neu'], true) && $ergebnis !== 'angefordert') {
            return false;
        }
        if ($selberTag && $alt['ergebnis'] === $ergebnis && $alt['grund'] === $grund && $alt['ziel'] === $ziel && $alt['text'] === $text) {
            return false;
        }
        Settings::put(self::LETZTER, (string) json_encode([
            'tag' => $tag, 'zeit' => $jetzt, 'ergebnis' => $ergebnis, 'grund' => $grund, 'ziel' => $ziel,
            'text' => mb_substr($text, 0, 200), 'gemeldet' => $selberTag ? $alt['gemeldet'] : [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return true;
    }

    // ================================================================ Anzeige

    /** Sekunden seit dem letzten Lauf des Zeitplans – null: noch nie. */
    public static function zeitplanAlter(?int $jetzt = null): ?int
    {
        $pfad = UpdateService::verzeichnis() . '/zeitplan';
        clearstatcache(true, $pfad);
        $t = @filemtime($pfad);
        return $t === false ? null : max(0, ($jetzt ?? time()) - $t);
    }

    /**
     * Läuft der Zeitplan nicht (Modus ≠ aus, seit 15 min kein Lebenszeichen)?
     * Lief er noch nie, zählt die Zeit ab der Einrichtung (storage/update/soll)
     * – ohne Namensräume im LXC (226/NAMESPACE) startet er nie. Während der
     * Schreibsperre setzt er bewusst aus.
     *
     * @param array<string,mixed>|null $zustand wie UpdateService::zustand()
     */
    public static function zeitplanStill(?int $jetzt = null, ?array $zustand = null): bool
    {
        $jetzt ??= time();
        $z = $zustand ?? UpdateService::zustand($jetzt);
        if (in_array($z['zustand'], ['demo', 'nicht_eingerichtet'], true) || $z['wartung'] || self::einstellungen()['modus'] === 'aus') {
            return false;
        }
        $alter = self::zeitplanAlter($jetzt);
        if ($alter === null) {
            $pfad = UpdateService::verzeichnis() . '/soll';
            clearstatcache(true, $pfad);
            $soll = @filemtime($pfad);
            $alter = $soll === false ? null : max(0, $jetzt - $soll);
        }
        return $alter !== null && $alter > self::ZEITPLAN_STILL;
    }

    /** Werden automatische Updates seit 14 Tagen oder länger verschoben? */
    public static function langeVerschoben(?int $jetzt = null): bool
    {
        $seit = self::verschobenSeit();
        return $seit !== null && self::einstellungen()['modus'] === 'einspielen' && ($jetzt ?? time()) - $seit >= self::VERSCHOBEN_LANG;
    }

    /**
     * Für Seite und Konsole.
     *
     * @param array<string,mixed>|null $zustand wie UpdateService::zustand()
     * @return array<string,mixed>
     */
    public static function anzeige(?int $jetzt = null, ?array $zustand = null): array
    {
        $jetzt ??= time();
        $e = self::einstellungen();
        return [
            'einstellungen'    => $e,
            'modus_text'       => self::modusText($e['modus']),
            'fenster_text'     => self::fensterText($e),
            'pausiert'         => $e['pause_bis'] !== null && $e['pause_bis'] > $jetzt,
            'naechstes'        => $e['modus'] === 'einspielen' ? self::naechstesFenster($jetzt, $e) : null,
            'letzter'          => self::letzter(),
            'zeitplan_alter'   => self::zeitplanAlter($jetzt),
            'zeitplan_still'   => self::zeitplanStill($jetzt, $zustand),
            'verschoben_seit'  => self::verschobenSeit(),
            'lange_verschoben' => self::langeVerschoben($jetzt),
        ];
    }

    public static function modusText(string $modus): string
    {
        return match ($modus) {
            'aus'        => 'aus',
            'einspielen' => 'automatisch einspielen',
            default      => 'nur suchen und anzeigen',
        };
    }

    /**
     * „täglich“, „Mo–Fr“, „Mo, Mi, Fr“
     *
     * @param list<int> $tage
     */
    public static function tageText(array $tage): string
    {
        $tage = array_values(array_filter($tage, static fn ($t) => isset(self::TAG_NAMEN[$t])));
        if ($tage === [1, 2, 3, 4, 5, 6, 7]) {
            return 'täglich';
        }
        if ($tage === []) {
            return 'an keinem Tag';
        }
        if (count($tage) >= 3 && $tage === range($tage[0], $tage[count($tage) - 1])) {
            return self::TAG_NAMEN[$tage[0]] . '–' . self::TAG_NAMEN[$tage[count($tage) - 1]];
        }
        return implode(', ', array_map(static fn (int $t): string => self::TAG_NAMEN[$t], $tage));
    }

    /** @return array<int,string> 1 => Mo … 7 => So */
    public static function tagNamen(): array
    {
        return self::TAG_NAMEN;
    }

    /**
     * „täglich ab 04:00 für 2 Std., Karenz 24 Std.“
     *
     * @param array<string,mixed> $e wie einstellungen()
     */
    public static function fensterText(array $e): string
    {
        return self::tageText($e['tage']) . ' ab ' . $e['beginn'] . ' für ' . intdiv((int) $e['dauer'], 60) . ' Std., Karenz '
            . ((int) $e['karenz'] === 0 ? 'keine' : $e['karenz'] . ' Std.');
    }

    /**
     * „Sa 18.10. 04:00–06:00“
     *
     * @param array{beginn:int, ende:int} $f
     */
    public static function terminText(array $f): string
    {
        $von = (new \DateTimeImmutable('@' . $f['beginn']))->setTimezone(Zeit::zone());
        $bis = (new \DateTimeImmutable('@' . $f['ende']))->setTimezone(Zeit::zone());
        return self::TAG_NAMEN[(int) $von->format('N')] . ' ' . $von->format('d.m. H:i') . '–' . $bis->format('H:i');
    }

    /** Warum verschoben wurde, als Satz. */
    public static function grundText(string $grund, string $text = ''): string
    {
        return match ($grund) {
            'pausiert'        => 'Automatische Updates sind pausiert.',
            'nicht_linear'    => 'Der Verlauf ist nicht linear – nur über die Konsole.',
            'lokal_geaendert' => 'Im Code gibt es lokale Änderungen – nur über die Konsole.',
            'kein_zweig'      => 'Es ist kein Zweig ausgecheckt – nur über die Konsole.',
            'abgeschaltet'    => 'Automatisches Einspielen ist auf diesem System abgeschaltet (/etc/powermanagement/update.conf).',
            'offen'           => 'Ein unterbrochenes Update ist noch nicht zurückgerollt.',
            'karenz'          => 'Die neuen Stände sind jünger als die Karenzzeit.',
            'gescheitert'     => 'Der neue Stand ist schon einmal gescheitert – automatisch wird er nicht wiederholt.',
            'aufbau'          => $text !== '' ? $text : 'Ein Aufbau läuft.',
            'geschaltet'      => $text !== '' ? $text : 'In den letzten 30 Minuten wurde geschaltet.',
            'treiber'         => 'Das Update erweitert die Datenbank, und sie ist nicht SQLite – nur von Hand mit eigener Sicherung.',
            default           => 'Grund: ' . $grund,
        };
    }

    /**
     * „Update auf 3b1f0c9 angefordert“, „verschoben: Aufbau „Messe“ läuft.“
     *
     * @param array<string,mixed>|null $l wie letzter()
     */
    public static function letzterText(?array $l): string
    {
        if ($l === null) {
            return 'noch nichts';
        }
        return match ($l['ergebnis']) {
            'angefordert' => 'Update auf ' . substr((string) $l['ziel'], 0, 7) . ' angefordert',
            'nichts_neu'  => 'nichts Neues',
            'gesucht'     => 'nach neuen Ständen gesucht',
            default       => 'verschoben: ' . self::grundText((string) $l['grund'], (string) $l['text']),
        };
    }
}
