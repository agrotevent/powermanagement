<?php
declare(strict_types=1);

namespace App\Geraetepruefung;

use App\Auth\Auth;
use App\Berichte\Kanon;
use App\Kunden\KundenService;
use App\Services\UpdateService;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Database;

/**
 * Datenbank des Prüfgeräts importieren: lesen → Vorschau → übernehmen
 * (Muster KatalogImport).
 *
 * - {@see lesen()}: Normalform aus {@see BenningDatei}, dazu die bereinigte
 *   Vorlage ({@see BenningVorlage::bereinigen()}), wenn die Datei taugt. Das
 *   Paket liegt bis zum Übernehmen unter `storage/cache/gp-import-<32 hex>.json`
 *   (eine Stunde, {@see aufraeumen()} in `bin/power wartung`); die Kennung
 *   steht in der Sitzung, das Formular schickt nur die Auswahl.
 * - {@see pruefen()}: Abgleich mit dem Bestand für die Vorschau – je Kunde im
 *   Gerät ein Vorschlag (`PM-EIGEN` → eigener Bestand, `PM-K<id>` → dieser
 *   Kunde, gleiche Kundennummer, gemerkte Zuordnung; sonst „nicht
 *   übernehmen“), je Prüfling neu / schon da / hier anders / Fehler, je
 *   Prüfung neu / schon da (über den Fingerabdruck).
 * - {@see uebernehmen()}: prüft neu gegen den Stand jetzt und schreibt alles
 *   in **einer** Transaktion. Nie still: Die Zuordnung der Kunden kommt immer
 *   aus dem Formular; Stammdaten eines Prüflings, die hier anders stehen,
 *   werden nur mit Haken ersetzt – und nur, wenn sie noch so stehen, wie die
 *   Vorschau sie zeigte (`$ersetzen`: Stand je Prüfling, wie `$gesehen` im
 *   KatalogImport). Prüfungen sind unveränderlich: schon da bleibt da.
 *
 * Zwei gleichzeitige Übernahmen (Doppelklick, zweiter Reiter) hält eine
 * nicht blockierende Sperre auseinander ({@see gesperrt()}).
 */
final class GeraeteImport
{
    public const VORSCHAU_DAUER = 3600;

    /** Zustand eines Prüflings in der Vorschau → [Text, Abzeichen]. */
    public const ZUSTAENDE = [
        'neu'    => ['neu', 'info'],
        'gleich' => ['schon da', 'ok'],
        'anders' => ['hier anders', 'warn'],
        'fehler' => ['Fehler', 'bad'],
    ];

    /** Stammdaten, die hier gepflegt werden und deshalb verglichen werden → Beschriftung. */
    public const VERGLICHEN = [
        'bezeichnung' => 'Bezeichnung', 'hersteller' => 'Hersteller', 'typ' => 'Typ', 'modell' => 'Modell',
        'seriennummer' => 'Seriennummer', 'abteilung' => 'Abteilung', 'gebaeude' => 'Gebäude', 'bemerkung' => 'Bemerkung',
        'schutzklasse' => 'Schutzklasse', 'pruefablauf' => 'Prüfablauf', 'intervall_monate' => 'Prüfintervall (Monate)',
        'leitungslaenge' => 'Leitungslänge (m)', 'querschnitt' => 'Querschnitt (mm²)', 'leiter' => 'Leiter',
        'nennleistung' => 'Nennleistung', 'ausser_betrieb' => 'außer Betrieb',
    ];

    /** Felder eines Prüflings, die der Import aus dem Gerät schreibt (neu oder ersetzt). */
    private const STAMMDATEN = ['bezeichnung', 'hersteller', 'typ', 'modell', 'seriennummer', 'abteilung', 'gebaeude', 'bemerkung',
        'schutzklasse', 'intervall_monate', 'leitungslaenge', 'querschnitt', 'leiter', 'nennleistung', 'ausser_betrieb'];

    /** Kontaktfelder eines Kunden (Normalform = Spalte in kunden) – bei vorhandenen Kunden nur leere gefüllt. */
    private const KONTAKT = ['strasse', 'plz', 'ort', 'telefon', 'fax', 'email', 'ansprechpartner', 'ansprechpartner_abteilung',
        'ansprechpartner_telefon', 'ansprechpartner_email'];

    /** Sitzungsschlüssel der Vorschau (Kennung und Dateiname). */
    public const SITZUNG = 'geraetepruefung_import';

    /**
     * Was der angemeldete Benutzer beim Übernehmen außerdem darf: Kunden aus
     * dem Gerät anlegen und leere Kontaktfelder füllen (Kunden pflegen),
     * Inventarstücke verknüpfen (Datenquellen ansehen – wie beim Prüfnachweis).
     *
     * @return array{neu_kunde:bool,kunden_ergaenzen:bool,inventar:bool}
     */
    public static function erlaubt(): array
    {
        return [
            'neu_kunde'        => Auth::can('kunden.manage'),
            'kunden_ergaenzen' => Auth::can('kunden.manage'),
            'inventar'         => Auth::can('datenquellen.view'),
        ];
    }

    /**
     * Eine Datei lesen: Normalform plus (wenn möglich) die bereinigte Vorlage.
     *
     * @return array{ok:bool,message:string,befunde?:list<string>,paket?:array<string,mixed>}
     */
    public static function lesen(string $pfad, string $dateiname): array
    {
        $datei = BenningDatei::lesen($pfad, $dateiname);
        if (!$datei['ok']) {
            return ['ok' => false, 'message' => (string) $datei['message'], 'befunde' => $datei['befunde'] ?? []];
        }
        $paket = $datei;
        unset($paket['ok'], $paket['message']);
        $paket['vorlage_daten'] = null;
        if ($paket['vorlage']['moeglich']) {
            $v = BenningVorlage::bereinigen($pfad);
            if ($v['ok']) {
                $paket['vorlage_daten'] = ['bytes' => base64_encode($v['bytes']), 'sha256' => $v['sha256'], 'groesse' => $v['groesse'],
                    'einstellungen' => $v['einstellungen'], 'db_version' => $v['db_version']];
            } else {
                $paket['vorlage'] = ['moeglich' => false, 'grund' => $v['grund']];
            }
        }
        $paket['zuordnung'] = null;
        return ['ok' => true, 'message' => '', 'paket' => $paket];
    }

