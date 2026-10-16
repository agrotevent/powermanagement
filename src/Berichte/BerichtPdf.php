<?php
declare(strict_types=1);

namespace App\Berichte;

use App\Pdf\Schreiber;
use App\Pdf\Tabelle;
use App\Support\Zeit;

/**
 * Das PDF eines Übergabeberichts, angelehnt an die beiden Vorlagen:
 * Blatt 1 „Übergabebericht – Elektrische Anlage“ (Kopf, Prüfpunkte,
 * Messung, Ergebnis, Unterschriften), danach das „Messprotokoll“ mit allen
 * Stromkreisen, den angewandten Grenzwerten und dem Prüfnachweis. Fuß auf
 * jeder Seite: Firmendaten, Nummer, Prüfsumme, „Blatt x von y“.
 *
 * Gelesen wird nur der Inhalt (bei einem eingefrorenen Bericht mit seinen
 * festen Kopien: Firma, Messgeräte, Texte, Bewertung) und die
 * Unterschriften – nichts aus den Einstellungen. So ergibt ein
 * abgeschlossener Bericht beim zweiten Erzeugen dieselben Bytes, auch wenn
 * die Firmendaten sich inzwischen geändert haben. Die Vorschau eines
 * Entwurfs bekommt die Kopien vorher von BerichtService (wie beim
 * Einfrieren) und ein Wasserzeichen.
 */
final class BerichtPdf
{
    public const ERZEUGER = 'pm-pdf-1';

    private const LINKS = 15.0;
    private const RECHTS = 195.0;
    private const BREITE = 180.0;
    /** Inhalt zwischen Seitenkopf und Fuß. */
    private const OBEN = 41.0;
    private const UNTEN = 268.0;
    private const FUSS = 272.0;
    private const LABEL = 45.0;
    private const POLSTER_X = 1.2;
    private const POLSTER_Y = 0.7;
    private const PT = 8.0;

    private const HOCH = ['1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹'];

    private Schreiber $pdf;
    /** @var array<string,mixed> vollständiger Inhalt */
    private array $i;
    private float $y = 0.0;
    /** blatt1 | mess – was ein Seitenumbruch beginnt */
    private string $art = 'blatt1';
    private string $abschnitt = '';
    /** @var array<string,string> Fußnoten des Messprotokolls: Text → Marke */
    private array $fussnoten = [];
    /** @var list<callable():void> erst am Ende (Seitenzahlen bekannt) */
    private array $nachher = [];
    private int $messAb = 0;

    /**
     * @param array<string,mixed> $bericht Zeile aus BerichtService::find() mit dekodiertem Inhalt
     * @param array<string,array<string,mixed>> $unterschriften je Rolle (BerichtService::unterschriften)
     */
    private function __construct(private array $bericht, private array $unterschriften, private bool $vorschau)
    {
        $this->i = BerichtSchema::vollstaendig(is_array($bericht['inhalt'] ?? null) ? $bericht['inhalt'] : []);
        if (!is_array($this->i['bewertung'] ?? null)) {
            $this->i['bewertung'] = Grenzwerte::alle($this->i);
        }
    }

    /** Die Bytes des PDF. $vorschau: mit Wasserzeichen, für alles vor dem Abschluss. */
    public static function erzeugen(array $bericht, array $unterschriften, bool $vorschau): string
    {
        return (new self($bericht, $unterschriften, $vorschau))->bauen();
    }

    /** Dateiname ohne Kundennamen: „UB-2026-0042.pdf“ bzw. „Entwurf-42.pdf“. */
    public static function dateiname(array $bericht): string
    {
        $nummer = (string) ($bericht['nummer'] ?? '');
        $name = $nummer !== '' ? $nummer : 'Entwurf-' . (int) ($bericht['id'] ?? 0);
        return (string) preg_replace('/[^A-Za-z0-9-]/', '-', $name) . '.pdf';
    }

    private function bauen(): string
    {
        $firma = $this->firma();
        $this->pdf = new Schreiber([
            'Title'   => 'Übergabebericht ' . $this->nummer(),
            'Author'  => $firma['name'],
            'Subject' => 'Übergabebericht Elektrische Anlage' . ((int) ($this->bericht['fassung'] ?? 1) > 1 ? ' – Korrektur (Fassung ' . (int) $this->bericht['fassung'] . ')' : '')
                . ($this->vorschau ? ' – Vorschau' : ''),
        ]);
        if ($this->vorschau) {
            $this->pdf->wasserzeichen($this->wasserzeichenText());
        }

        $this->blatt1();
        $this->messprotokoll();

        foreach ($this->nachher as $arbeit) {
            $arbeit();
        }
        $gesamt = $this->pdf->seitenzahl();
        for ($s = 0; $s < $gesamt; $s++) {
            $this->pdf->aufSeite($s);
            $this->fuss($s + 1, $gesamt);
        }
        return $this->pdf->ausgabe($this->erzeugtAm(), $this->dokumentId());
    }

    // ================================================================ Blatt 1

    private function blatt1(): void
    {
        $this->art = 'blatt1';
        $this->neueSeite();
        $kopf = $this->i['kopf'];
        $vg = $this->vorgaenger();
        if ((int) ($this->bericht['fassung'] ?? 1) > 1) {
            $text = 'Korrektur (Fassung ' . (int) $this->bericht['fassung'] . ')';
            if ($vg !== null) {
                $text .= ' – ersetzt Fassung ' . $vg['fassung'] . ($vg['abgeschlossen_am'] !== '' ? ' vom ' . Zeit::anzeige((string) $vg['abgeschlossen_am'], 'd.m.Y') : '')
                    . ' (' . $vg['nummer'] . ')' . ($vg['inhalt_hash'] !== '' ? ', Prüfsumme ' . self::kurz((string) $vg['inhalt_hash']) : '');
            }
            $grund = (string) ($vg['grund'] ?? ($this->bericht['korrektur_grund'] ?? ''));
            $this->zeile([['b' => self::BREITE, 'grau' => 0.95, 'teile' => [['t' => $text, 'fett' => true], ['t' => 'Grund: ' . ($grund !== '' ? $grund : '–')]]]]);
            $this->y += 2;
        }

        // ---------------------------------------------- Kopftabelle
        $kunde = $kopf['kunde'];
        $firma = $this->firma();
        $pruefer = is_array($kopf['pruefer'] ?? null) ? $kopf['pruefer'] : [];
        $this->kopfZeile('Veranstaltung / Zeitraum', [['t' => (string) $kopf['veranstaltung'], 'fett' => true], ['t' => (string) $kopf['zeitraum']]]);
        $this->kopfZeile('Auftragnehmer', [['t' => $firma['name'], 'fett' => true], ['t' => self::einzeilig($firma['anschrift'])]]);
        $this->kopfZeile('Kunde', [
            ['t' => (string) $kunde['firma'], 'fett' => true], ['t' => (string) $kunde['anschrift']],
            ['t' => $kunde['kundennummer'] !== '' ? 'Kundennummer ' . $kunde['kundennummer'] : ''],
        ], 'Ansprechpartner / Projektleiter Kunde', [
            ['t' => (string) $kunde['ansprechpartner']],
            ['t' => self::verbinden([(string) $kunde['telefon'], (string) $kunde['email']])],
            ['t' => $kopf['projektleiter_kunde'] !== '' && $kopf['projektleiter_kunde'] !== $kunde['ansprechpartner'] ? 'Projektleiter: ' . $kopf['projektleiter_kunde'] : ''],
        ]);
        $prueferText = [['t' => (string) ($pruefer['name'] ?? ''), 'fett' => true], ['t' => (string) ($pruefer['qualifikation'] ?? '')]];
        if ($kopf['projektleiter_montage'] !== '') {
            $this->kopfZeile('Prüfer', $prueferText, 'Projektleiter Montage', [['t' => (string) $kopf['projektleiter_montage']]]);
        } else {
            $this->kopfZeile('Prüfer', $prueferText);
        }
        $this->kopfZeile('Bezeichnung der Anlage, Aufstellort', [['t' => (string) $kopf['anlage']['bezeichnung'], 'fett' => true],
            ['t' => (string) $kopf['anlage']['details']], ['t' => (string) $kopf['anlage']['aufstellort']]]);
        if ($kopf['anlagenverantwortlicher'] !== '' || ($this->i['texte']['bereitschaft'] ?? $kopf['bereitschaft']) !== '') {
            $this->kopfZeile('Anlagenverantwortlicher', [['t' => (string) $kopf['anlagenverantwortlicher']]],
                'Bereitschaft', [['t' => (string) ($this->i['texte']['bereitschaft'] ?? $kopf['bereitschaft'])]]);
        }
        $this->kopfZeile('Netz', [['t' => $this->netzText()]]);
        $this->kopfZeile('Prüfung nach', [['t' => $this->normenText()]]);
        $this->kopfZeile('Grund der Prüfung', [['t' => (string) ($this->labels('gruende', Katalog::gruende())[$this->i['grundlage']['grund']] ?? '')]],
            'Prüfdatum', [['t' => self::datum((string) $kopf['pruefdatum']) . ($kopf['ort'] !== '' ? ' · ' . $kopf['ort'] : ''), 'fett' => true]]);
        $this->y += 2.2;

        // ---------------------------------------------- Prüfpunkte
        $bemerkungen = [];
        $this->punkte('besichtigung', 'Besichtigung', Katalog::besichtigung(), $bemerkungen);
        $this->punkte('erprobung', 'Erprobung', Katalog::erprobung(), $bemerkungen);
        if ($bemerkungen !== []) {
            $teile = [];
            foreach ($bemerkungen as $b) {
                $teile[] = ['t' => $b, 'pt' => 7.5];
            }
            $this->zeile([['b' => self::BREITE, 'teile' => $teile]]);
        }
        $this->platz(4);
        $this->legende(self::LINKS, $this->y + 3.0, [
            ['haken', 'in Ordnung'], ['kreuz', 'nicht in Ordnung'], ['', 'entf. = entfällt'], ['', 'leer = nicht geprüft'],
        ]);
        $this->y += 5.4;

        $this->messung();
        $this->bemerkungenUndMaengel();
        $this->ergebnis();
        $this->unterschriften();
    }

