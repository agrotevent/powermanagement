<?php
declare(strict_types=1);

namespace App\Kunden;

use App\Auth\Zugriff;
use App\Berichte\BerichtPdf;
use App\Berichte\BerichtSchema;
use App\Berichte\BerichtService;
use App\Berichte\Grenzwerte;
use App\Services\AnlagenService;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Zeit;

/**
 * Was ein Kunde sieht – für den Kundenbereich und für die Anzeige „Der Kunde
 * sieht derzeit …“ beim Personal (später auch die Kundenansicht).
 *
 * Jede Methode nimmt die Kunden-ID ausdrücklich: kein angemeldetes Konto,
 * kein globaler Zustand, der in andere Pfade sickert. Locations kommen nur
 * aus den wirksamen Freigaben am Kunden (Kundenbereich::freigaben() – DAS
 * Prädikat; ein beendeter Kunde hat keine). Jede weitere ID (Etage) wird
 * einzeln gegen die Location geprüft; fremd und nicht vorhanden sind
 * dasselbe: null, im Kundenbereich 404.
 *
 * Protokolle (Übergabeberichte) kommen nie über den Standort, sondern nur
 * über die ausdrückliche Zuordnung (berichte.kunde_id,
 * BerichtService::sichtbarFuerKunde): abgeschlossen oder ersetzt, das PDF
 * nur gespeichert – nie eine Vorschau. Sie bleiben, wenn die
 * Location-Freigabe abläuft, und enden mit dem Kunden.
 *
 * Herausgegeben werden nur ausdrücklich genannte Felder – nie sites.notes,
 * floors.notes, floors.fremdbestand, kunden.notizen, mobile Anlagen,
 * Kabel, Messgeräte oder Einsätze. Eventworx wird von hier aus nie gefragt.
 */