    /**
     * Die Vorschau: Abgleich des Pakets mit dem Bestand unter der Zuordnung
     * $zuordnung (Kunden-Ref im Gerät → 'eigen' | 'k<id>' | 'neu' | 'nicht');
     * ohne Zuordnung die im Paket gemerkte, sonst die Vorschläge.
     *
     * @param array<string,mixed> $paket
     * @param array<string,string>|null $zuordnung
     * @param array{neu_kunde:bool,kunden_ergaenzen:bool,inventar:bool}|null $erlaubt
     * @return array<string,mixed>
     */
    public static function pruefen(array $paket, ?array $zuordnung = null, ?array $erlaubt = null): array
    {
        $erlaubt ??= self::erlaubt();
        if (($paket['format'] ?? null) !== BenningDatei::FORMAT || !is_array($paket['kunden'] ?? null) || !is_array($paket['geraete'] ?? null)
            || !is_array($paket['pruefungen'] ?? null)) {
            return ['ok' => false, 'message' => 'Die Vorschau ist unvollständig – bitte die Datei noch einmal lesen.'];
        }
        $hinweise = [];
        $kundenHier = [];
        foreach (Database::select('SELECT id, name, kundennummer FROM kunden') as $k) {
            $kundenHier[(int) $k['id']] = $k;
        }
        $gemerkt = [];
        foreach (Database::select('SELECT schluessel, kunde_id FROM gp_kunden_zuordnung') as $z) {
            $gemerkt[(string) $z['schluessel']] = $z['kunde_id'] === null ? null : (int) $z['kunde_id'];
        }

        // ------------------------------------------------ Kunden und Zuordnung
        $zuordnung ??= is_array($paket['zuordnung'] ?? null) ? $paket['zuordnung'] : null;
        $kunden = [];
        $wahl = [];
        foreach ($paket['kunden'] as $k) {
            $ref = (string) $k['ref'];
            $vorschlag = self::vorschlag($k, $kundenHier, $gemerkt);
            $w = $zuordnung === null ? $vorschlag['wahl'] : (string) ($zuordnung[$ref] ?? 'nicht');
            if (!self::wahlGueltig($w, $kundenHier) || ($w === 'neu' && !$erlaubt['neu_kunde'])) {
                $w = 'nicht';
            }
            $wahl[$ref] = $w;
            $kunden[$ref] = $k + ['vorschlag' => $vorschlag['wahl'], 'vorschlag_grund' => $vorschlag['grund'], 'wahl' => $w,
                'prueflinge_neu' => 0, 'prueflinge_da' => 0, 'pruefungen_neu' => 0, 'pruefungen_da' => 0];
        }

        // ------------------------------------------------ Geräte nach Besitzer und Kennung
        $pruefungenJe = [];
        foreach ($paket['pruefungen'] as $p) {
            $pruefungenJe[(string) $p['geraet_ref']][] = $p;
        }
        $individuell = [];
        foreach ((array) ($paket['pruefablaeufe'] ?? []) as $a) {
            if (($a['individuell'] ?? false) === true) {
                $individuell[(string) $a['geraet_id']] = true;
            }
        }
        $gruppen = [];
        $uebergangen = ['geraete' => 0, 'pruefungen' => 0];
        foreach ($paket['geraete'] as $g) {
            $w = $wahl[(string) $g['kunde_ref']] ?? 'nicht';
            if ($w === 'nicht') {
                $uebergangen['geraete']++;
                $uebergangen['pruefungen'] += count($pruefungenJe[(string) $g['ref']] ?? []);
                continue;
            }
            $schluessel = match (true) {
                $w === 'eigen' => Prueflinge::eindeutig(null, (string) $g['kennung']),
                $w === 'neu'   => 'n' . $g['kunde_ref'] . ':' . mb_strtolower(Prueflinge::kennung((string) $g['kennung']), 'UTF-8'),
                default        => Prueflinge::eindeutig((int) substr($w, 1), (string) $g['kennung']),
            };
            $gruppen[$schluessel]['wahl'] = $w;
            $gruppen[$schluessel]['kunde_ref'] = (string) $g['kunde_ref'];
            $gruppen[$schluessel]['geraete'][] = $g;
        }

        // ------------------------------------------------ Bestand
        $besitzer = [];
        foreach ($gruppen as $gr) {
            if ($gr['wahl'] === 'eigen') {
                $besitzer['eigen'] = true;
            } elseif ($gr['wahl'] !== 'neu') {
                $besitzer[(int) substr($gr['wahl'], 1)] = true;
            }
        }
        $hier = [];
        $ids = array_filter(array_keys($besitzer), 'is_int');
        $bedingungen = [];
        if (isset($besitzer['eigen'])) {
            $bedingungen[] = 'kunde_id IS NULL';
        }
        if ($ids !== []) {
            $bedingungen[] = 'kunde_id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }
        if ($bedingungen !== []) {
            foreach (Database::select('SELECT * FROM gp_prueflinge WHERE ' . implode(' OR ', $bedingungen)) as $z) {
                $hier[(string) $z['eindeutig']] = $z;
            }
        }
        $ablaufGeraet = [];
        $ablaufName = [];
        foreach (Database::select('SELECT id, geraet_id, name FROM gp_pruefablaeufe') as $a) {
            $ablaufGeraet[(int) $a['id']] = (string) $a['geraet_id'];
            $ablaufName[(string) $a['geraet_id']] = (string) $a['name'];
        }
        foreach ((array) ($paket['pruefablaeufe'] ?? []) as $a) {
            $ablaufName[(string) $a['geraet_id']] ??= (string) $a['name'];
        }
        $inventar = [];
        if ($erlaubt['inventar'] && isset($besitzer['eigen'])) {
            $verknuepft = array_flip(array_map('intval', array_column(Database::select('SELECT inventar_id FROM gp_prueflinge WHERE inventar_id IS NOT NULL'), 'inventar_id')));
            $doppelt = [];
            foreach (Database::select("SELECT id, kennung FROM inventar WHERE art <> 'tanksensor'") as $i) {
                $k = mb_strtolower(trim((string) $i['kennung']), 'UTF-8');
                if ($k === '' || isset($verknuepft[(int) $i['id']])) {
                    continue;
                }
                if (isset($inventar[$k])) {
                    $doppelt[$k] = true;
                } else {
                    $inventar[$k] = ['id' => (int) $i['id'], 'kennung' => (string) $i['kennung']];
                }
            }
            $inventar = array_diff_key($inventar, $doppelt);
        }
        $schonDa = self::vorhandeneSchluessel(array_map(static fn (array $p): string => (string) $p['schluessel'], $paket['pruefungen']));

        // ------------------------------------------------ Prüflinge
        $zeilen = [];
        $zahlen = ['prueflinge_neu' => 0, 'prueflinge_gleich' => 0, 'prueflinge_anders' => 0, 'prueflinge_fehler' => 0,
            'pruefungen_neu' => 0, 'pruefungen_da' => 0, 'pruefungen_uebergangen' => $uebergangen['pruefungen'], 'geraete_uebergangen' => $uebergangen['geraete']];
        $gesehen = [];
        foreach ($gruppen as $schluessel => $gr) {
            $schluessel = (string) $schluessel;
            $pruefungen = [];
            $juengste = null;
            $stamm = null;
            foreach ($gr['geraete'] as $g) {
                foreach ($pruefungenJe[(string) $g['ref']] ?? [] as $p) {
                    $pruefungen[] = $p;
                    if ($juengste === null || strcmp((string) $p['geprueft_am'], $juengste) > 0) {
                        $juengste = (string) $p['geprueft_am'];
                        $stamm = $g;
                    }
                }
            }
            if ($stamm === null) {
                $stamm = $gr['geraete'][0];
                foreach ($gr['geraete'] as $g) {
                    if ((int) $g['ref'] > (int) $stamm['ref']) {
                        $stamm = $g;
                    }
                }
            }
            usort($pruefungen, static fn (array $a, array $b): int => strcmp((string) $a['geprueft_am'], (string) $b['geprueft_am']) ?: ((int) $a['ref'] <=> (int) $b['ref']));
            $zeile = [
                'schluessel'  => $schluessel,
                'form'        => substr(hash('sha256', $schluessel), 0, 32),
                'besitzer'    => $gr['wahl'],
                'kunde_ref'   => $gr['kunde_ref'],
                'kennung'     => (string) $stamm['kennung'],
                'bezeichnung' => (string) $stamm['bezeichnung'],
                'zustand'     => 'neu',
                'fehler'      => null,
                'id'          => null,
                'stand'       => null,
                'unterschiede' => [],
                'abweichungen' => [],
                'inventar'    => null,
                'hinweise'    => [],
                'daten'       => self::daten($stamm, isset($individuell[(string) $stamm['ablauf_geraet_id']])),
                'ablauf_geraet_id' => (string) $stamm['ablauf_geraet_id'],
                'geraete'     => array_map(static fn (array $g): string => (string) $g['ref'], $gr['geraete']),
                'pruefungen'  => $pruefungen,
                'pruefungen_neu' => 0,
                'pruefungen_da'  => 0,
            ];
            if (count($gr['geraete']) > 1) {
                $zeile['hinweise'][] = 'Die Kennung kommt im Prüfgerät ' . count($gr['geraete']) . '-mal vor – ein Prüfling, Stammdaten vom Gerät mit der jüngsten Prüfung.';
            }
            if ($zeile['ablauf_geraet_id'] !== '' && !isset($ablaufName[$zeile['ablauf_geraet_id']])) {
                $zeile['hinweise'][] = 'Den Prüfablauf ' . $zeile['ablauf_geraet_id'] . ' kennt weder die Datei noch PowerManagement – der Prüfling bleibt ohne Prüfablauf.';
            }
            $fehler = self::fehlerIn($zeile['daten']);
            $vorhanden = $gr['wahl'] === 'neu' ? null : ($hier[$schluessel] ?? null);
            if ($fehler !== null) {
                $zeile['zustand'] = 'fehler';
                $zeile['fehler'] = $fehler;
            } elseif ($vorhanden !== null) {
                $zeile['id'] = (int) $vorhanden['id'];
                $hierWerte = self::vergleichswerte($vorhanden, $ablaufGeraet);
                $zeile['stand'] = Kanon::hash(['id' => (int) $vorhanden['id']] + $hierWerte);
                $dateiWerte = self::dateiwerte($zeile['daten'], $zeile['ablauf_geraet_id']);
                foreach (self::VERGLICHEN as $feld => $text) {
                    if (!self::gleich($feld, $hierWerte[$feld], $dateiWerte[$feld])) {
                        $zeile['unterschiede'][] = $text;
                        $zeile['abweichungen'][] = ['feld' => $text, 'hier' => self::anzeige($feld, $hierWerte[$feld], $ablaufName),
                            'datei' => self::anzeige($feld, $dateiWerte[$feld], $ablaufName)];
                    }
                }
                $zeile['zustand'] = $zeile['unterschiede'] === [] ? 'gleich' : 'anders';
            }
            if ($zeile['zustand'] !== 'fehler' && $gr['wahl'] === 'eigen' && ($vorhanden === null || $vorhanden['inventar_id'] === null)) {
                $treffer = $inventar[mb_strtolower($zeile['kennung'], 'UTF-8')] ?? null;
                if ($treffer !== null) {
                    $zeile['inventar'] = $treffer;
                }
            }
            if ($zeile['zustand'] !== 'fehler') {
                foreach ($pruefungen as $p) {
                    $s = (string) $p['schluessel'];
                    if (isset($schonDa[$s]) || isset($gesehen[$s])) {
                        $zeile['pruefungen_da']++;
                    } else {
                        $zeile['pruefungen_neu']++;
                        $gesehen[$s] = true;
                    }
                }
            } else {
                $zahlen['pruefungen_uebergangen'] += count($pruefungen);
            }
            $zahlen['prueflinge_' . $zeile['zustand']]++;
            $zahlen['pruefungen_neu'] += $zeile['pruefungen_neu'];
            $zahlen['pruefungen_da'] += $zeile['pruefungen_da'];
            $k = &$kunden[$gr['kunde_ref']];
            $k[$zeile['zustand'] === 'neu' ? 'prueflinge_neu' : 'prueflinge_da']++;
            $k['pruefungen_neu'] += $zeile['pruefungen_neu'];
            $k['pruefungen_da'] += $zeile['pruefungen_da'];
            unset($k);
            $zeilen[] = $zeile;
        }
        usort($zeilen, static fn (array $a, array $b): int => [$a['kunde_ref'] === '' ? 0 : (int) $a['kunde_ref'], self::rang($a['zustand']), $a['kennung']]
            <=> [$b['kunde_ref'] === '' ? 0 : (int) $b['kunde_ref'], self::rang($b['zustand']), $b['kennung']]);

        // ------------------------------------------------ Vorlage
        $moeglich = ($paket['vorlage']['moeglich'] ?? false) === true && is_array($paket['vorlage_daten'] ?? null);
        $aktiv = BenningVorlage::aktiv(true);
        $gleichAktiv = false;
        if ($moeglich && $aktiv !== null) {
            $bytes = BenningVorlage::bytes($aktiv);
            $gleichAktiv = hash_equals((string) $aktiv['sha256'], (string) $paket['vorlage_daten']['sha256'])
                || ($bytes !== null && hash_equals((string) BenningVorlage::einstellungenAus($bytes), (string) $paket['vorlage_daten']['einstellungen']));
        }

        $zuordnungJetzt = [];
        foreach ($kunden as $ref => $k) {
            $zuordnungJetzt[(string) $ref] = $k['wahl'];
        }
        return [
            'ok'         => true,
            'message'    => '',
            'quelle'     => $paket['quelle'],
            'kunden'     => array_values($kunden),
            'prueflinge' => $zeilen,
            'zahlen'     => $zahlen + ['kunden' => count($kunden), 'geraete' => count($paket['geraete']), 'pruefungen' => count($paket['pruefungen'])],
            'vorlage'    => [
                'moeglich'   => $moeglich,
                'grund'      => (string) ($paket['vorlage']['grund'] ?? ''),
                'aktiv'      => $aktiv !== null,
                'gleich'     => $gleichAktiv,
                'vorauswahl' => $moeglich && ($aktiv === null || !$gleichAktiv),
            ],
            'pruefablaeufe' => count((array) ($paket['pruefablaeufe'] ?? [])),
            'zuordnung'  => $zuordnungJetzt,
            'zuordnung_stand' => self::zuordnungStand($zuordnungJetzt),
            'kunden_hier' => array_map(static fn (array $k): array => ['id' => (int) $k['id'], 'name' => (string) $k['name'],
                'kundennummer' => (string) $k['kundennummer']], array_values($kundenHier)),
            'hinweise'   => array_merge((array) ($paket['hinweise'] ?? []), $hinweise),
            'erlaubt'    => $erlaubt,
        ];
    }

    /** Fingerabdruck einer Zuordnung (Kunden-Ref → Wahl), unabhängig von der Reihenfolge. */
    public static function zuordnungStand(array $zuordnung): string
    {
        $z = [];
        foreach ($zuordnung as $ref => $wahl) {
            $z[] = [(string) $ref, (string) $wahl];
        }
        usort($z, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        return Kanon::hash($z);
    }

    /**
     * Die Zuordnung aus dem Formular, bereinigt: nur Kunden-Refs des Pakets,
     * nur bekannte Wahlen; fehlende → 'nicht'.
     *
     * @param array<string,mixed> $paket
     * @return array<string,string>
     */
    public static function zuordnungAus(array $paket, mixed $eingabe): array
    {
        $eingabe = is_array($eingabe) ? $eingabe : [];
        $aus = [];
        foreach ((array) ($paket['kunden'] ?? []) as $k) {
            $ref = (string) $k['ref'];
            $w = is_scalar($eingabe[$ref] ?? null) ? trim((string) $eingabe[$ref]) : 'nicht';
            $aus[$ref] = preg_match('/^(?:eigen|nicht|neu|k\d{1,9})$/', $w) === 1 ? $w : 'nicht';
        }
        return $aus;
    }

    /**
     * Übernehmen, was die Vorschau zeigte – gegen den Stand jetzt geprüft, in
     * einer Transaktion.
     *
     * @param array<string,mixed> $paket
     * @param array<string,string> $zuordnung Kunden-Ref → eigen | k<id> | neu | nicht
     * @param array<string,string> $ersetzen Formular-Schlüssel eines Prüflings → Stand, den die Vorschau zeigte
     * @param array<string,string> $inventar Formular-Schlüssel → Inventar-ID (Vorschlag angehakt)
     * @param bool $vorlage die Einstellungen des Geräts als Vorlage übernehmen
     * @param array{neu_kunde:bool,kunden_ergaenzen:bool,inventar:bool}|null $erlaubt
     * @return array{ok:bool,message:string,nichts?:bool,wartung?:bool,zahlen?:array<string,int>,import_id?:int}
     */
    public static function uebernehmen(array $paket, array $zuordnung, array $ersetzen, array $inventar, bool $vorlage, ?array $erlaubt = null): array
    {
        $erlaubt ??= self::erlaubt();
        $kundenHier = [];
        foreach (Database::select('SELECT id, name, kundennummer FROM kunden') as $k) {
            $kundenHier[(int) $k['id']] = $k;
        }
        foreach ((array) ($paket['kunden'] ?? []) as $k) {
            $w = (string) ($zuordnung[(string) $k['ref']] ?? 'nicht');
            if (!self::wahlGueltig($w, $kundenHier)) {
                return ['ok' => false, 'message' => 'Diese Zuordnung gibt es nicht (der Kunde ist inzwischen gelöscht?) – bitte neu wählen.'];
            }
            if ($w === 'neu' && !$erlaubt['neu_kunde']) {
                return ['ok' => false, 'message' => 'Kunden aus dem Prüfgerät anlegen darf nur, wer Kunden pflegt – bitte einen vorhandenen Kunden wählen.'];
            }
        }
        $zuordnung = self::zuordnungAus($paket, $zuordnung);
        return self::gesperrt(function () use ($paket, $zuordnung, $ersetzen, $inventar, $vorlage, $erlaubt): array {
            // Angemeldet ist die Anfrage schon an der Tür (UpdateService::wartungsSperre);
            // hier wird nur gelesen und gerechnet, ohne Netz – aber eine große Datei kann
            // dauern. Unmittelbar vor dem Schreiben also noch einmal fragen.
            if (UpdateService::wartungAktiv()) {
                return ['ok' => false, 'wartung' => true, 'message' => UpdateService::GESPERRT];
            }
            try {
                $zahlen = Database::transaktion(fn (): array => self::anwenden($paket, $zuordnung, $ersetzen, $inventar, $vorlage, $erlaubt));
            } catch (\UnderflowException) {
                return ['ok' => true, 'nichts' => true, 'message' => 'Nichts übernommen – alles stand schon so da, oder es war nichts ausgewählt.'];
            } catch (\DomainException|\InvalidArgumentException $e) {
                return ['ok' => false, 'message' => 'Nichts übernommen – ' . $e->getMessage()];
            } catch (\PDOException $e) {
                error_log('Geräteprüfung, Import: ' . $e->getMessage());
                return ['ok' => false, 'message' => 'Nichts übernommen – beim Speichern ging etwas schief (zwei Kennungen, die sich nur im Akzent unterscheiden?).'];
            }
            return ['ok' => true, 'message' => self::meldung($zahlen), 'zahlen' => $zahlen, 'import_id' => $zahlen['import_id']];
        });
    }

    /**
     * Das eigentliche Übernehmen (in der Transaktion von {@see uebernehmen()}).
     *
     * @return array<string,int>
     */
    private static function anwenden(array $paket, array $zuordnung, array $ersetzen, array $inventar, bool $vorlageGewaehlt, array $erlaubt): array
    {
        $stand = self::pruefen($paket, $zuordnung, $erlaubt);
        if (!$stand['ok']) {
            throw new \DomainException((string) $stand['message']);
        }
        $vorlage = $vorlageGewaehlt && $stand['vorlage']['moeglich'];
        $z = ['import_id' => 0, 'kunden_neu' => 0, 'kunden_ergaenzt' => 0, 'prueflinge_neu' => 0, 'prueflinge_ersetzt' => 0,
            'prueflinge_gleich' => 0, 'prueflinge_behalten' => 0, 'prueflinge_veraendert' => 0, 'prueflinge_fehler' => 0,
            'prueflinge_technik' => 0, 'inventar_verknuepft' => 0, 'pruefungen_neu' => 0, 'pruefungen_da' => 0,
            'pruefungen_uebergangen' => (int) $stand['zahlen']['pruefungen_uebergangen'], 'ablaeufe_neu' => 0, 'ablaeufe_geaendert' => 0,
            'ablaeufe_entfallen' => 0, 'vorlage' => 0];

        $abgleich = BenningVorlage::ablaeufeAbgleichen((array) ($paket['pruefablaeufe'] ?? []), $vorlage);
        $z['ablaeufe_neu'] = $abgleich['neu'];
        $z['ablaeufe_geaendert'] = $abgleich['geaendert'];
        $z['ablaeufe_entfallen'] = $abgleich['entfallen'];
        $ablaufId = [];
        foreach (Database::select('SELECT id, geraet_id FROM gp_pruefablaeufe') as $a) {
            $ablaufId[(string) $a['geraet_id']] = (int) $a['id'];
        }
        $quelle = (array) $paket['quelle'];
        $importId = Database::insert('gp_importe', [
            'sha256'        => (string) $quelle['sha256'],
            'dateiname'     => Messarten::text($quelle['dateiname'] ?? '', 120),
            'groesse'       => (int) ($quelle['groesse'] ?? 0),
            'messgeraet'    => Messarten::text($quelle['messgeraet'] ?? '', 80),
            'messgeraet_sn' => Messarten::text($quelle['messgeraet_sn'] ?? '', 80),
            'db_version'    => Messarten::text($quelle['db_version'] ?? '', 20),
            'zahlen'        => null,
            'created_by'    => Auth::id(),
            'created_at'    => gmdate('c'),
        ]);
        $z['import_id'] = $importId;

        // ------------------------------------------------ Kunden
        $kundeId = [];
        foreach ($stand['kunden'] as $k) {
            $ref = (string) $k['ref'];
            if ($k['wahl'] === 'neu') {
                $kundeId[$ref] = self::kundeAnlegen($k);
                $z['kunden_neu']++;
            } elseif (str_starts_with((string) $k['wahl'], 'k')) {
                $kundeId[$ref] = (int) substr((string) $k['wahl'], 1);
                if ($erlaubt['kunden_ergaenzen'] && self::kundeErgaenzen($kundeId[$ref], $k)) {
                    $z['kunden_ergaenzt']++;
                }
            } elseif ($k['wahl'] === 'eigen') {
                $kundeId[$ref] = null;
            }
        }

        // ------------------------------------------------ Prüflinge und Prüfungen
        $beruehrt = [];
        foreach ($stand['prueflinge'] as $zeile) {
            if ($zeile['zustand'] === 'fehler') {
                $z['prueflinge_fehler']++;
                continue;
            }
            $besitzer = $kundeId[(string) $zeile['kunde_ref']] ?? null;
            $daten = $zeile['daten'];
            $daten['pruefablauf_id'] = $zeile['ablauf_geraet_id'] !== '' ? ($ablaufId[$zeile['ablauf_geraet_id']] ?? null) : null;
            $inventarId = null;
            if ($zeile['inventar'] !== null && $erlaubt['inventar'] && $besitzer === null && $zeile['besitzer'] === 'eigen'
                && (string) ($inventar[$zeile['form']] ?? '') === (string) $zeile['inventar']['id'] && self::inventarFrei((int) $zeile['inventar']['id'])) {
                $inventarId = (int) $zeile['inventar']['id'];
            }
            if ($zeile['zustand'] === 'neu') {
                $neu = $daten + ['kunde_id' => $besitzer, 'inventar_id' => $inventarId];
                $neu['letzte_pruefung'] = $daten['letzte_pruefung'] !== '' ? $daten['letzte_pruefung'] : null;
                $neu['naechste_pruefung'] = $daten['naechste_pruefung'] !== '' ? $daten['naechste_pruefung'] : null;
                $id = Prueflinge::anlegen($neu);
                $z['prueflinge_neu']++;
                $z['inventar_verknuepft'] += $inventarId !== null ? 1 : 0;
            } else {
                $id = (int) $zeile['id'];
                $aenderung = [];
                if ($zeile['zustand'] === 'anders') {
                    $gewaehlt = $ersetzen[$zeile['form']] ?? null;
                    if (is_string($gewaehlt) && hash_equals((string) $zeile['stand'], $gewaehlt)) {
                        foreach (self::STAMMDATEN as $feld) {
                            $aenderung[$feld] = $daten[$feld];
                        }
                        $aenderung['pruefablauf_id'] = $daten['pruefablauf_id'];
                        $z['prueflinge_ersetzt']++;
                    } elseif ($gewaehlt !== null) {
                        $z['prueflinge_veraendert']++;
                    } else {
                        $z['prueflinge_behalten']++;
                    }
                } else {
                    $z['prueflinge_gleich']++;
                }
                $vorher = Prueflinge::find($id);
                if ($vorher !== null && self::technikText($vorher['technik']) !== self::technikText($daten['technik'])) {
                    $aenderung['technik'] = $daten['technik'];
                    $z['prueflinge_technik']++;
                }
                if ($inventarId !== null && $vorher !== null && $vorher['inventar_id'] === null) {
                    $aenderung['inventar_id'] = $inventarId;
                    $z['inventar_verknuepft']++;
                }
                if ($aenderung !== []) {
                    Prueflinge::aendern($id, $aenderung);
                    if (array_key_exists('intervall_monate', $aenderung)) {
                        $beruehrt[$id] = true;
                    }
                }
            }
            foreach ($zeile['pruefungen'] as $p) {
                $r = Pruefungen::anlegen($id, $p, $importId);
                if ($r['neu']) {
                    $z['pruefungen_neu']++;
                    $beruehrt[$id] = true;
                } else {
                    $z['pruefungen_da']++;
                }
            }
        }
        foreach (array_keys($beruehrt) as $id) {
            Prueflinge::faelligkeitNeu((int) $id);
        }

        // ------------------------------------------------ Vorlage, Zuordnung, Beleg
        if ($vorlage) {
            $v = (array) $paket['vorlage_daten'];
            $bytes = base64_decode((string) $v['bytes'], true);
            if (!is_string($bytes) || !hash_equals((string) $v['sha256'], hash('sha256', $bytes))) {
                throw new \DomainException('die Vorlage in der Vorschau ist beschädigt – bitte die Datei noch einmal lesen.');
            }
            $aktivVorher = BenningVorlage::aktiv();
            $gespeichert = BenningVorlage::speichern($bytes, ['messgeraet' => $quelle['messgeraet'] ?? '', 'messgeraet_sn' => $quelle['messgeraet_sn'] ?? '',
                'db_version' => $v['db_version'] ?? ''], $importId);
            $z['vorlage'] = $aktivVorher !== null && (int) $aktivVorher['id'] === $gespeichert['id'] ? 0 : 1;
        }
        foreach ($stand['kunden'] as $k) {
            $ref = (string) $k['ref'];
            if ($k['wahl'] === 'nicht' || !array_key_exists($ref, $kundeId) || BenningVorlage::reserviert((string) $k['kundennummer'])) {
                continue;
            }
            self::merken(self::zuordnungSchluessel($k), $kundeId[$ref]);
        }
        $aenderungen = $z['kunden_neu'] + $z['kunden_ergaenzt'] + $z['prueflinge_neu'] + $z['prueflinge_ersetzt'] + $z['prueflinge_technik']
            + $z['inventar_verknuepft'] + $z['pruefungen_neu'] + $z['ablaeufe_neu'] + $z['ablaeufe_geaendert'] + $z['ablaeufe_entfallen'] + $z['vorlage'];
        if ($aenderungen === 0) {
            // Nichts Neues: auch kein Beleg – die Transaktion rollt zurück.
            throw new \UnderflowException('nichts');
        }
        $nurZahlen = array_diff_key($z, ['import_id' => true]);
        Database::update('gp_importe', $importId, ['zahlen' => json_encode($nurZahlen)]);
        Audit::log('geraetepruefung.import', 'gp_import', (string) $importId, $nurZahlen);
        return $z;
    }

    // ------------------------------------------------------------ Vorschau ablegen

    /** Ein gelesenes Paket ablegen; gibt die Kennung (32 hex) zurück. */
    public static function ablegen(array $paket): string
    {
        $kennung = bin2hex(random_bytes(16));
        self::schreiben($kennung, $paket);
        return $kennung;
    }

    /** Ein abgelegtes Paket ersetzen (etwa mit einer neuen Zuordnung); false, wenn es weg ist. */
    public static function aktualisieren(string $kennung, array $paket): bool
    {
        $pfad = self::ablage($kennung);
        if ($pfad === null || !is_file($pfad)) {
            return false;
        }
        self::schreiben($kennung, $paket);
        return true;
    }

    /** Das abgelegte Paket zur Kennung (nur 32 hex, nie ein Pfad); null, wenn weg oder älter als eine Stunde. */
    public static function abgelegt(string $kennung): ?array
    {
        $pfad = self::ablage($kennung);
        if ($pfad === null || !is_file($pfad)) {
            return null;
        }
        if (time() - (int) filemtime($pfad) > self::VORSCHAU_DAUER) {
            @unlink($pfad);
            return null;
        }
        $paket = json_decode((string) file_get_contents($pfad), true);
        return is_array($paket) ? $paket : null;
    }

    public static function verwerfen(string $kennung): void
    {
        $pfad = self::ablage($kennung);
        if ($pfad !== null && is_file($pfad)) {
            @unlink($pfad);
        }
    }

    /**
     * Für `bin/power wartung`: abgelaufene Vorschauen und liegengebliebene
     * Kopien von Gerätedatenbanken (abgebrochenes Lesen, Vorlage, Export) weg.
     */
    public static function aufraeumen(?int $jetzt = null): int
    {
        $jetzt ??= time();
        $weg = 0;
        $ordner = APP_ROOT . '/storage/cache';
        foreach ([...(glob($ordner . '/gp-import-*.json') ?: []), ...(glob($ordner . '/gp-*.db') ?: []), ...(glob($ordner . '/gp-*.db-journal') ?: [])] as $pfad) {
            if (is_file($pfad) && $jetzt - (int) filemtime($pfad) > self::VORSCHAU_DAUER && @unlink($pfad)) {
                $weg++;
            }
        }
        return $weg;
    }

    /**
     * Die letzten Importe (nur Eckdaten und Zahlen).
     *
     * @return list<array<string,mixed>>
     */
    public static function letzte(int $anzahl = 5): array
    {
        $aus = [];
        foreach (Database::select('SELECT i.id, i.dateiname, i.groesse, i.messgeraet, i.messgeraet_sn, i.db_version, i.zahlen, i.created_at,
                u.username FROM gp_importe i LEFT JOIN users u ON u.id = i.created_by ORDER BY i.id DESC LIMIT ' . max(1, min(100, $anzahl))) as $z) {
            $zahlen = json_decode((string) $z['zahlen'], true);
            $z['zahlen'] = is_array($zahlen) ? array_map('intval', $zahlen) : [];
            $z['id'] = (int) $z['id'];
            $aus[] = $z;
        }
        return $aus;
    }

    /** Text einer Meldung aus den Zahlen eines Imports. */
    public static function meldung(array $z): string
    {
        $mz = static fn (int $n, string $eins, string $viele): string => $n . ' ' . ($n === 1 ? $eins : $viele);
        $teile = [];
        if ($z['kunden_neu'] > 0) {
            $teile[] = $mz($z['kunden_neu'], 'Kunde angelegt', 'Kunden angelegt');
        }
        if ($z['kunden_ergaenzt'] > 0) {
            $teile[] = $mz($z['kunden_ergaenzt'], 'Kunde ergänzt', 'Kunden ergänzt');
        }
        $teile[] = $mz($z['prueflinge_neu'], 'Prüfling neu', 'Prüflinge neu');
        if ($z['prueflinge_ersetzt'] > 0) {
            $teile[] = $mz($z['prueflinge_ersetzt'], 'Prüfling ersetzt', 'Prüflinge ersetzt');
        }
        if ($z['prueflinge_behalten'] > 0) {
            $teile[] = $mz($z['prueflinge_behalten'], 'Prüfling hier anders, bleibt', 'Prüflinge hier anders, bleiben');
        }
        if ($z['prueflinge_veraendert'] > 0) {
            $teile[] = $mz($z['prueflinge_veraendert'], 'Prüfling seit der Vorschau geändert, bleibt', 'Prüflinge seit der Vorschau geändert, bleiben');
        }
        if ($z['inventar_verknuepft'] > 0) {
            $teile[] = $mz($z['inventar_verknuepft'], 'Inventarstück verknüpft', 'Inventarstücke verknüpft');
        }
        $teile[] = $mz($z['pruefungen_neu'], 'Prüfung neu', 'Prüfungen neu');
        if ($z['pruefungen_da'] > 0) {
            $teile[] = $z['pruefungen_da'] . ' schon da';
        }
        if ($z['pruefungen_uebergangen'] > 0) {
            $teile[] = $mz($z['pruefungen_uebergangen'], 'Prüfung übergangen', 'Prüfungen übergangen');
        }
        if ($z['ablaeufe_neu'] + $z['ablaeufe_geaendert'] + $z['ablaeufe_entfallen'] > 0) {
            $teile[] = 'Prüfabläufe ' . $z['ablaeufe_neu'] . ' neu, ' . $z['ablaeufe_geaendert'] . ' geändert, ' . $z['ablaeufe_entfallen'] . ' ungültig';
        }
        if ($z['vorlage'] > 0) {
            $teile[] = 'Einstellungen des Geräts als Vorlage übernommen';
        }
        return 'Import übernommen: ' . implode('; ', $teile) . '.';
    }

    // ------------------------------------------------------------ intern

    /**
     * Nicht blockierende Sperre gegen ein zweites gleichzeitiges Übernehmen
     * (je Datenbank – Prüfskripte mit eigener Instanz teilen storage/cache).
     *
     * @param callable():array $arbeit
     * @return array<string,mixed>
     */
    public static function gesperrt(callable $arbeit): array
    {
        $griff = @fopen(self::sperrdatei(), 'c');
        if ($griff === false) {
            return ['ok' => false, 'message' => 'storage/cache ist nicht beschreibbar – nichts übernommen.'];
        }
        if (!flock($griff, LOCK_EX | LOCK_NB)) {
            fclose($griff);
            return ['ok' => false, 'message' => 'Gerade wird schon ein Import übernommen – bitte einen Moment warten und die Seite neu laden.'];
        }
        try {
            return $arbeit();
        } finally {
            flock($griff, LOCK_UN);
            fclose($griff);
        }
    }

    /** Pfad der Sperrdatei (je Datenbank eine). */
    public static function sperrdatei(): string
    {
        $datenbank = [];
        foreach (['driver', 'path', 'host', 'port', 'database'] as $k) {
            $datenbank[$k] = Config::get('database.' . $k);
        }
        $ordner = APP_ROOT . '/storage/cache';
        if (!is_dir($ordner)) {
            @mkdir($ordner, 0775, true);
        }
        return $ordner . '/gp-import-' . substr(hash('sha256', (string) json_encode($datenbank)), 0, 24) . '.lock';
    }

    private static function ablage(string $kennung): ?string
    {
        return preg_match('/^[0-9a-f]{32}$/', $kennung) === 1 ? APP_ROOT . '/storage/cache/gp-import-' . $kennung . '.json' : null;
    }

    private static function schreiben(string $kennung, array $paket): void
    {
        $ordner = APP_ROOT . '/storage/cache';
        if (!is_dir($ordner)) {
            mkdir($ordner, 0775, true);
        }
        $pfad = (string) self::ablage($kennung);
        $json = json_encode($paket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($pfad, $json, LOCK_EX) !== strlen($json)) {
            @unlink($pfad);
            throw new \RuntimeException('Die Vorschau ließ sich nicht ablegen (storage/cache voll oder nicht beschreibbar).');
        }
        @chmod($pfad, 0600);
    }

    /**
     * Vorschlag für einen Kunden im Gerät – nie über den Namen allein.
     *
     * @param array<string,mixed> $k
     * @param array<int,array<string,mixed>> $kundenHier
     * @param array<string,?int> $gemerkt
     * @return array{wahl:string,grund:string}
     */
    private static function vorschlag(array $k, array $kundenHier, array $gemerkt): array
    {
        $nummer = trim((string) ($k['kundennummer'] ?? ''));
        if (strcasecmp($nummer, BenningVorlage::EIGEN) === 0) {
            return ['wahl' => 'eigen', 'grund' => 'Kundennummer ' . BenningVorlage::EIGEN . ' (aus PowerManagement geschrieben)'];
        }
        if (preg_match('/^PM-K(\d{1,9})$/i', $nummer, $m) === 1) {
            return isset($kundenHier[(int) $m[1]])
                ? ['wahl' => 'k' . (int) $m[1], 'grund' => 'Kundennummer ' . $nummer . ' (aus PowerManagement geschrieben)']
                : ['wahl' => 'nicht', 'grund' => 'Den Kunden ' . $nummer . ' gibt es hier nicht mehr.'];
        }
        if ($nummer !== '') {
            $treffer = array_values(array_filter($kundenHier, static fn (array $h): bool => (string) $h['kundennummer'] !== ''
                && mb_strtolower(trim((string) $h['kundennummer']), 'UTF-8') === mb_strtolower($nummer, 'UTF-8')));
            if (count($treffer) === 1) {
                return ['wahl' => 'k' . (int) $treffer[0]['id'], 'grund' => 'gleiche Kundennummer'];
            }
        }
        $schluessel = self::zuordnungSchluessel($k);
        if (array_key_exists($schluessel, $gemerkt)) {
            $id = $gemerkt[$schluessel];
            if ($id === null) {
                return ['wahl' => 'eigen', 'grund' => 'beim letzten Import so zugeordnet'];
            }
            if (isset($kundenHier[$id])) {
                return ['wahl' => 'k' . $id, 'grund' => 'beim letzten Import so zugeordnet'];
            }
        }
        return ['wahl' => 'nicht', 'grund' => ''];
    }

    /** Schlüssel der gemerkten Zuordnung: über die Kundennummer im Gerät, ohne sie über den Namen. */
    private static function zuordnungSchluessel(array $k): string
    {
        $nummer = trim((string) ($k['kundennummer'] ?? ''));
        if ($nummer !== '') {
            return hash('sha256', 'nummer:' . mb_strtolower($nummer, 'UTF-8'));
        }
        return hash('sha256', 'name:' . mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($k['name'] ?? ''))), 'UTF-8'));
    }