    /**
     * Ein Kasten mit den Prüfpunkten in zwei Spalten: die Kurzliste und
     * alles, was beantwortet ist oder eine Bemerkung hat.
     *
     * @param array<string,array{text:string,kurz:bool,quelle:string}> $katalog
     * @param list<string> $bemerkungen gesammelte Bemerkungen mit Marke
     */
    private function punkte(string $teil, string $titel, array $katalog, array &$bemerkungen): void
    {
        $antworten = (array) ($this->i['punkte'][$teil] ?? []);
        $texte = $this->labels($teil, array_map(static fn (array $p): string => $p['text'], $katalog));
        $liste = [];
        foreach (array_keys(Katalog::inReihenfolge($texte + $antworten, $katalog)) as $schluessel) {
            $schluessel = (string) $schluessel;
            $antwort = (string) ($antworten[$schluessel] ?? '');
            $bem = (string) ($this->i['punkt_bemerkung'][$schluessel] ?? '');
            if (!($katalog[$schluessel]['kurz'] ?? false) && $antwort === '' && $bem === '') {
                continue;
            }
            $text = (string) ($texte[$schluessel] ?? Katalog::punktText($schluessel));
            if ($bem !== '') {
                $marke = self::hoch(count($bemerkungen) + 1);
                $bemerkungen[] = $marke . ' ' . $text . ': ' . $bem;
                $text .= ' ' . $marke;
            }
            $liste[] = ['zustand' => $antwort, 'text' => $text];
        }
        if ($teil === 'besichtigung' && (string) $this->i['sicherheitskategorie'] !== '') {
            $liste[] = ['zustand' => '-', 'text' => 'Höchste Sicherheitskategorie: ' . $this->i['sicherheitskategorie']];
        }
        $this->abschnitt($titel, 'Angabe je Punkt: in Ordnung, nicht in Ordnung, entfällt – leer: nicht geprüft');
        if ($liste === []) {
            $this->zeile([['b' => self::BREITE, 'teile' => [['t' => 'Keine Punkte eingetragen.', 'grau' => 0.4]]]]);
        }
        foreach (array_chunk($liste, 2) as $paar) {
            $zellen = [];
            foreach ([0, 1] as $n) {
                $p = $paar[$n] ?? null;
                if ($p === null) {
                    $zellen[] = ['b' => self::BREITE / 2, 'teile' => []];
                    continue;
                }
                $zustand = $p['zustand'];
                $zellen[] = ['b' => self::BREITE / 2, 'einzug' => $zustand === '-' ? 0.0 : 5.4, 'teile' => [['t' => $p['text'], 'pt' => 7.8]], 'min' => 5.0,
                    'zeichne' => $zustand === '-' ? null : function (float $x, float $y) use ($zustand): void {
                        $this->pdf->kaestchen($x + 1.2, $y + 0.6, $zustand, 3.8);
                    }];
            }
            $this->zeile($zellen);
        }
        $this->abschnittEnde();
    }

    private function messung(): void
    {
        $m = $this->i['messung'];
        $erd = (array) ($this->i['bewertung']['erdung'] ?? []);
        $this->abschnitt('Messung');
        $wert = self::wert((array) $m['erdung']);
        if ($wert !== '') {
            $text = $wert . ' Ω';
            $grenze = ($erd['grenze'] ?? null) !== null ? 'Grenzwert ≤ ' . Grenzwerte::komma((float) $erd['grenze']) . ' Ω, ' : '';
            $bezug = (string) ($erd['bezug'] ?? '');
            if ($grenze !== '' || $bezug !== '') {
                $text .= ' (' . $grenze . $bezug . ')';
            }
            $stufe = match ((string) ($erd['auto'] ?? '')) {
                'ok'      => ' – in Ordnung',
                'mangel'  => ' – Mangel',
                'manuell' => ' – vom Prüfer zu bewerten',
                'fehlt'   => ' – unvollständig',
                default   => '',
            };
            $teile = [['t' => $text . $stufe]];
            if ((string) $m['erdung']['methode'] !== '') {
                $teile[] = ['t' => 'Messverfahren: ' . $m['erdung']['methode'], 'pt' => 7.5];
            }
        } else {
            $teile = [['t' => 'nicht gemessen', 'grau' => 0.35]];
        }
        $this->kopfZeile('Erdungswiderstand', $teile);
        if ((string) ($m['iso_bus']['w'] ?? '') !== '') {
            $this->kopfZeile('Isolationswiderstand der Busleitung', [['t' => self::wert((array) $m['iso_bus']) . ' MΩ']]);
        }
        [$label, $geraete] = $this->messgeraete();
        $this->kopfZeile($label, $geraete !== [] ? array_map(static fn (string $g): array => ['t' => $g], $geraete) : [['t' => '–']]);

        // Stromkreise: Zusammenfassung, Verweis auf die Blätter des Messprotokolls (erst am Ende bekannt).
        $z = $this->zaehlen();
        $zusammen = $z['gemessen'] . ' gemessen'
            . ($z['nicht'] > 0 ? ', ' . $z['nicht'] . ' nicht gemessen' : '')
            . ($z['offen'] > 0 ? ', ' . $z['offen'] . ' ohne Messwerte' : '')
            . ', ' . $z['hinweis'] . ($z['hinweis'] === 1 ? ' Hinweis' : ' Hinweise')
            . ', ' . $z['mangel'] . ($z['mangel'] === 1 ? ' Mangel' : ' Mängel')
            . ' · Isolationsmessung mit ' . ((string) $m['riso_u'] !== '' ? $m['riso_u'] . ' V' : '–');
        $this->kopfZeile('Stromkreise', [['t' => $zusammen], ['t' => ' ']], null, [], function (float $x, float $y, float $b): void {
            $seite = $this->pdf->aktuelleSeite();
            $grundlinie = $y + self::POLSTER_Y + Tabelle::zeilenhoehe(self::PT) * 1.78;
            $this->nachher[] = function () use ($seite, $x, $grundlinie): void {
                $this->pdf->aufSeite($seite);
                $von = $this->messAb + 1;
                $bis = $this->pdf->seitenzahl();
                $this->pdf->text($x + self::LABEL + self::POLSTER_X, $grundlinie, 'Einzelwerte im Messprotokoll, Blatt ' . $von . ($bis > $von ? '–' . $bis : ''), self::PT);
            };
        });
        foreach ((array) ($this->i['bewertung']['netz']['meldungen'] ?? []) as $meldung) {
            $this->kopfZeile('Netz', [['t' => (string) ($meldung['text'] ?? '')]]);
        }
        $this->abschnittEnde();
    }