final class KundenSicht
{
    /**
     * Die Locations, die der Kunde jetzt sieht – je Standort einmal, mit den
     * Freigaben dorthin (Veranstaltung, sichtbar bis, Messdaten). Nach Name.
     *
     * @return list<array{site:array{id:int,name:string,address:string},freigaben:list<array{veranstaltung:string,ab:?string,bis:?string,daten_ab:?string,daten_bis:?string,daten:string}>}>
     */
    public static function locations(int $kundeId, ?int $jetzt = null): array
    {
        $jeStandort = [];
        foreach (Kundenbereich::freigaben($kundeId, $jetzt) as $f) {
            $jeStandort[(int) $f['ziel_id']][] = self::freigabe($f);
        }
        $out = [];
        foreach ($jeStandort as $siteId => $freigaben) {
            $site = self::standort($siteId);
            if ($site === null) {
                continue;   // Standort gelöscht – seine Freigaben gehen mit (KundenService::standortEntfernt)
            }
            usort($freigaben, static fn (array $a, array $b): int => strcmp((string) $a['bis'], (string) $b['bis']));
            $out[] = ['site' => $site, 'freigaben' => $freigaben];
        }
        usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['site']['name'], $b['site']['name']) ?: $a['site']['id'] <=> $b['site']['id']);
        return $out;
    }

    /**
     * Locations, die der Kunde erst noch sehen wird – nur Name und „ab …“
     * (früheste Freigabe je Standort). Was er jetzt schon sieht, steht hier
     * nicht noch einmal.
     *
     * @return list<array{name:string,ab:string}>
     */
    public static function kommend(int $kundeId, ?int $jetzt = null): array
    {
        $sichtbar = array_map(static fn (array $f): int => (int) $f['ziel_id'], Kundenbereich::freigaben($kundeId, $jetzt));
        $ab = [];
        foreach (Kundenbereich::kommend($kundeId, $jetzt) as $f) {
            $siteId = (int) $f['ziel_id'];
            if (in_array($siteId, $sichtbar, true)) {
                continue;
            }
            $ts = (int) Zeit::ts((string) $f['gueltig_ab']);
            $ab[$siteId] = isset($ab[$siteId]) ? min($ab[$siteId], $ts) : $ts;
        }
        asort($ab);
        $out = [];
        foreach ($ab as $siteId => $ts) {
            $site = self::standort($siteId);
            if ($site !== null) {
                $out[] = ['name' => $site['name'], 'ab' => Zeit::iso($ts)];
            }
        }
        return $out;
    }

    /**
     * Eine Location, wie der Kunde sie sieht – oder null (nicht freigegeben,
     * nicht vorhanden, Kunde beendet: dasselbe).
     *
     * etagen: nur Etagen mit Plan; feststrom: nur fest installierte Anlagen
     * dieses Standorts (keine Vorlagen, nie mobile); berichte: die Protokolle
     * des Kunden an diesem Standort (berichte()).
     *
     * @return array{site:array{id:int,name:string,address:string},freigaben:list<array<string,mixed>>,etagen:list<array{id:int,name:string,plan:string,seite:int}>,feststrom:list<array{name:string,art:string,etage:string,anschluss:string,vorsicherung:string}>,berichte:list<array<string,mixed>>}|null
     */
    public static function location(int $kundeId, int $siteId, ?int $jetzt = null): ?array
    {
        $eintrag = null;
        foreach (self::locations($kundeId, $jetzt) as $l) {
            if ($l['site']['id'] === $siteId) {
                $eintrag = $l;
                break;
            }
        }
        if ($eintrag === null) {
            return null;
        }
        $etagen = [];
        $etagenNamen = [];
        foreach (self::etagenZeilen($siteId) as $f) {
            $etagenNamen[(int) $f['id']] = (string) $f['name'];
            $art = self::planArt($f);
            if ($art !== '') {
                $etagen[] = ['id' => (int) $f['id'], 'name' => (string) $f['name'], 'plan' => $art,
                    'seite' => $art === 'pdf' ? max(1, (int) $f['plan_page']) : 1];
            }
        }
        $feststrom = [];
        foreach (AnlagenService::liste(['site_id' => $siteId, 'fest' => true]) as $o) {
            $e = $o['eigenschaften'];
            $art = $o['art'] === 'anschluss' && is_string($e['anschlussart'] ?? null) && $e['anschlussart'] !== ''
                ? (string) $e['anschlussart'] : (string) $o['art_anzeige'];
            $vorsicherung = is_numeric($e['absicherung_a'] ?? null) && (float) $e['absicherung_a'] > 0
                ? rtrim(rtrim(number_format((float) $e['absicherung_a'], 1, ',', ''), '0'), ',') . ' A' : '';
            $feststrom[] = [
                'name'         => (string) $o['name'],
                'art'          => $art,
                // Nur eine Etage dieses Standorts – nie ein Name von anderswo.
                'etage'        => $etagenNamen[(int) ($o['floor_id'] ?? 0)] ?? '',
                'anschluss'    => AnlagenService::kurzform((int) $o['id']),
                'vorsicherung' => $vorsicherung,
            ];
        }
        return $eintrag + ['etagen' => $etagen, 'feststrom' => $feststrom, 'berichte' => self::berichte($kundeId, $siteId, $jetzt)];
    }

    /**
     * Eine Etage mit Plan – nur, wenn sie zu genau dieser Location gehört,
     * die Location für den Kunden sichtbar ist und ein Plan vorliegt. Nur die
     * Felder, die die Auslieferung braucht (SiteService::planAusliefern).
     *
     * @return array{id:int,site_id:int,name:string,plan_file:string,plan_type:string,plan_page:int,plan_original_name:string}|null
     */
    public static function etage(int $kundeId, int $siteId, int $floorId, ?int $jetzt = null): ?array
    {
        $f = Database::first('SELECT id, site_id, name, plan_file, plan_type, plan_page, plan_original_name FROM floors WHERE id = ?', [$floorId]);
        if ($f === null || (int) $f['site_id'] !== $siteId || self::planArt($f) === '') {
            return null;
        }
        if (!in_array($siteId, array_map(static fn (array $l): int => $l['site']['id'], self::locations($kundeId, $jetzt)), true)) {
            return null;
        }
        return ['id' => (int) $f['id'], 'site_id' => (int) $f['site_id'], 'name' => (string) $f['name'], 'plan_file' => (string) $f['plan_file'],
            'plan_type' => (string) $f['plan_type'], 'plan_page' => (int) $f['plan_page'], 'plan_original_name' => (string) $f['plan_original_name']];
    }

    /**
     * Die Protokolle (Übergabeberichte) des Kunden, neueste Prüfung zuerst –
     * je Nummer ein Eintrag: die gültige Fassung, frühere (ersetzte) darunter
     * in `fruehere`. Nur Listenfelder, kein Inhalt. Mit $siteId nur die an
     * dieser Location. Kunde beendet → [].
     *
     * @return list<array{id:int,nummer:string,fassung:int,status:string,titel:string,veranstaltung:string,standort:string,pruefdatum:string,abgeschlossen_am:?string,pdf:bool,ersetzt_durch:?string,fruehere:list<array<string,mixed>>}>
     */
    public static function berichte(int $kundeId, ?int $siteId = null, ?int $jetzt = null): array
    {
        if (Kundenbereich::kundeSperre($kundeId, $jetzt) !== null) {
            return [];
        }
        $zeilen = BerichtService::fuerKunde($kundeId, $siteId);
        $nachId = [];
        foreach ($zeilen as $b) {
            $nachId[(int) $b['id']] = $b;
        }
        $familien = [];
        foreach ($zeilen as $b) {
            $schluessel = $b['jahr'] !== null && $b['laufnr'] !== null ? $b['jahr'] . '-' . $b['laufnr'] : 'id-' . $b['id'];
            $familien[$schluessel][] = $b;
        }
        $out = [];
        foreach ($familien as $fassungen) {
            // Die gültige Fassung zuerst: abgeschlossen vor ersetzt, dann die höchste.
            usort($fassungen, static fn (array $x, array $y): int => [(int) ($y['status'] === 'abgeschlossen'), (int) $y['fassung']]
                <=> [(int) ($x['status'] === 'abgeschlossen'), (int) $x['fassung']]);
            $haupt = self::berichtZeile(array_shift($fassungen), $nachId);
            $haupt['fruehere'] = array_map(static fn (array $f): array => self::berichtZeile($f, $nachId), $fassungen);
            $out[] = $haupt;
        }
        usort($out, static fn (array $x, array $y): int => [$y['pruefdatum'], $y['id']] <=> [$x['pruefdatum'], $x['id']]);
        return $out;
    }

    /**
     * Ein Protokoll zum Ansehen – oder null (nicht zugeordnet, nicht
     * abgeschlossen, Kunde beendet, nicht vorhanden: dasselbe). Inhalt,
     * Bewertung und Unterschriften wie im PDF; dazu die Prüfsumme des
     * gespeicherten PDF und die Nachbarfassungen, soweit der Kunde sie sieht.
     *
     * @return array{bericht:array<string,mixed>,inhalt:array,bewertung:array,unterschriften:array,pdf:?array{name:string,sha256:string},vorgaenger:?array{id:int,nummer:string,fassung:int},nachfolger:?array{id:int,nummer:string,fassung:int}}|null
     */
    public static function bericht(int $kundeId, int $id): ?array
    {
        if (Kundenbereich::kundeSperre($kundeId) !== null) {
            return null;
        }
        $b = BerichtService::find($id);
        if ($b === null || !BerichtService::sichtbarFuerKunde($b, $kundeId)) {
            return null;
        }
        $nachbar = static function (?int $nid) use ($kundeId): ?array {
            $n = $nid !== null ? BerichtService::find($nid) : null;
            return $n !== null && BerichtService::sichtbarFuerKunde($n, $kundeId)
                ? ['id' => (int) $n['id'], 'nummer' => (string) $n['nummer'], 'fassung' => (int) $n['fassung']] : null;
        };
        $inhalt = BerichtSchema::vollstaendig($b['inhalt']);
        $sha = BerichtService::pdfHash((int) $b['id']);
        return [
            // Nur, was die Ansicht braucht – nie Ersteller, Bearbeiter, Verweise nach Eventworx.
            'bericht'        => ['id' => (int) $b['id'], 'nummer' => (string) $b['nummer'], 'fassung' => (int) $b['fassung'], 'status' => (string) $b['status'],
                'titel' => (string) $b['titel'], 'veranstaltung' => (string) $b['veranstaltung'], 'standort' => (string) $b['standort_name'],
                'pruefdatum' => (string) $b['pruefdatum'], 'abgeschlossen_am' => $b['abgeschlossen_am'], 'korrektur_grund' => (string) $b['korrektur_grund']],
            'inhalt'         => $inhalt,
            'bewertung'      => is_array($inhalt['bewertung'] ?? null) ? $inhalt['bewertung'] : Grenzwerte::alle($inhalt),
            'unterschriften' => BerichtService::unterschriften((int) $b['id']),
            'pdf'            => $sha !== null ? ['name' => BerichtPdf::dateiname($b), 'sha256' => $sha] : null,
            'vorgaenger'     => $nachbar($b['vorgaenger_id']),
            'nachfolger'     => $nachbar($b['ersetzt_durch_id']),
        ];
    }

    /**
     * Das PDF eines Protokolls für den Kunden: nur die beim Abschluss
     * gespeicherten Bytes – nie eine Vorschau, nie eine Neuerzeugung. Stimmt
     * ihre Prüfsumme nicht mit der gespeicherten überein, gibt es nichts
     * (Logger::error ohne Inhalt): der Kunde bekäme sonst etwas anderes als
     * das, was beim Abschluss festgehalten wurde.
     *
     * @return array{name:string,bytes:string,sha256:string}|null
     */
    public static function protokollPdf(int $kundeId, int $id): ?array
    {
        if (Kundenbereich::kundeSperre($kundeId) !== null) {
            return null;
        }
        $b = BerichtService::find($id);
        if ($b === null || !BerichtService::sichtbarFuerKunde($b, $kundeId)) {
            return null;
        }
        $bytes = BerichtService::pdfGespeichert($id);
        $sha = BerichtService::pdfHash($id);
        if ($bytes === null || $sha === null) {
            return null;
        }
        if (!hash_equals($sha, hash('sha256', $bytes))) {
            Logger::error('Kundenbereich: gespeichertes PDF weicht von seiner Prüfsumme ab – nicht ausgeliefert', ['bericht_id' => $id]);
            return null;
        }
        return ['name' => BerichtPdf::dateiname($b), 'bytes' => $bytes, 'sha256' => $sha];
    }

    /**
     * Für das Personal: „Der Kunde sieht derzeit …“ – aus denselben
     * Prädikaten wie der Kundenbereich. gesperrt: null oder der Grund
     * (Kunde beendet oder abgelaufen; dann sieht er nichts).
     *
     * @return array{gesperrt:?string,locations:list<array<string,mixed>>,kommend:list<array{name:string,ab:string}>,berichte:list<array<string,mixed>>}
     */
    public static function zusammenfassung(int $kundeId, ?int $jetzt = null): array
    {
        return [
            'gesperrt'  => Kundenbereich::kundeSperre($kundeId, $jetzt),
            'locations' => self::locations($kundeId, $jetzt),
            'kommend'   => self::kommend($kundeId, $jetzt),
            'berichte'  => self::berichte($kundeId, null, $jetzt),
        ];
    }

    // ------------------------------------------------------------ intern

    /**
     * Ein Protokoll in der Liste – nur Listenfelder. ersetzt_durch: die
     * Nummer der Nachfolgerin, wenn der Kunde sie auch sieht.
     *
     * @param array<int,array<string,mixed>> $nachId die Berichte des Kunden
     */
    private static function berichtZeile(array $b, array $nachId): array
    {
        $nachfolger = $b['ersetzt_durch_id'] !== null ? ($nachId[(int) $b['ersetzt_durch_id']] ?? null) : null;
        return [
            'id'               => (int) $b['id'],
            'nummer'           => (string) $b['nummer'],
            'fassung'          => (int) $b['fassung'],
            'status'           => (string) $b['status'],
            'titel'            => (string) $b['titel'],
            'veranstaltung'    => (string) $b['veranstaltung'],
            'standort'         => (string) $b['standort_name'],
            'pruefdatum'       => (string) $b['pruefdatum'],
            'abgeschlossen_am' => $b['abgeschlossen_am'] !== null ? (string) $b['abgeschlossen_am'] : null,
            'pdf'              => $b['pdf_sha256'] !== null,
            'ersetzt_durch'    => $nachfolger !== null ? (string) $nachfolger['nummer'] : null,
        ];
    }

    /** @return array{id:int,name:string,address:string}|null nur Name und Anschrift – nie die Notizen */
    private static function standort(int $siteId): ?array
    {
        $s = Database::first('SELECT id, name, address FROM sites WHERE id = ?', [$siteId]);
        return $s === null ? null : ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'address' => (string) $s['address']];
    }

    /** @return list<array<string,mixed>> Etagen des Standorts – ohne Notizen und Fremdbestand */
    private static function etagenZeilen(int $siteId): array
    {
        return Database::select('SELECT id, name, plan_file, plan_page FROM floors WHERE site_id = ? ORDER BY sort_order, level_no, name, id', [$siteId]);
    }

    /** 'pdf', 'bild' – oder '' ohne (vorhandenen) Plan. */
    private static function planArt(array $etage): string
    {
        if (SiteService::planPath($etage) === null) {
            return '';
        }
        return SiteService::planMime($etage) === 'application/pdf' ? 'pdf' : 'bild';
    }

    /**
     * Eine wirksame Kunden-Freigabe, wie der Kunde sie lesen darf: die
     * Veranstaltung (notiz), wie lange sichtbar, welche Messdaten. Ein
     * leerer Datenzeitraum (Kundenbereich::freigaben) heißt „keine“.
     */
    private static function freigabe(array $f): array
    {
        $a = Zeit::ts($f['daten_ab'] ?? null);
        $b = Zeit::ts($f['daten_bis'] ?? null);
        $leer = $a === null || $b === null || $a >= $b;
        return [
            'veranstaltung' => (string) ($f['notiz'] ?? ''),
            'ab'            => $f['gueltig_ab'] ?? null,
            'bis'           => $f['gueltig_bis'] ?? null,
            'daten_ab'      => $leer ? null : $f['daten_ab'],
            'daten_bis'     => $leer ? null : $f['daten_bis'],
            // Als ganzer Satzteil, so in den Ansichten: „Messdaten vom … bis …“ bzw. „keine Messdaten freigegeben“.
            'daten'         => $leer ? Zugriff::beschreiben([])
                : 'Messdaten vom ' . Zeit::anzeige((string) $f['daten_ab']) . ' bis ' . Zeit::anzeige((string) $f['daten_bis']),
        ];
    }
}