    private static function merken(string $schluessel, ?int $kundeId): void
    {
        $da = Database::first('SELECT id, kunde_id FROM gp_kunden_zuordnung WHERE schluessel = ?', [$schluessel]);
        if ($da === null) {
            Database::insert('gp_kunden_zuordnung', ['schluessel' => $schluessel, 'kunde_id' => $kundeId, 'created_at' => gmdate('c')]);
        } elseif (($da['kunde_id'] === null ? null : (int) $da['kunde_id']) !== $kundeId) {
            Database::update('gp_kunden_zuordnung', (int) $da['id'], ['kunde_id' => $kundeId, 'updated_at' => gmdate('c')]);
        }
    }

    /** @param array<int,array<string,mixed>> $kundenHier */
    private static function wahlGueltig(string $w, array $kundenHier): bool
    {
        if (in_array($w, ['eigen', 'nicht', 'neu'], true)) {
            return true;
        }
        return preg_match('/^k(\d{1,9})$/', $w, $m) === 1 && isset($kundenHier[(int) $m[1]]);
    }

    /** Sortierung der Prüflinge in der Vorschau: Fehler und „hier anders“ zuerst. */
    private static function rang(string $zustand): int
    {
        return ['fehler' => 0, 'anders' => 1, 'neu' => 2, 'gleich' => 3][$zustand] ?? 4;
    }