    private function bemerkungenUndMaengel(): void
    {
        $maengel = array_values(array_filter($this->i['maengel'], static fn ($m): bool => is_array($m) && trim((string) ($m['text'] ?? '')) !== ''));
        $anhang = $this->i['anhang'];
        $anhangText = self::verbinden([
            ($anhang['thermographie'] ?? '') === 'ja' ? 'Thermographie' : '',
            (string) ($anhang['weitere'] ?? ''),
        ]);
        if ($maengel === [] && trim((string) $this->i['bemerkungen']) === '' && $anhangText === '') {
            return;
        }
        $this->abschnitt('Bemerkungen und Mängel');
        $nachZ = [];
        foreach ($this->i['kreise'] as $k) {
            $nachZ[(string) $k['z']] = (string) $k['nr'];
        }
        foreach ($maengel as $n => $m) {
            $zusatz = self::verbinden([
                ($m['kreis'] ?? '') !== '' && isset($nachZ[(string) $m['kreis']]) ? 'Stromkreis ' . $nachZ[(string) $m['kreis']] : '',
                ($m['frist'] ?? '') !== '' ? 'Frist ' . self::datum((string) $m['frist']) : '',
                ($m['behoben'] ?? '') !== '' ? 'behoben ' . self::datum((string) $m['behoben']) : '',
            ]);
            $this->kopfZeile('Mangel ' . ($n + 1), [['t' => (string) $m['text']], ['t' => $zusatz, 'pt' => 7.5, 'grau' => 0.3]]);
        }
        if (trim((string) $this->i['bemerkungen']) !== '') {
            $this->kopfZeile('Bemerkungen', [['t' => (string) $this->i['bemerkungen']]]);
        }
        if ($anhangText !== '') {
            $this->kopfZeile('Anlagen zum Bericht', [['t' => $anhangText]]);
        }
        $this->abschnittEnde();
    }

    private function ergebnis(): void
    {
        $e = $this->i['ergebnis'];
        $this->abschnitt('Prüfergebnis', '', 34.0);
        $this->janeinZeile('Die elektrische Anlage entspricht den anerkannten Regeln der Elektrotechnik', (string) $e['entspricht']);
        $this->janeinZeile('Mängelfrei', (string) $e['maengelfrei']);
        if ((string) $e['instand_bis'] !== '') {
            $this->zeile([['b' => self::BREITE, 'teile' => [['t' => 'Die Anlage ist bis zum ' . self::datum((string) $e['instand_bis']) . ' ordnungsgemäß instand zu setzen.', 'fett' => true]]]]);
        }
        if ((string) $e['plakette'] !== '' || (string) $e['plakette_nr'] !== '') {
            $this->janeinZeile('Prüfplakette angebracht' . ((string) $e['plakette_nr'] !== '' ? ' – Nr. ' . $e['plakette_nr'] : ''), (string) $e['plakette']);
        }
        $this->kopfZeile('Nächster Prüftermin', [['t' => (string) $e['naechste'] !== '' ? (string) $e['naechste'] : '–']]);
        if ((string) $e['errichter'] === '1') {
            $text = (string) ($this->i['texte']['errichter'] ?? 'Errichterbestätigung nach DGUV Vorschrift 3 § 5 Abs. 4');
            $this->zeile([['b' => self::BREITE, 'einzug' => 6.0, 'teile' => [['t' => 'Errichterbestätigung', 'fett' => true], ['t' => $text]],
                'zeichne' => function (float $x, float $y): void {
                    $this->pdf->kaestchen($x + 1.2, $y + 1.0, 'x', 4.0);
                }]]);
        }
        $this->abschnittEnde();
    }

    /**
     * Unterschriften wie in Vorlage 2: darüber über die ganze Breite die
     * Bestätigungen des Kunden (Abnahme-/Dokumentbestätigung, nicht des
     * prüftechnischen Inhalts), darunter zwei Kästen zu je 88 mm – Prüfer
     * und Kunde, jeweils Ort, Datum, Unterschrift (Pfad), Name.
     */
    private function unterschriften(): void
    {
        $breite = 88.0;
        $innen = $breite - 4.0;
        $flaeche = 22.0;
        $p = $this->unterschriften['pruefer'] ?? null;
        $k = $this->unterschriften['kunde'] ?? null;
        $texte = is_array($this->i['texte'] ?? null) ? $this->i['texte'] : [];
        $ohne = $k !== null && (string) $k['art'] !== 'gezeichnet';
        $wann = static fn (array $u): string => 'Ort, Datum: ' . self::verbinden([(string) $u['ort'], Zeit::anzeige((string) $u['unterschrieben_am'])], ', ');
        $leer = ['t' => 'Ort, Datum:', 'pt' => 7.5, 'grau' => 0.4];

        // Bestätigungen des Kunden
        $kopf = [];
        $haken = [];
        $nachHaken = [];
        if (!$ohne) {
            $kopf = $this->zeilen(array_merge([['t' => 'Übergabe an den Kunden', 'pt' => 8.5, 'fett' => true]],
                ($texte['kunde'] ?? '') !== '' ? [['t' => (string) $texte['kunde'], 'pt' => 6.8, 'grau' => 0.3]] : []), self::BREITE - 4.0);
            $gewaehlt = $k !== null ? array_map('strval', (array) ($k['angaben']['bestaetigungen'] ?? [])) : [];
            foreach (Katalog::inReihenfolge($this->labels('bestaetigungen', array_map(static fn (array $b): string => $b['text'], Katalog::bestaetigungen())),
                Katalog::bestaetigungen()) as $schluessel => $text) {
                $label = match ((string) $schluessel) {
                    'eingewiesen' => (string) ($texte['einweisung'] ?? $text),
                    'uebernommen' => (string) $text . ((string) $this->i['kopf']['zeitraum'] !== '' ? ' für ' . $this->i['kopf']['zeitraum'] : ''),
                    default       => (string) $text,
                };
                $haken[] = ['an' => in_array((string) $schluessel, $gewaehlt, true), 'zeilen' => $this->zeilen([['t' => $label, 'pt' => 7.2]], self::BREITE - 10.0)];
            }
            if ($k !== null) {
                $vorbehalte = trim((string) ($k['angaben']['vorbehalte'] ?? ''));
                $nachHaken = $this->zeilen([['t' => 'Vorbehalte des Kunden: ' . ($vorbehalte !== '' ? $vorbehalte : 'keine'), 'pt' => 7.2]], self::BREITE - 4.0);
            }
        }
        $hakenH = static fn (array $h): float => max(3.8, Tabelle::zeilenhoehe(7.2) * count($h['zeilen']) + 0.7);
        $hBest = $kopf === [] ? 0.0 : 1.5 + $this->hoehe($kopf) + 0.8 + array_sum(array_map($hakenH, $haken)) + $this->hoehe($nachHaken) + 1.5;

        // Kästen: [Zeilen vor der Fläche, Striche, mit Fläche, Zeilen danach]
        $kaesten = [
            [$this->zeilen([['t' => 'Prüfer', 'pt' => 9.0, 'fett' => true], $p !== null ? ['t' => $wann($p), 'pt' => 7.5] : $leer], $innen),
                $p['striche'] ?? null, true,
                $this->zeilen($p !== null ? [['t' => (string) $p['name'], 'pt' => 8.0, 'fett' => true], ['t' => (string) $p['funktion'], 'pt' => 7.5]]
                    : [['t' => 'Unterschrift, Name, Qualifikation', 'pt' => 7.0, 'grau' => 0.4]], $innen)],
        ];
        if ($ohne) {
            $kaesten[] = [$this->zeilen([['t' => 'Kunde', 'pt' => 9.0, 'fett' => true],
                ['t' => 'Ohne Unterschrift des Kunden: ' . self::verbinden([BerichtService::OHNE_KUNDE[(string) $k['art']] ?? (string) $k['art'],
                    (string) ($k['angaben']['grund'] ?? '')], ' – '), 'pt' => 8.0, 'fett' => true],
                ['t' => 'eingetragen ' . Zeit::anzeige((string) $k['unterschrieben_am']), 'pt' => 7.0, 'grau' => 0.35]], $innen), null, false, []];
        } else {
            $kaesten[] = [$this->zeilen([['t' => 'Kunde (Abnahme-/Dokumentbestätigung)', 'pt' => 9.0, 'fett' => true], $k !== null ? ['t' => $wann($k), 'pt' => 7.5] : $leer], $innen),
                $k['striche'] ?? null, true,
                $this->zeilen($k !== null ? [['t' => (string) $k['name'], 'pt' => 8.0, 'fett' => true], ['t' => (string) $k['funktion'], 'pt' => 7.5]]
                    : [['t' => 'Unterschrift, Name, Funktion', 'pt' => 7.0, 'grau' => 0.4]], $innen)];
        }
        $hBox = 0.0;
        foreach ($kaesten as [$vor, , $mitFlaeche, $nach]) {
            $hBox = max($hBox, 2.0 + $this->hoehe($vor) + ($mitFlaeche ? 1.0 + $flaeche + 1.5 : 0.0) + $this->hoehe($nach) + 2.0);
        }
        $this->platz($hBest + ($hBest > 0 ? 2.0 : 0.0) + $hBox);

        if ($hBest > 0) {
            $y0 = $this->y;
            $this->pdf->rechteck(self::LINKS, $y0, self::BREITE, $hBest);
            $y = $this->schreiben($kopf, self::LINKS + 2.0, $y0 + 1.5) + 0.8;
            foreach ($haken as $h) {
                $this->pdf->kaestchen(self::LINKS + 2.0, $y + 0.3, $h['an'] ? 'io' : '', 3.0);
                $this->schreiben($h['zeilen'], self::LINKS + 7.0, $y);
                $y += $hakenH($h);
            }
            $this->schreiben($nachHaken, self::LINKS + 2.0, $y);
            $this->y = $y0 + $hBest + 2.0;
        }
        $y0 = $this->y;
        foreach ($kaesten as $n => [$vor, $striche, $mitFlaeche, $nach]) {
            $x = self::LINKS + $n * ($breite + 4.0);
            $this->pdf->rechteck($x, $y0, $breite, $hBox);
            $y = $this->schreiben($vor, $x + 2.0, $y0 + 2.0);
            if ($mitFlaeche) {
                $y += 1.0;
                if (is_array($striche)) {
                    $this->pdf->unterschrift($striche, $x + 2.0, $y, 80.0, $flaeche);
                }
                $y += $flaeche;
                $this->pdf->linie($x + 2.0, $y + 0.5, $x + $breite - 2.0, $y + 0.5, 0.2);
                $y += 1.5;
            }
            $this->schreiben($nach, $x + 2.0, $y);
        }
        $this->y = $y0 + $hBox + 3.0;
    }

    // ================================================================ Messprotokoll

    private function messprotokoll(): void
    {
        $this->art = 'mess';
        $this->abschnitt = '';
        $this->neueSeite();
        $this->messAb = $this->pdf->aktuelleSeite();
        $kreise = $this->i['kreise'];
        $mitRpe = false;
        foreach ($kreise as $k) {
            if ((string) ($k['w']['rpe']['w'] ?? '') !== '') {
                $mitRpe = true;
            }
        }
        $sp = [
            ['titel' => ['Nr'], 'breite' => 7.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['Ort/Anlagenteil'], 'breite' => $mitRpe ? 38.0 : 47.0],
            ['titel' => ['Art'], 'breite' => 15.0],
            ['titel' => ['Leiter'], 'breite' => 7.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['mm²'], 'breite' => 8.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['Art/', 'Char.'], 'breite' => 10.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['I_{n}', '[A]'], 'breite' => 8.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['Z_{s} [Ω]', 'I_{k} [A]'], 'breite' => 15.0, 'ausrichtung' => 'mitte'],
        ];
        if ($mitRpe) {
            $sp[] = ['titel' => ['R_{PE}', '[Ω]'], 'breite' => 9.0, 'ausrichtung' => 'mitte'];
        }
        $rcd = count($sp);
        array_push($sp,
            ['titel' => ['R_{iso}', '[MΩ]'], 'breite' => 12.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['I_{n}/Art', '[A]'], 'breite' => 12.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['IΔn', '[mA]'], 'breite' => 8.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['IΔ', '[mA]'], 'breite' => 8.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['t_{A}', '[ms]'], 'breite' => 8.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['U_{B}', '[V]'], 'breite' => 8.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['Erg.'], 'breite' => 7.0, 'ausrichtung' => 'mitte'],
        );
        $gruppen = [
            ['titel' => 'Leitung', 'von' => 2, 'bis' => 4],
            ['titel' => 'Überstromschutz', 'von' => 5, 'bis' => 6],
            ['titel' => 'Fehlerstrom-Schutz (RCD)', 'von' => $rcd + 1, 'bis' => $rcd + 4],
        ];
        $tabelle = new Tabelle($this->pdf, self::LINKS, $this->y, $sp, $gruppen, function (): float {
            $this->neueSeite();
            return $this->y;
        }, self::UNTEN);
        $tabelle->kopf();
        $nachZ = [];
        foreach ($kreise as $k) {
            $nachZ[(string) $k['z']] = $k;
        }
        foreach ($kreise as $k) {
            $tabelle->zeile($this->kreisZellen($k, $nachZ, $mitRpe));
        }
        if ($kreise === []) {
            $tabelle->zeile(['', ['teile' => [['t' => 'Keine Stromkreise eingetragen.', 'grau' => 0.4]]]]);
        }
        $this->y = $tabelle->y() + 1.5;

        // Fußnoten und Lesehilfe
        $noten = [];
        foreach ($this->fussnoten as $text => $marke) {
            $noten[] = ['t' => $marke . ' ' . $text, 'pt' => 6.5];
        }
        $risoU = (string) $this->i['messung']['riso_u'];
        $noten[] = ['t' => '„>“ über, „<“ unter dem Messbereich. Zs/Ik: zweite Zeile der Grenzwert dieses Stromkreises (Ik: Mindestwert). '
            . 'Riso gemessen mit ' . ($risoU !== '' ? $risoU . ' V' : '–') . ', abweichende Messspannung in der zweiten Zeile. '
            . 'RCD-Werte ohne eigene Messung: siehe Fußnote.', 'pt' => 6.5];
        $zeilen = $this->zeilen($noten, self::BREITE);
        $this->platz($this->hoehe($zeilen) + 4.5);
        $this->y = $this->schreiben($zeilen, self::LINKS, $this->y);
        $this->legende(self::LINKS, $this->y + 2.6, [
            ['haken', 'in Ordnung'], ['', '! Hinweis'], ['kreuz', 'Mangel'], ['', '? vom Prüfer zu bewerten'], ['', '… Wert fehlt'], ['', '– nicht gemessen'],
        ], 6.5);
        $this->y += 6.0;

        $this->grenzwerteUndNachweis();
        $this->prueferzeile();
    }