    /**
     * Felder eines Prüflings aus einem Gerät der Normalform (ohne Besitzer,
     * Inventar und Prüfablauf-ID).
     *
     * @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    private static function daten(array $g, bool $individuell): array
    {
        $technik = [];
        foreach ((array) ($g['technik'] ?? []) as $k => $v) {
            $technik[(string) $k] = (string) $v;
        }
        $technik['fragen'] = implode(',', array_map('strval', (array) ($g['fragen'] ?? [])));
        if ($individuell && (string) ($g['ablauf_geraet_id'] ?? '') !== '') {
            // Einzelablauf des Geräts: seine eigene Bitmaske für den Export.
            $technik['individuell_id'] = (string) $g['ablauf_geraet_id'];
            $technik['individuell_ablauf'] = (string) ($g['pruefablauf'] ?? '0');
        }
        ksort($technik);
        $d = ['kennung' => (string) $g['kennung']];
        foreach (self::STAMMDATEN as $feld) {
            $d[$feld] = $g[$feld] ?? '';
        }
        $d['ausser_betrieb'] = ($g['ausser_betrieb'] ?? false) === true;
        $d['letzte_pruefung'] = (string) ($g['letzte_pruefung'] ?? '');
        $d['naechste_pruefung'] = (string) ($g['naechste_pruefung'] ?? '');
        $d['technik'] = $technik;
        return $d;
    }

    /** Taugen die Felder für einen Prüfling? null = ja, sonst der Grund. */
    private static function fehlerIn(array $d): ?string
    {
        if (Prueflinge::kennung($d['kennung']) === '') {
            return 'ohne Kennung';
        }
        if (!isset(Prueflinge::SCHUTZKLASSEN[(string) $d['schutzklasse']])) {
            return 'unbekannte Schutzklasse';
        }
        if (preg_match('/^\d{1,3}$/', (string) $d['intervall_monate']) !== 1 || (int) $d['intervall_monate'] > 600) {
            return 'Prüfintervall nicht lesbar';
        }
        foreach (Prueflinge::ZAHLEN as $feld => $laenge) {
            $t = Messarten::dezimal($d[$feld]);
            if ($t === null || strlen($t) > $laenge) {
                return Messarten::text(self::VERGLICHEN[$feld] ?? $feld, 40) . ' nicht lesbar';
            }
        }
        foreach ($d['letzte_pruefung'] === '' && $d['naechste_pruefung'] === '' ? [] : [$d['letzte_pruefung'], $d['naechste_pruefung']] as $tag) {
            if ($tag !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag) !== 1) {
                return 'Datum nicht lesbar';
            }
        }
        return null;
    }

    /**
     * Vergleichswerte eines Prüflings hier (Text je Feld aus {@see VERGLICHEN}).
     *
     * @param array<string,mixed> $p Zeile aus gp_prueflinge
     * @param array<int,string> $ablaufGeraet gp_pruefablaeufe.id → geraet_id
     * @return array<string,string>
     */
    private static function vergleichswerte(array $p, array $ablaufGeraet): array
    {
        $w = [];
        foreach (self::VERGLICHEN as $feld => $_) {
            $w[$feld] = match ($feld) {
                'pruefablauf'      => $p['pruefablauf_id'] === null ? '' : ($ablaufGeraet[(int) $p['pruefablauf_id']] ?? ''),
                'intervall_monate' => (string) (int) $p['intervall_monate'],
                'ausser_betrieb'   => (int) $p['ausser_betrieb'] === 1 ? '1' : '0',
                default            => (string) $p[$feld],
            };
        }
        return $w;
    }

    /** @return array<string,string> */
    private static function dateiwerte(array $d, string $ablaufGeraetId): array
    {
        $w = [];
        foreach (self::VERGLICHEN as $feld => $_) {
            $w[$feld] = match ($feld) {
                'pruefablauf'    => $ablaufGeraetId,
                'ausser_betrieb' => $d['ausser_betrieb'] === true ? '1' : '0',
                default          => (string) $d[$feld],
            };
        }
        return $w;
    }

    /**
     * Gleich? Ein Text, den der Export auf die Länge der Spalte im Gerät
     * gekürzt hat, zählt als gleich – sonst stünde nach jedem Export „hier
     * anders“ da.
     */
    private static function gleich(string $feld, string $hier, string $datei): bool
    {
        if ($hier === $datei) {
            return true;
        }
        $laenge = BenningVorlage::GERAETE_TEXTE[$feld][1] ?? null;
        return $laenge !== null && mb_strlen($hier) > $laenge && $datei === BenningVorlage::geraetText($hier, $laenge);
    }

    /** @param array<string,string> $ablaufName */
    private static function anzeige(string $feld, string $wert, array $ablaufName): string
    {
        return match ($feld) {
            'pruefablauf'    => $wert === '' ? '–' : ($ablaufName[$wert] ?? $wert) . ' (' . $wert . ')',
            'schutzklasse'   => Prueflinge::SCHUTZKLASSEN[$wert] ?? $wert,
            'ausser_betrieb' => $wert === '1' ? 'ja' : 'nein',
            default          => $wert === '' ? '–' : Messarten::anzeige($wert),
        };
    }

    /** @param array<string,mixed>|null $technik */
    private static function technikText(?array $technik): string
    {
        $t = array_map(static fn ($v): string => is_bool($v) ? ($v ? '1' : '0') : (string) $v, (array) $technik);
        ksort($t);
        return (string) json_encode($t, JSON_UNESCAPED_UNICODE);
    }

    /** Ist das Inventarstück noch da, kein Tanksensor und mit keinem Prüfling verknüpft? */
    private static function inventarFrei(int $id): bool
    {
        $i = Database::first('SELECT art FROM inventar WHERE id = ?', [$id]);
        return $i !== null && (string) $i['art'] !== 'tanksensor'
            && (int) Database::scalar('SELECT COUNT(*) FROM gp_prueflinge WHERE inventar_id = ?', [$id]) === 0;
    }

    /**
     * Welche dieser Fingerabdrücke gibt es schon? (in Stücken – nie eine
     * Abfrage mit 50 000 Platzhaltern)
     *
     * @param list<string> $schluessel
     * @return array<string,true>
     */
    private static function vorhandeneSchluessel(array $schluessel): array
    {
        $da = [];
        foreach (array_chunk(array_values(array_unique($schluessel)), 400) as $stueck) {
            foreach (Database::select('SELECT schluessel FROM gp_pruefungen WHERE schluessel IN (' . implode(',', array_fill(0, count($stueck), '?')) . ')', $stueck) as $z) {
                $da[(string) $z['schluessel']] = true;
            }
        }
        return $da;
    }

    /**
     * Einen Kunden aus dem Gerät anlegen (über KundenService – Prüfung und
     * Audit wie von Hand), dann die Kontaktfelder.
     *
     * @param array<string,mixed> $k
     */
    private static function kundeAnlegen(array $k): int
    {
        $name = Messarten::text($k['name'] ?? '', 255);
        if (mb_strlen($name) < 2) {
            $name = 'Kunde aus dem Prüfgerät (' . $k['ref'] . ')';
        }
        $nummer = self::nummerFrei((string) ($k['kundennummer'] ?? ''), null);
        $r = KundenService::speichern(null, ['name' => $name, 'kundennummer' => $nummer, 'aktiv' => 1,
            'notizen' => Messarten::absatz($k['bemerkung'] ?? '', 5000)]);
        if (!$r['ok']) {
            throw new \DomainException('der Kunde „' . Messarten::text($name, 60) . '“ ließ sich nicht anlegen: ' . $r['message']);
        }
        $id = (int) $r['id'];
        $felder = [];
        foreach (self::KONTAKT as $feld) {
            if ((string) ($k[$feld] ?? '') !== '') {
                $felder[$feld] = (string) $k[$feld];
            }
        }
        if ($felder !== []) {
            Database::update('kunden', $id, $felder);
        }
        KundenService::vergessen();
        return $id;
    }

    /**
     * Leere Felder eines vorhandenen Kunden aus dem Gerät füllen – nie
     * überschreiben. true, wenn etwas gefüllt wurde.
     *
     * @param array<string,mixed> $k
     */
    private static function kundeErgaenzen(int $id, array $k): bool
    {
        $hier = Database::first('SELECT * FROM kunden WHERE id = ?', [$id]);
        if ($hier === null) {
            throw new \DomainException('einen der gewählten Kunden gibt es inzwischen nicht mehr.');
        }
        $felder = [];
        foreach (self::KONTAKT as $feld) {
            if (array_key_exists($feld, $hier) && trim((string) $hier[$feld]) === '' && (string) ($k[$feld] ?? '') !== '') {
                $felder[$feld] = (string) $k[$feld];
            }
        }
        if (trim((string) $hier['kundennummer']) === '') {
            $nummer = self::nummerFrei((string) ($k['kundennummer'] ?? ''), $id);
            if ($nummer !== '') {
                $felder['kundennummer'] = $nummer;
            }
        }
        if ($felder === []) {
            return false;
        }
        Database::update('kunden', $id, $felder + ['updated_at' => gmdate('c')]);
        KundenService::vergessen();
        Audit::log('kunden.speichern', 'kunde', (string) $id, ['kunde_id' => $id, 'aus_geraetepruefung' => true, 'felder' => count($felder)]);
        return true;
    }

    /** Die Kundennummer aus dem Gerät, wenn sie hier frei ist und nicht PowerManagement gehört – sonst ''. */
    private static function nummerFrei(string $nummer, ?int $ausser): string
    {
        $nummer = Messarten::text($nummer, 64);
        if ($nummer === '' || BenningVorlage::reserviert($nummer)) {
            return '';
        }
        foreach (Database::select("SELECT id, kundennummer FROM kunden WHERE kundennummer <> ''") as $andere) {
            if ((int) $andere['id'] !== $ausser && mb_strtolower((string) $andere['kundennummer'], 'UTF-8') === mb_strtolower($nummer, 'UTF-8')) {
                return '';
            }
        }
        return $nummer;
    }
}