    /**
     * Die Zellen eines Stromkreises.
     *
     * @param array<string,array<string,mixed>> $nachZ
     * @return list<string|array>
     */
    private function kreisZellen(array $k, array $nachZ, bool $mitRpe): array
    {
        $z = (string) $k['z'];
        $bw = (array) ($this->i['bewertung']['kreise'][$z] ?? []);
        $g = (array) ($bw['grenzen'] ?? []);
        $w = static fn (string $feld): string => self::wert((array) ($k['w'][$feld] ?? []));
        $klein = static fn (string $t): array => ['t' => $t, 'pt' => 6.3, 'grau' => 0.3];

        $ort = [['t' => (string) $k['ort']]];
        if ((string) $k['kabel'] !== '') {
            $ort[] = $klein((string) $k['kabel']);
        }
        if ((string) $k['status'] === 'nicht_gemessen') {
            $ort[] = $klein('nicht gemessen' . ((string) $k['grund'] !== '' ? ': ' . $k['grund'] : ''));
        }
        if ((string) $k['drehfeld'] !== '') {
            $ort[] = $klein('Drehfeld ' . $k['drehfeld']);
        }
        if ((string) $k['bem'] !== '') {
            $ort[] = $klein((string) $k['bem']);
        }

        $ueb = $k['ueb'];
        $uebArt = trim(((string) $ueb['art'] !== 'LS' ? (string) $ueb['art'] . ' ' : '') . (string) $ueb['char']);

        // Zs oder Ik, darunter der Grenzwert; Abschaltung durch RCD als Fußnote
        $zs = $w('zs');
        $ik = $w('ik');
        $marke = (string) ($bw['abschaltung'] ?? '') === 'RCD' ? $this->fussnote('Abschaltung durch RCD (Zs · IΔn ≤ U0).') : '';
        $zsTeile = [];
        if ($zs !== '') {
            $zsTeile[] = ['t' => $zs . $marke];
            if (($g['zs_max'] ?? null) !== null) {
                $zsTeile[] = $klein('≤ ' . Grenzwerte::komma((float) $g['zs_max']));
            }
        } elseif ($ik !== '') {
            $zsTeile[] = ['t' => 'Ik ' . $ik . $marke];
            if (($g['ik_min'] ?? null) !== null) {
                $zsTeile[] = $klein('≥ ' . Grenzwerte::komma((float) $g['ik_min']) . ' A');
            }
        } elseif (($g['zs_max'] ?? null) !== null) {
            $zsTeile[] = $klein('≤ ' . Grenzwerte::komma((float) $g['zs_max']));
        }

        $riso = [['t' => $w('riso')]];
        $risoU = (string) ($k['w']['riso_u'] ?? '');
        if ($risoU !== '' && $risoU !== (string) $this->i['messung']['riso_u']) {
            $riso[] = $klein('bei ' . $risoU . ' V');
        }

        $rcd = $k['rcd'];
        $rcdText = '';
        $rcdTeile = [];
        if ((string) $rcd['idn'] !== '') {
            $rcdText = trim(Felder::komma((string) $rcd['in']) . ((string) $rcd['typ'] !== '' ? '/' . $rcd['typ'] : '') . ((string) $rcd['s'] === '1' ? ' S' : ''), '/');
            $rcdTeile[] = ['t' => $rcdText];
            if ((string) $rcd['bez'] !== '') {
                $rcdTeile[] = $klein((string) $rcd['bez']);
            }
        }

        $von = (string) $k['rcd_von'];
        $bezug = $von !== '' && $von !== $z ? ($nachZ[$von] ?? null) : null;
        if ($bezug !== null) {
            $rcdMarke = $this->fussnote('RCD-Werte gemessen an Stromkreis ' . $bezug['nr'] . '.');
            [$idelta, $ta, $ub] = [$rcdMarke, '', ''];
        } else {
            [$idelta, $ta, $ub] = [$w('idelta'), $w('ta'), $w('ub')];
        }

        // Ergebnis: Prüfer vor Automatik, immer Symbol oder Zeichen
        $auto = (string) ($bw['auto'] ?? '');
        $pruefer = (string) $k['pruefer'];
        $stufe = $pruefer === 'io' ? 'ok' : ($pruefer === 'nio' ? 'mangel' : $auto);
        $ergMarke = $pruefer !== 'auto' && $pruefer !== '' && (string) $k['begruendung'] !== ''
            ? $this->fussnote('Stromkreis ' . $k['nr'] . ' vom Prüfer abweichend bewertet: ' . $k['begruendung']) : '';
        $erg = match ($stufe) {
            'ok'      => ['symbol' => 'haken', 'marke' => $ergMarke],
            'mangel'  => ['symbol' => 'kreuz', 'marke' => $ergMarke],
            'hinweis' => ['teile' => [['t' => '!' . $ergMarke, 'fett' => true]]],
            'manuell' => ['teile' => [['t' => '?' . $ergMarke, 'fett' => true]]],
            'fehlt'   => ['teile' => [['t' => '…' . $ergMarke]]],
            'entf'    => ['teile' => [['t' => '–']]],
            default   => '',
        };

        $zellen = [
            (string) $k['nr'],
            ['teile' => $ort],
            (string) $k['leitung']['art'],
            (string) $k['leitung']['leiter'],
            Felder::komma((string) $k['leitung']['querschnitt']),
            $uebArt,
            Felder::komma((string) $ueb['in']),
            ['teile' => $zsTeile],
        ];
        if ($mitRpe) {
            $zellen[] = $w('rpe');
        }
        array_push($zellen, ['teile' => $riso], ['teile' => $rcdTeile], Felder::komma((string) $rcd['idn']), $idelta, $ta, $ub, $erg);
        return $zellen;
    }

    /** Kasten „Angewandte Grenzwerte und Prüfnachweis“ aus inhalt.grenzwerte und den Unterschriften. */
    private function grenzwerteUndNachweis(): void
    {
        $p = is_array($this->i['grenzwerte'] ?? null) && $this->i['grenzwerte'] !== [] ? $this->i['grenzwerte'] : Grenzwerte::parameter();
        $k = static fn (mixed $v): string => is_string($v) && $v !== '' ? Felder::komma($v) : '–';
        $kWerte = [];
        foreach ((array) ($p['k'] ?? []) as $char => $faktor) {
            $kWerte[] = $k($faktor) . ' (' . $char . ')';
        }
        $riso = [];
        foreach ((array) ($p['riso'] ?? []) as $u => $min) {
            $riso[] = '≥ ' . $k($min) . ' MΩ bei ' . $u . ' V';
        }
        $rcd = (array) ($p['rcd'] ?? []);
        $erd = (array) ($this->i['bewertung']['erdung'] ?? []);
        $grenzen = [
            'Schleifenimpedanz (TN): Zs ≤ f · U0 / (k · In) mit f = ' . $k($p['zs_faktor'] ?? '') . ', U0 = ' . $k($p['u0'] ?? '') . ' V; k = ' . implode(', ', $kWerte)
                . '. Ik ≥ U0 / Zs,max. Ohne Rechenwert (NH/gG) gilt der eingetragene Grenzwert.',
            'Abschaltung durch RCD: Zs · IΔn ≤ U0.',
            'Isolationswiderstand: ' . implode(', ', $riso) . ' Messspannung.',
            'RCD: ' . $k($rcd['i_min'] ?? '') . ' · IΔn < IΔ ≤ ' . $k($rcd['i_max'] ?? '') . ' · IΔn; tA ≤ ' . $k($rcd['ta_max'] ?? '') . ' ms (selektiv '
                . $k($rcd['ta_s_min'] ?? '') . '–' . $k($rcd['ta_s_max'] ?? '') . ' ms); Berührungsspannung UB ≤ UL = ' . $k($p['ul'] ?? '') . ' V.',
            'Schutzleiterwiderstand: Hinweis über ' . $k($p['rpe_hinweis'] ?? '') . ' Ω.',
            'Erdung: ' . (string) ($erd['bezug'] ?? '–') . (($erd['grenze'] ?? null) !== null ? ', Grenzwert ≤ ' . Grenzwerte::komma((float) $erd['grenze']) . ' Ω' : '') . '.',
            'Quellen: ' . (string) ($p['quelle'] ?? Grenzwerte::QUELLE) . ' (Grenzwerte Fassung ' . (string) ($p['version'] ?? '1') . ').',
        ];
        $teileG = array_map(static fn (string $t): array => ['t' => $t, 'pt' => 6.5], $grenzen);

        $nachweis = [];
        $hash = (string) ($this->bericht['inhalt_hash'] ?? '');
        if ($hash !== '') {
            $nachweis[] = 'Prüfsumme des eingefrorenen Inhalts (SHA-256): ' . $hash;
            foreach (['pruefer' => 'Prüfer', 'kunde' => 'Kunde'] as $rolle => $wer) {
                $u = $this->unterschriften[$rolle] ?? null;
                if ($u === null) {
                    continue;
                }
                $am = (string) $u['unterschrieben_am'];
                $nachweis[] = 'Siegel ' . $wer . ((string) $u['art'] !== 'gezeichnet' ? ' (ohne Unterschrift)' : '') . ': ' . substr((string) $u['siegel'], 0, 16)
                    . ' · ' . Zeit::anzeige($am, 'Y-m-d H:i:s') . ' Ortszeit (' . (Zeit::ts($am) !== null ? gmdate('Y-m-d H:i:s', (int) Zeit::ts($am)) : '–') . ' UTC)'
                    . ' · Schlüssel ' . preg_replace('/[^0-9a-f]/', '', (string) $u['schluessel_id']);
            }
            $nachweis[] = 'Nummer ' . $this->nummer() . ', Fassung ' . (int) ($this->bericht['fassung'] ?? 1) . '. Inhalt, Unterschriften (nur als Linienzug) und Siegel sind gespeichert und lassen sich nachrechnen.';
        } else {
            $nachweis[] = 'Vorschau – der Inhalt ist noch nicht eingefroren und hat keine Prüfsumme.';
        }
        $vg = $this->vorgaenger();
        if ($vg !== null) {
            $nachweis[] = 'Ersetzt ' . $vg['nummer'] . ', Fassung ' . $vg['fassung'] . ($vg['abgeschlossen_am'] !== '' ? ' vom ' . Zeit::anzeige((string) $vg['abgeschlossen_am'], 'd.m.Y') : '')
                . ', Prüfsumme ' . ($vg['inhalt_hash'] !== '' ? $vg['inhalt_hash'] : '–') . ', Grund: ' . $vg['grund'];
        }
        $teileN = array_map(static fn (string $t): array => ['t' => $t, 'pt' => 6.5], $nachweis);

        $this->abschnitt('Angewandte Grenzwerte und Prüfnachweis');
        $this->zeile([['b' => self::BREITE, 'teile' => $teileG]]);
        $this->zeile([['b' => self::BREITE, 'teile' => $teileN]]);
        $this->abschnittEnde();
    }

    /** Am Ende des Messprotokolls: Prüfer, Ort, Datum und dieselbe Unterschrift. */
    private function prueferzeile(): void
    {
        $p = $this->unterschriften['pruefer'] ?? null;
        $pruefer = is_array($this->i['kopf']['pruefer'] ?? null) ? $this->i['kopf']['pruefer'] : [];
        $h = 24.0;
        $this->platz($h);
        $y0 = $this->y;
        $this->pdf->rechteck(self::LINKS, $y0, self::BREITE, $h);
        $this->pdf->text(self::LINKS + 2, $y0 + 4.5, 'Prüfer', 9.0, true);
        $name = (string) ($p['name'] ?? ($pruefer['name'] ?? ''));
        $this->pdf->text(self::LINKS + 2, $y0 + 9.5, $name !== '' ? $name : '–', 8.5);
        $quali = $this->zeilen([['t' => (string) ($p['funktion'] ?? ($pruefer['qualifikation'] ?? '')), 'pt' => 7.5]], 70.0);
        $this->schreiben(array_slice($quali, 0, 2), self::LINKS + 2, $y0 + 10.8);
        $this->pdf->text(self::LINKS + 2, $y0 + 20.5, 'Ort, Datum: ' . ($p !== null ? self::verbinden([(string) $p['ort'], Zeit::anzeige((string) $p['unterschrieben_am'])], ', ') : ''),
            7.5, false, $p !== null ? 0.0 : 0.4);
        $x = self::LINKS + 95.0;
        if ($p !== null) {
            $this->pdf->unterschrift((array) $p['striche'], $x, $y0 + 1.5, 80.0, 17.0);
        }
        $this->pdf->linie($x, $y0 + 19.0, self::RECHTS - 2, $y0 + 19.0, 0.2);
        $this->pdf->text($x, $y0 + 22.2, 'Unterschrift', 6.5, false, 0.4);
        $this->y = $y0 + $h + 2;
    }

    // ================================================================ Seiten

    private function neueSeite(): void
    {
        $this->pdf->seite();
        $firma = $this->firma();
        $korrektur = (int) ($this->bericht['fassung'] ?? 1) > 1 ? 'Korrektur (Fassung ' . (int) $this->bericht['fassung'] . ')' : '';
        if ($this->art === 'mess') {
            $this->pdf->text(self::LINKS, 22, 'Messprotokoll', 18, true);
            $this->pdf->text(self::LINKS, 28.5, 'zum Übergabebericht Elektrische Anlage', 9);
        } else {
            $this->pdf->text(self::LINKS, 22, 'Übergabebericht', 18, true);
            $this->pdf->text(self::LINKS, 29, 'Elektrische Anlage', 18, true);
        }
        if ($korrektur !== '') {
            $this->pdf->text(self::LINKS, 34.5, $korrektur, 9.5, true);
        }
        // rechts: Firma, Zusatz, Nummer
        if ($firma['name'] !== '') {
            $pt = 12.0;
            while ($pt > 8.0 && $this->pdf->breite($firma['name'], $pt, true) > 95.0) {
                $pt -= 0.5;
            }
            $zeile = $this->pdf->umbruch($firma['name'], 95.0, $pt, true)[0];
            $this->pdf->textRechts(self::RECHTS, 20.5, $zeile, $pt, true);
        }
        $y = 25.0;
        foreach (array_slice($this->pdf->umbruch($firma['zusatz'], 95.0, 7.5), 0, 2) as $z) {
            if ($z !== '') {
                $this->pdf->textRechts(self::RECHTS, $y, $z, 7.5);
                $y += 3.3;
            }
        }
        $auftrag = (string) $this->i['kopf']['auftrag_nr'] !== '' ? (string) $this->i['kopf']['auftrag_nr'] : (string) ($this->i['kopf']['eventworx']['nummer'] ?? '');
        $nr = self::verbinden([
            'Prüfprotokoll Nr. ' . $this->nummer(),
            'Fassung ' . (int) ($this->bericht['fassung'] ?? 1),
            $auftrag !== '' ? 'Auftrag ' . $auftrag : '',
            (string) $this->i['kopf']['projekt_nr'] !== '' ? 'Projekt ' . $this->i['kopf']['projekt_nr'] : '',
        ]);
        $this->pdf->textRechts(self::RECHTS, 34.0, $this->pdf->umbruch($nr, 120.0, 8.0)[0], 8.0);
        $this->pdf->linie(self::LINKS, 36.5, self::RECHTS, 36.5, 0.4);
        $this->y = self::OBEN;

        if ($this->art === 'mess') {
            $kopf = $this->i['kopf'];
            [$label, $geraete] = $this->messgeraete();
            $info = [
                ['t' => self::verbinden(['Anlage: ' . ((string) $kopf['anlage']['bezeichnung'] !== '' ? $kopf['anlage']['bezeichnung'] : '–'),
                    (string) $kopf['anlage']['aufstellort'] !== '' ? 'Aufstellort: ' . $kopf['anlage']['aufstellort'] : '']), 'pt' => 8.0, 'fett' => true],
                ['t' => self::verbinden(['Netz: ' . $this->netzText(), 'Prüfdatum: ' . self::datum((string) $kopf['pruefdatum'])]), 'pt' => 8.0],
                ['t' => $label . ': ' . ($geraete !== [] ? implode('; ', $geraete) : '–'), 'pt' => 8.0],
            ];
            $this->y = $this->schreiben($this->zeilen($info, self::BREITE), self::LINKS, $this->y - 1.5) + 1.5;
        }
    }

    /** Fuß: Linie, vier Spalten Firmendaten, Nummer, Prüfsumme, Blatt. */
    private function fuss(int $blatt, int $gesamt): void
    {
        $firma = $this->firma();
        $this->pdf->linie(self::LINKS, self::FUSS, self::RECHTS, self::FUSS, 0.3);
        $spalten = [
            array_merge($firma['name'] !== '' ? [['t' => $firma['name'], 'pt' => 6.5, 'fett' => true]] : [],
                $this->ohneErsteZeile($firma['anschrift'], $firma['name'])),
            [['t' => $firma['kontakt'], 'pt' => 6.5]],
            [['t' => $firma['bank'], 'pt' => 6.5]],
            [['t' => $firma['register'], 'pt' => 6.5]],
        ];
        foreach ($spalten as $n => $teile) {
            $zeilen = array_slice(array_values(array_filter($this->zeilen($teile, 43.0), static fn (array $z): bool => $z['t'] !== '')), 0, 5);
            $this->schreiben($zeilen, self::LINKS + 45.0 * $n, self::FUSS + 1.0);
        }
        $hash = (string) ($this->bericht['inhalt_hash'] ?? '');
        $links = self::verbinden([
            $this->nummer(),
            'Fassung ' . (int) ($this->bericht['fassung'] ?? 1),
            $hash !== '' ? 'Prüfsumme ' . self::kurz($hash) : '',
            $this->vorschau ? $this->wasserzeichenText() : '',
        ]);
        $this->pdf->text(self::LINKS, 291.5, $links, 6.0, false, 0.25);
        $this->pdf->textRechts(self::RECHTS, 291.5, 'Blatt ' . $blatt . ' von ' . $gesamt, 6.0, false, 0.25);
    }

    // ================================================================ Bausteine

    /** Platz für $mm – sonst eine neue Seite derselben Art (mit Abschnittskopf „Fortsetzung“). */
    private function platz(float $mm): void
    {
        if ($this->y + $mm <= self::UNTEN) {
            return;
        }
        $this->neueSeite();
        if ($this->abschnitt !== '') {
            $this->abschnittsKopf($this->abschnitt . ' (Fortsetzung)', '');
        }
    }

    /** Einen Abschnitt beginnen; $zusammen: so viel soll mit dem Kopf auf dieselbe Seite. */
    private function abschnitt(string $titel, string $zusatz = '', float $zusammen = 7.0): void
    {
        $this->abschnitt = '';
        $this->platz(5.0 + $zusammen);
        $this->abschnitt = $titel;
        $this->abschnittsKopf($titel, $zusatz);
    }

    private function abschnittsKopf(string $titel, string $zusatz): void
    {
        $this->pdf->rechteck(self::LINKS, $this->y, self::BREITE, 5.0, 0.86);
        $this->pdf->text(self::LINKS + self::POLSTER_X, $this->y + 3.6, $titel, 9.0, true);
        if ($zusatz !== '') {
            $this->pdf->text(self::LINKS + self::POLSTER_X + $this->pdf->breite($titel, 9.0, true) + 2.0, $this->y + 3.6, $zusatz, 6.5, false, 0.3);
        }
        $this->y += 5.0;
    }

    private function abschnittEnde(): void
    {
        $this->abschnitt = '';
        $this->y += 2.2;
    }

    /**
     * Eine gerahmte Zeile aus Zellen nebeneinander. Zelle: b (Breite),
     * teile (Text, umbrochen), grau (Füllung), einzug (Text ab), min
     * (Mindesthöhe), zeichne (fn(x, y, b, h) für Kästchen o. Ä.).
     *
     * @param list<array<string,mixed>> $zellen
     */
    private function zeile(array $zellen, float $min = 4.8): void
    {
        $inhalt = [];
        $h = $min;
        foreach ($zellen as $n => $z) {
            $einzug = (float) ($z['einzug'] ?? 0.0);
            $inhalt[$n] = $this->zeilen((array) ($z['teile'] ?? []), (float) $z['b'] - 2 * self::POLSTER_X - $einzug);
            $h = max($h, $this->hoehe($inhalt[$n]) + 2 * self::POLSTER_Y, (float) ($z['min'] ?? 0.0));
        }
        $this->platz($h);
        $x = self::LINKS;
        foreach ($zellen as $n => $z) {
            $b = (float) $z['b'];
            $this->pdf->rechteck($x, $this->y, $b, $h, isset($z['grau']) ? (float) $z['grau'] : null);
            $this->schreiben($inhalt[$n], $x + self::POLSTER_X + (float) ($z['einzug'] ?? 0.0), $this->y + self::POLSTER_Y);
            if (isset($z['zeichne']) && is_callable($z['zeichne'])) {
                ($z['zeichne'])($x, $this->y, $b, $h);
            }
            $x += $b;
        }
        $this->y += $h;
    }

    /**
     * Eine Zeile der Kopftabelle: Beschriftung (45 mm, grau) und Wert – oder
     * zwei Paare nebeneinander. $zeichne bekommt (x, y, Breite) der Zeile.
     */
    private function kopfZeile(string $label, array $teile, ?string $label2 = null, array $teile2 = [], ?callable $zeichne = null): void
    {
        $teile = array_values(array_filter($teile, static fn (array $t): bool => trim((string) $t['t']) !== '' || $t['t'] === ' '));
        if ($teile === []) {
            $teile = [['t' => '–', 'grau' => 0.4]];
        }
        $l = ['b' => self::LABEL, 'grau' => 0.95, 'teile' => [['t' => $label, 'pt' => 7.2, 'fett' => true]]];
        if ($label2 === null) {
            $zellen = [$l, ['b' => self::BREITE - self::LABEL, 'teile' => $teile]];
        } else {
            $teile2 = array_values(array_filter($teile2, static fn (array $t): bool => trim((string) $t['t']) !== ''));
            $zellen = [$l, ['b' => 47.0, 'teile' => $teile], ['b' => 30.0, 'grau' => 0.95, 'teile' => [['t' => $label2, 'pt' => 7.2, 'fett' => true]]],
                ['b' => self::BREITE - self::LABEL - 47.0 - 30.0, 'teile' => $teile2 !== [] ? $teile2 : [['t' => '–', 'grau' => 0.4]]]];
        }
        if ($zeichne !== null) {
            $zellen[0]['zeichne'] = $zeichne;
        }
        $this->zeile($zellen);
    }

    /** „… : ☒ Ja ☐ Nein“ – Kästchen als Pfade. */
    private function janeinZeile(string $frage, string $wert): void
    {
        $this->zeile([
            ['b' => self::BREITE - 40.0, 'teile' => [['t' => $frage . ':']]],
            ['b' => 40.0, 'teile' => [], 'zeichne' => function (float $x, float $y, float $b, float $h) use ($wert): void {
                foreach (['ja' => 'Ja', 'nein' => 'Nein'] as $w => $text) {
                    $this->pdf->kaestchen($x + 3.0, $y + ($h - 3.4) / 2, $wert === $w ? 'x' : '', 3.4);
                    $this->pdf->text($x + 7.6, $y + $h / 2 + 1.1, $text, self::PT, $wert === $w);
                    $x += 19.0;
                }
            }],
        ]);
    }

    /**
     * Teile in Zeilen für eine Breite umbrechen.
     *
     * @param list<array{t:string,pt?:float,fett?:bool,grau?:float}> $teile
     * @return list<array{t:string,pt:float,fett:bool,grau:float}>
     */
    private function zeilen(array $teile, float $breite): array
    {
        $out = [];
        foreach ($teile as $t) {
            $text = (string) ($t['t'] ?? '');
            if (trim($text) === '' && $text !== ' ') {
                continue;
            }
            $pt = (float) ($t['pt'] ?? self::PT);
            $fett = (bool) ($t['fett'] ?? false);
            foreach ($this->pdf->umbruch($text, max(5.0, $breite), $pt, $fett) as $z) {
                $out[] = ['t' => $z, 'pt' => $pt, 'fett' => $fett, 'grau' => (float) ($t['grau'] ?? 0.0)];
            }
        }
        return $out;
    }

    private function hoehe(array $zeilen): float
    {
        $h = 0.0;
        foreach ($zeilen as $z) {
            $h += Tabelle::zeilenhoehe($z['pt']);
        }
        return $h;
    }

    /** Zeilen ab (x, oberer Rand y) schreiben; gibt den unteren Rand zurück. */
    private function schreiben(array $zeilen, float $x, float $y): float
    {
        foreach ($zeilen as $z) {
            $h = Tabelle::zeilenhoehe($z['pt']);
            $this->pdf->text($x, $y + $h * 0.78, $z['t'], $z['pt'], $z['fett'], $z['grau']);
            $y += $h;
        }
        return $y;
    }

    /** Legende mit Haken/Kreuz als Pfad. @param list<array{0:string,1:string}> $eintraege */
    private function legende(float $x, float $grundlinie, array $eintraege, float $pt = 6.5): void
    {
        foreach ($eintraege as $n => [$symbol, $text]) {
            if ($n > 0) {
                $this->pdf->text($x, $grundlinie, '·', $pt, false, 0.3);
                $x += $this->pdf->breite('· ', $pt);
            }
            if ($symbol !== '') {
                $this->pdf->kaestchen($x, $grundlinie - 2.3, $symbol === 'haken' ? 'io' : 'nio', 2.6);
                $x += 3.4;
            }
            $this->pdf->text($x, $grundlinie, $text, $pt, false, 0.3);
            $x += $this->pdf->breite($text . ' ', $pt);
        }
    }

    // ================================================================ Texte

    /** @return array{name:string,zusatz:string,anschrift:string,kontakt:string,bank:string,register:string} */
    private function firma(): array
    {
        $f = is_array($this->i['auftragnehmer'] ?? null) ? $this->i['auftragnehmer'] : [];
        $out = [];
        foreach (['name', 'zusatz', 'anschrift', 'kontakt', 'bank', 'register'] as $feld) {
            $out[$feld] = trim((string) ($f[$feld] ?? ''));
        }
        return $out;
    }

    /** Anschrift ohne eine erste Zeile, die nur den Firmennamen wiederholt. */
    private function ohneErsteZeile(string $anschrift, string $name): array
    {
        $zeilen = preg_split('/\R/u', $anschrift) ?: [];
        if ($zeilen !== [] && trim((string) $zeilen[0]) === $name) {
            array_shift($zeilen);
        }
        return [['t' => implode("\n", $zeilen), 'pt' => 6.5]];
    }

    /** @return array{nummer:string,fassung:string,inhalt_hash:string,abgeschlossen_am:string,grund:string}|null */
    private function vorgaenger(): ?array
    {
        $v = $this->i['vorgaenger'] ?? null;
        if (!is_array($v)) {
            return null;
        }
        $out = [];
        foreach (['nummer', 'fassung', 'inhalt_hash', 'abgeschlossen_am', 'grund'] as $feld) {
            $out[$feld] = (string) ($v[$feld] ?? '');
        }
        return $out;
    }

    private function nummer(): string
    {
        $nummer = (string) ($this->bericht['nummer'] ?? '');
        return $nummer !== '' ? $nummer : 'Entwurf #' . (int) ($this->bericht['id'] ?? 0);
    }

    private function netzText(): string
    {
        $n = $this->i['netz'];
        $einspeisung = (string) ($this->labels('einspeisungen', Katalog::einspeisungen())[(string) $n['einspeisung']] ?? '');
        $spannung = (string) $n['spannung'];
        return self::verbinden([
            $spannung === '400' ? '400/230 V' : ($spannung !== '' ? Felder::komma($spannung) . ' V' : ''),
            (string) $n['frequenz'] !== '' ? $n['frequenz'] . ' Hz' : '',
            (string) $n['form'],
            $einspeisung !== '' ? 'Einspeisung: ' . $einspeisung : '',
            (string) $n['erder'] !== '' ? 'Erder: ' . $n['erder'] : '',
            (string) $n['u_ln'] !== '' ? 'U(L-N) gemessen ' . Felder::komma((string) $n['u_ln']) . ' V' : '',
            (string) $n['drehfeld'] !== '' ? 'Drehfeld an der Einspeisung: ' . $n['drehfeld'] : '',
        ]) ?: '–';
    }

    private function normenText(): string
    {
        $normen = $this->labels('normen', Katalog::normen());
        $liste = array_map(static fn ($n): string => (string) ($normen[$n] ?? $n), (array) $this->i['grundlage']['normen']);
        if ((string) $this->i['grundlage']['sonstige'] !== '') {
            $liste[] = (string) $this->i['grundlage']['sonstige'];
        }
        return $liste !== [] ? implode(', ', $liste) : '–';
    }

    /** @return array{0:string,1:list<string>} [Beschriftung, Geräte] */
    private function messgeraete(): array
    {
        $geraete = array_values(array_filter(is_array($this->i['messgeraete'] ?? null) ? $this->i['messgeraete'] : [], 'is_array'));
        $normen = array_values(array_unique(array_filter(array_map(static fn ($g): string => trim((string) ($g['norm'] ?? '')), $geraete))));
        $gemeinsam = count($normen) === 1 && count(array_filter($geraete, static fn ($g): bool => trim((string) ($g['norm'] ?? '')) === '')) === 0 ? $normen[0] : '';
        $liste = [];
        foreach ($geraete as $g) {
            $liste[] = trim((string) ($g['fabrikat'] ?? '') . ' ' . (string) ($g['typ'] ?? ''))
                . ((string) ($g['seriennummer'] ?? '') !== '' ? ', Nr. ' . $g['seriennummer'] : '')
                . ((string) ($g['kalibriert_bis'] ?? '') !== '' ? ', kalibriert bis ' . self::datum((string) $g['kalibriert_bis']) : '')
                . ($gemeinsam === '' && trim((string) ($g['norm'] ?? '')) !== '' ? ' (' . $g['norm'] . ')' : '');
        }
        return [$gemeinsam !== '' ? 'Messgeräte nach ' . $gemeinsam : 'Messgeräte', $liste];
    }

    /** @return array{gemessen:int,nicht:int,offen:int,hinweis:int,mangel:int} */
    private function zaehlen(): array
    {
        $z = ['gemessen' => 0, 'nicht' => 0, 'offen' => 0, 'hinweis' => 0, 'mangel' => 0];
        foreach ($this->i['kreise'] as $k) {
            $auto = (string) ($this->i['bewertung']['kreise'][(string) $k['z']]['auto'] ?? '');
            $stufe = match ((string) $k['pruefer']) {
                'io'    => 'ok',
                'nio'   => 'mangel',
                default => $auto,
            };
            if ((string) $k['status'] === 'nicht_gemessen' || $auto === 'entf') {
                $z['nicht']++;
            } elseif ($auto === '') {
                $z['offen']++;
            } else {
                $z['gemessen']++;
            }
            if ($stufe === 'hinweis') {
                $z['hinweis']++;
            } elseif ($stufe === 'mangel') {
                $z['mangel']++;
            }
        }
        return $z;
    }

    /** Texte aus den festen Kopien (`labels`), sonst aus dem Katalog. */
    private function labels(string $teil, array $ersatz): array
    {
        $l = $this->i['labels'][$teil] ?? null;
        return is_array($l) && $l !== [] ? $l + $ersatz : $ersatz;
    }

    /** Marke einer Fußnote des Messprotokolls – derselbe Text, dieselbe Marke. */
    private function fussnote(string $text): string
    {
        return $this->fussnoten[$text] ??= self::hoch(count($this->fussnoten) + 1);
    }

    private function wasserzeichenText(): string
    {
        return match ((string) ($this->bericht['status'] ?? '')) {
            'geprueft'  => 'VORSCHAU – Kundenbestätigung fehlt',
            'verworfen' => 'VERWORFEN – nicht gültig',
            default     => 'VORSCHAU – nicht abgeschlossen',
        };
    }

    private function erzeugtAm(): string
    {
        $b = $this->bericht;
        $kandidaten = $this->vorschau
            ? [$b['eingefroren_am'] ?? null, $b['updated_at'] ?? null, $b['created_at'] ?? null]
            : [$b['abgeschlossen_am'] ?? null, $b['eingefroren_am'] ?? null, $b['updated_at'] ?? null];
        foreach ($kandidaten as $k) {
            if (is_string($k) && $k !== '' && strtotime($k) !== false) {
                return $k;
            }
        }
        return '2000-01-01T00:00:00+00:00';
    }

    private function dokumentId(): string
    {
        $hash = (string) ($this->bericht['inhalt_hash'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            try {
                $hash = Kanon::hash($this->i);
            } catch (\Throwable) {
                $hash = hash('sha256', (string) json_encode($this->i));
            }
        }
        return substr($hash, 0, 32);
    }

    // ================================================================ kleine Hilfen

    /** Messwert mit Operator: „> 550“, „0,42“. */
    private static function wert(array $w): string
    {
        $v = trim((string) ($w['w'] ?? ''));
        if ($v === '') {
            return '';
        }
        $op = (string) ($w['op'] ?? '');
        return ($op === '<' || $op === '>' ? $op . ' ' : '') . Felder::komma($v);
    }

    private static function datum(string $d): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : ($d !== '' ? $d : '–');
    }

    /** Die ersten 16 Zeichen einer Prüfsumme in Vierergruppen. */
    private static function kurz(string $hash): string
    {
        return implode(' ', str_split(substr($hash, 0, 16), 4));
    }

    private static function hoch(int $n): string
    {
        return $n <= 9 ? self::HOCH[(string) $n] : '(' . $n . ')';
    }

    private static function verbinden(array $teile, string $mit = ' · '): string
    {
        return implode($mit, array_filter(array_map(static fn ($t): string => trim((string) $t), $teile), static fn (string $t): bool => $t !== ''));
    }

    private static function einzeilig(string $text): string
    {
        return self::verbinden(preg_split('/\R/u', $text) ?: [], ', ');
    }
}
