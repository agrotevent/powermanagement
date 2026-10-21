<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Database;
use App\Support\Fingerabdruck;
use App\Support\Logger;
use App\Tanks\TankService;

/**
 * Locations einer anderen Instanz (Seite B): Liste mit Zustand, Übernehmen,
 * Zuordnen, Nur verknüpfen, Lösen.
 *
 * Ein übernommener Standort merkt sich, woher er kommt: partner_id und
 * partner_schluessel (die Location dort), partner_stand (ihr „stand“ beim
 * letzten Übernehmen) und partner_hash (der eigene Fingerabdruck direkt
 * danach, hierStand()). Daraus ergibt sich der Zustand: aktuell, dort
 * geändert, hier geändert oder beide geändert. Etagen, Anlagen und Kabel
 * tragen den Schlüssel von dort in partner_schluessel – der eigene sync_key
 * (Location-Abgleich über Git) bleibt unberührt.
 *
 * Übernommen wird über LocationUebertrag – derselbe Weg, auf dem Seite A
 * einen Vorschlag annimmt: Namen von Standort und Etagen, die Schaltsperre
 * und die Messpunkte hier bleiben, ein fehlender Maßstab oder eine fehlende
 * Georeferenz dort löscht hier nichts, gelöscht wird nichts. Zugeordnet wird
 * nur innerhalb des Ziel-Standorts – nie wandert eine Anlage von einem
 * anderen Standort herüber.
 *
 * Rechte prüft diese Klasse selbst (ok: false mit Meldung): übernehmen und
 * verknüpfen nur mit „Standorte pflegen“ und – für einen vorhandenen
 * Standort – einer Freigabe mit „bearbeiten“; Feststrom zusätzlich nur mit
 * „Anlagen ändern“ und „Kabel einzeichnen“; fehlende Steckertypen nur mit
 * dem Recht, den Katalog zu pflegen.
 */
final class PartnerAbgleich
{
    /** Zustand einer Location dort → [Text, Badge-Klasse]. */
    public const ZUSTAENDE = [
        'neu'        => ['noch nicht hier', ''],
        'passt'      => ['passt zu einem Standort hier', 'info'],
        'verknuepft' => ['verknüpft – noch nicht übernommen', 'warn'],
        'aktuell'    => ['aktuell', 'ok'],
        'dort'       => ['dort geändert', 'info'],
        'hier'       => ['hier geändert', 'warn'],
        'beide'      => ['beide geändert', 'bad'],
        // Verknüpft mit einem Standort, den der Benutzer nicht sieht – ohne
        // Vergleich: der Zustand verriete etwas über den Standort.
        'verborgen'  => ['verknüpft', ''],
    ];

    /** Woran eine Etage oder ein Standort hier als passend erkannt wurde. */
    public const GRUENDE = ['verknuepft' => 'verknüpft', 'schluessel' => 'gleicher Schlüssel', 'name' => 'gleicher Name'];

    /** Schlüssel einer Location dort (wie MasterAdmin sie in Adressen nimmt). */
    private const SCHLUESSEL = '/^[a-z0-9-]{1,190}$/';

    /** Plandatei dort: wie MasterAdmin sie nennt; webp kann PowerManagement nicht anzeigen. */
    private const PLANDATEI = '#^plaene/[a-z0-9-]+\.(pdf|png|jpe?g|svg|webp)$#';

    /**
     * Platz im Körper einer Sendung, der für Location und Feststrom (JSON)
     * frei bleibt – die Plandateien (base64) teilen sich den Rest von
     * VorschlagService::MAX_KOERPER.
     */
    private const PLATZ_FUER_JSON = 4 * 1024 * 1024;

    // ---------------------------------------------------------------- Liste

    /**
     * Die Locations dort – je mit Zustand und dem, was hier dazu passt.
     *
     * @return array{ok:bool,message:string,locations:list<array<string,mixed>>,uebergangen:int}
     */
    public static function locations(array $partner): array
    {
        if (!PartnerService::darfDort($partner, 'plaene.lesen')) {
            return ['ok' => false, 'message' => 'Das Token darf dort keine Grundrisse lesen.', 'locations' => [], 'uebergangen' => 0];
        }
        $r = PartnerService::anfrage($partner, 'GET', '/locations');
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'locations' => [], 'uebergangen' => 0];
        }
        if (!is_array($r['daten']['locations'] ?? null)) {
            return ['ok' => false, 'message' => 'Die Gegenseite schickt keine Liste der Locations.', 'locations' => [], 'uebergangen' => 0];
        }
        $pm = PartnerService::istPowerManagement($partner);
        $feststromDort = $pm && PartnerService::darfDort($partner, 'feststrom');
        $liste = [];
        $uebergangen = 0;
        foreach ($r['daten']['locations'] as $l) {
            $key = is_array($l) && is_string($l['schluessel'] ?? null) ? $l['schluessel'] : '';
            if (preg_match(self::SCHLUESSEL, $key) !== 1) {
                $uebergangen++;
                continue;
            }
            $etagen = [];
            foreach ((array) ($l['etagen'] ?? []) as $e) {
                $ek = is_array($e) && is_string($e['schluessel'] ?? null) ? $e['schluessel'] : '';
                if (!LocationBundle::schluesselFormat($ek) || isset($etagen[$ek])) {
                    continue;
                }
                $etagen[$ek] = [
                    'schluessel' => $ek,
                    'name'       => self::text($e['name'] ?? '', 190) ?: $ek,
                    'plan'       => !empty($e['plan']),
                    'bearbeiten' => ($e['bearbeiten'] ?? false) === true,
                    'anlagen'    => is_int($e['anlagen'] ?? null) ? $e['anlagen'] : null,
                    'leitungen'  => is_int($e['leitungen'] ?? null) ? $e['leitungen'] : null,
                ];
            }
            $etagen = array_values($etagen);
            $name = self::text($l['name'] ?? '', 190) ?: $key;
            $stand = is_string($l['stand'] ?? null) && preg_match('/^[0-9a-f]{1,64}$/', $l['stand']) === 1 ? $l['stand'] : '';

            $hier = self::verknuepft((int) $partner['id'], $key);
            if ($hier !== null && !Zugriff::siehtStandort((int) $hier['id'])) {
                // Verknüpft mit einem Standort, den der Benutzer nicht sieht:
                // weder dessen Etagen noch sein Zustand – nur, welche Etage
                // dort überhaupt verknüpft ist.
                $verknuepft = array_flip(array_map('strval', array_column(Database::select(
                    "SELECT partner_schluessel FROM floors WHERE site_id = ? AND partner_schluessel <> ''", [(int) $hier['id']]), 'partner_schluessel')));
                foreach ($etagen as $i => $e) {
                    $etagen[$i]['ziel'] = isset($verknuepft[$e['schluessel']]) ? ['grund' => 'verknuepft'] : null;
                }
                $liste[] = [
                    'schluessel' => $key, 'name' => $name, 'stand' => $stand, 'etagen' => $etagen,
                    'hier' => ['id' => 0, 'name' => ''], 'sichtbar' => false, 'vorschlag' => null, 'zustand' => 'verborgen',
                    'aendern' => false, 'loesen' => false, 'bearbeitbar' => 0, 'git' => false, 'feststrom' => false,
                ];
                continue;
            }
            $vorschlag = $hier === null ? self::vorschlagStandort($key, $name) : null;
            $zielId = (int) ($hier['id'] ?? $vorschlag['id'] ?? 0);
            $ziele = self::etagenVorschlag($etagen, $zielId);
            $bearbeitbar = 0;
            foreach ($etagen as $i => $e) {
                $etagen[$i]['ziel'] = $ziele[$e['schluessel']] ?? null;
                if ($hier !== null && $e['bearbeiten'] && ($etagen[$i]['ziel']['grund'] ?? '') === 'verknuepft') {
                    $bearbeitbar++;
                }
            }

            $zustand = self::zustandVon($hier, $stand, $vorschlag);
            $aendern = Auth::can('plans.manage') && ($zielId > 0 ? Zugriff::darfStandortAendern($zielId) : true);
            $liste[] = [
                'schluessel'  => $key,
                'name'        => $name,
                'stand'       => $stand,
                'etagen'      => $etagen,
                'hier'        => $hier,
                'sichtbar'    => true,
                'vorschlag'   => $vorschlag,
                'zustand'     => $zustand,
                'aendern'     => $aendern,
                'loesen'      => $hier !== null && Zugriff::darfStandortAendern((int) $hier['id']),
                'bearbeitbar' => $bearbeitbar,
                'git'         => $hier !== null && (string) ($hier['synced_at'] ?? '') !== '',
                'feststrom'   => $feststromDort && $aendern && self::darfFeststrom($zielId > 0 ? $zielId : null),
            ];
        }
        return ['ok' => true, 'message' => '', 'locations' => $liste, 'uebergangen' => $uebergangen];
    }

    /**
     * Der Zustand einer Location dort – aus ihrem Stand dort und dem
     * Fingerabdruck hier (wie in locations() und auf „Zuordnen“).
     */
    private static function zustandVon(?array $hier, string $stand, ?array $vorschlag): string
    {
        if ($hier === null) {
            return $vorschlag !== null ? 'passt' : 'neu';
        }
        if ((string) $hier['partner_stand'] === '') {
            return 'verknuepft';
        }
        $dort = $stand !== (string) $hier['partner_stand'];
        $hiesig = self::hierStand((int) $hier['id']) !== (string) $hier['partner_hash'];
        return $dort && $hiesig ? 'beide' : ($dort ? 'dort' : ($hiesig ? 'hier' : 'aktuell'));
    }

    /**
     * Die Rückfrage vor „Übernehmen“ – in der Liste und auf „Zuordnen“
     * dieselbe. '' = keine (noch nicht hier, dort geändert, aktuell).
     *
     * @param bool $feststrom ob Feststrom mitkommen kann (Kästchen)
     * @param string $zielName der passende Standort hier (Zustand „passt“)
     * @param bool $zurueckBereit ob es einen Weg zurück gibt
     */
    public static function rueckfrage(string $zustand, bool $feststrom, string $zielName = '', bool $zurueckBereit = false): string
    {
        $was = 'Plan, Maßstab und Georeferenz' . ($feststrom ? ' – mit „Feststrom“ auch Feststromanschlüsse und feste Kabel' : '');
        return match ($zustand) {
            'hier', 'beide' => 'Änderungen hier auf den verknüpften Etagen werden überschrieben'
                . ($zurueckBereit ? ' – vorher zurückschicken, wenn sie dort ankommen sollen.' : '.')
                . ($feststrom ? ' Anlagen und Kabel, die es nur hier gibt, bleiben.' : ''),
            'verknuepft'    => 'Die verknüpften Etagen hier bekommen den Stand von dort (' . $was . ').',
            'passt'         => 'Mit „' . $zielName . '“ hier verknüpfen und den Stand von dort übernehmen? Etagen mit gleichem Schlüssel oder Namen bekommen '
                . $was . ' von dort, die übrigen kommen neu dazu; Namen hier bleiben.',
            default         => '',
        };
    }

    /**
     * Alles für die Seite „Zuordnen“: die Location dort, der Standort hier
     * (verknüpft, vorgeschlagen oder gewählt) und je Etage dort die passende
     * Etage hier. Wählbar sind nur Standorte, die man ändern darf und die
     * noch mit nichts verknüpft sind (oder schon mit dieser Location).
     *
     * @param int|null $ziel Standort hier; 0 = neu; null = verknüpft, sonst Vorschlag
     * @return array<string,mixed> {ok, message, location, hier, vorschlag, ziel, neu_erlaubt, standorte, etagen, zielEtagen, feststrom, stecker_fehlen}
     */
    public static function zuordnung(array $partner, string $schluessel, ?int $ziel): array
    {
        $geholt = self::holen($partner, $schluessel);
        if (!$geholt['ok']) {
            return ['ok' => false, 'message' => $geholt['message']];
        }
        $dort = $geholt['location'];
        $hier = self::verknuepft((int) $partner['id'], $schluessel);
        $vorschlag = $hier === null ? self::vorschlagStandort($schluessel, (string) ($dort['name'] ?? '')) : null;
        if ($hier !== null) {
            // Verknüpft bleibt verknüpft – ein anderes Ziel erst nach „Lösen“.
            $ziel = (int) $hier['id'];
        } elseif ($ziel === null) {
            $ziel = (int) ($vorschlag['id'] ?? 0);
        }
        $standorte = array_values(array_filter(SiteService::all(), static fn (array $s): bool
            => ($s['partner_id'] === null || ($hier !== null && (int) $s['id'] === (int) $hier['id']))
                && Zugriff::darfStandortAendern((int) $s['id'])));
        $zielEtagen = [];
        if ($ziel > 0) {
            $gefunden = null;
            foreach ($standorte as $s) {
                if ((int) $s['id'] === $ziel) {
                    $gefunden = $s;
                }
            }
            if ($gefunden === null) {
                return ['ok' => false, 'message' => 'Diesen Standort gibt es hier nicht, er ist schon mit einer anderen Location verknüpft, oder du darfst ihn nicht ändern.'];
            }
            $zielEtagen = array_values($gefunden['floors']);
        }

        $vorschlaege = self::etagenVorschlag(array_values($dort['etagen']), $ziel);
        $anlagen = $leitungen = [];
        foreach ((array) ($geholt['strom']['anlagen'] ?? []) as $a) {
            $k = is_array($a) && is_string($a['etage'] ?? null) ? $a['etage'] : '';
            $anlagen[$k] = ($anlagen[$k] ?? 0) + 1;
        }
        foreach ((array) ($geholt['strom']['leitungen'] ?? []) as $l) {
            $k = is_array($l) && is_string($l['etage'] ?? null) ? $l['etage'] : '';
            $leitungen[$k] = ($leitungen[$k] ?? 0) + 1;
        }
        $mitFeststrom = is_array($geholt['strom']) && array_key_exists('anlagen', $geholt['strom']);
        $etagen = [];
        foreach ($dort['etagen'] as $key => $e) {
            $plan = is_array($e['plan'] ?? null) ? $e['plan'] : null;
            $etagen[] = [
                'schluessel' => $key,
                'name'       => self::text($e['name'] ?? '', 190) ?: $key,
                'plan'       => $plan !== null,
                'webp'       => $plan !== null && str_ends_with((string) ($plan['datei'] ?? ''), '.webp'),
                'massstab'   => is_array($e['referenz'] ?? null),
                'georeferenz'=> is_array($geholt['strom']['georeferenz'][$key] ?? null),
                'bearbeiten' => ($e['bearbeiten'] ?? false) === true,
                'anlagen'    => $mitFeststrom ? ($anlagen[$key] ?? 0) : null,
                'leitungen'  => $mitFeststrom ? ($leitungen[$key] ?? 0) : null,
                'ziel'       => $vorschlaege[$key] ?? null,
            ];
        }
        $feststrom = $mitFeststrom && self::darfFeststrom($ziel > 0 ? $ziel : null);
        // Dieselbe Rückfrage wie in der Liste – auch dieser Weg überschreibt.
        $zustand = self::zustandVon($hier, (string) $geholt['stand'], $vorschlag);
        if ($hier !== null) {
            $rueckfrage = self::rueckfrage($zustand, $feststrom);
        } elseif ($ziel > 0) {
            $rueckfrage = 'Plan, Maßstab und Georeferenz der gewählten Etagen hier werden durch die von dort ersetzt'
                . ($feststrom ? ' – mit „Feststrom übernehmen“ auch Feststromanschlüsse und feste Kabel' : '')
                . '. Namen hier bleiben, gelöscht wird nichts. Übernehmen?';
        } else {
            $rueckfrage = '';
        }
        return [
            'ok'             => true,
            'message'        => '',
            'location'       => ['schluessel' => $schluessel, 'name' => self::text($dort['name'] ?? '', 190) ?: $schluessel],
            'zustand'        => $zustand,
            'rueckfrage'     => $rueckfrage,
            'hier'           => $hier,
            'vorschlag'      => $vorschlag,
            'ziel'           => $ziel,
            'neu_erlaubt'    => $hier === null,
            'standorte'      => $standorte,
            'etagen'         => $etagen,
            'zielEtagen'     => $zielEtagen,
            'feststrom'      => $feststrom,
            'stecker_fehlen' => $feststrom ? LocationUebertrag::fehlendeSteckertypen((array) $geholt['strom']) : [],
            'darf_stecker'   => Auth::can('stecker.manage'),
            'darf_planen'    => Auth::can('plans.manage'),
        ];
    }

    // ---------------------------------------------------------------- Übernehmen

    /**
     * Eine Location von dort übernehmen – oder nur verknüpfen.
     *
     * Ohne Angaben: eine verknüpfte Location holt den Stand von dort für ihre
     * verknüpften Etagen (und nur für die – eine beim Zuordnen ausgelassene
     * Etage kommt nicht über die Hintertür wieder), eine passende geht an den
     * vorgeschlagenen Standort, sonst an einen neuen, mit allen Etagen.
     *
     * Läuft unter der Sperre der Location (gesperrt()): Ein zweites
     * Übernehmen derselben Location – Doppelklick, zweiter Reiter – legte
     * sonst, während beide auf dort warten, einen zweiten Standort an.
     *
     * @param int|null $ziel Standort hier; 0 = neu; null = automatisch
     * @param array<string,string|int>|null $etagenWahl je Etage dort: '' (nicht übernehmen), 'neu' oder eine Etage hier (ID)
     * @return array{ok:bool,message:string,site_id?:int,neu?:bool,etagen?:array<string,int>,plaene?:int,anlagen?:int,leitungen?:int,downloads?:int}
     */
    public static function uebernehmen(array $partner, string $schluessel, ?int $ziel, ?array $etagenWahl, bool $feststrom, bool $nurVerknuepfen = false): array
    {
        if (preg_match(self::SCHLUESSEL, $schluessel) !== 1) {
            return ['ok' => false, 'message' => 'Diese Location gibt es dort nicht.'];
        }
        if (!Auth::can('plans.manage')) {
            return ['ok' => false, 'message' => 'Übernehmen und verknüpfen braucht das Recht „Standorte, Etagen und Grundrisse pflegen“.'];
        }
        return self::gesperrt((int) $partner['id'], $schluessel,
            static fn (): array => self::uebernehmenGesperrt($partner, $schluessel, $ziel, $etagenWahl, $feststrom, $nurVerknuepfen));
    }

    /** uebernehmen() unter der Sperre der Location – vom Holen bis zum Verknüpfen. */
    private static function uebernehmenGesperrt(array $partner, string $schluessel, ?int $ziel, ?array $etagenWahl, bool $feststrom, bool $nurVerknuepfen): array
    {
        $nein = static fn (string $meldung): array => ['ok' => false, 'message' => $meldung];
        $geholt = self::holen($partner, $schluessel);
        if (!$geholt['ok']) {
            return $nein($geholt['message']);
        }
        $dort = $geholt['location'];
        $dortEtagen = $dort['etagen'];

        // ------------------------------------------------ der Standort hier
        $hier = self::verknuepft((int) $partner['id'], $schluessel);
        if ($ziel === null) {
            $ziel = (int) ($hier['id'] ?? self::vorschlagStandort($schluessel, (string) ($dort['name'] ?? ''))['id'] ?? 0);
        }
        $site = null;
        if ($ziel > 0) {
            $site = SiteService::find($ziel);
            if ($site === null) {
                return $nein('Diesen Standort gibt es hier nicht.');
            }
            if ($hier !== null && (int) $hier['id'] !== $ziel) {
                return $nein(sprintf('Diese Location ist schon mit „%s“ verknüpft – erst die Verknüpfung lösen.', self::siteName($hier)));
            }
            if ($site['partner_id'] !== null && $hier === null) {
                return $nein(sprintf('„%s“ ist schon mit einer anderen Location verknüpft.', self::siteName($site)));
            }
            if (!Zugriff::darfStandortAendern($ziel)) {
                return $nein(sprintf('An „%s“ darfst du nichts ändern – dafür braucht es eine Freigabe mit „bearbeiten“.', self::siteName($site)));
            }
        } elseif ($hier !== null) {
            return $nein(sprintf('Diese Location ist schon mit „%s“ verknüpft – erst die Verknüpfung lösen.', self::siteName($hier)));
        }
        if ($nurVerknuepfen && $site === null) {
            return $nein('Verknüpfen geht nur mit einem Standort, den es hier schon gibt.');
        }

        // ------------------------------------------------ welche Etage dort auf welche hier
        $hierEtagen = [];
        if ($site !== null) {
            foreach (SiteService::floors($ziel) as $f) {
                $hierEtagen[(int) $f['id']] = $f;
            }
        }
        if ($etagenWahl === null) {
            $etagenWahl = [];
            foreach (self::etagenVorschlag(array_values($dortEtagen), $ziel) as $key => $v) {
                if ($hier !== null) {
                    if ($v !== null && $v['grund'] === 'verknuepft') {
                        $etagenWahl[$key] = (int) $v['id'];
                    }
                } else {
                    $etagenWahl[$key] = $v !== null ? (int) $v['id'] : 'neu';
                }
            }
        }
        // Welche Etage hier schon mit welcher dort verknüpft ist – eine Etage
        // dort bekommt hier nie eine zweite.
        $verknuepftMit = [];
        foreach ($hierEtagen as $id => $f) {
            if ((string) ($f['partner_schluessel'] ?? '') !== '') {
                $verknuepftMit[(string) $f['partner_schluessel']] ??= $id;
            }
        }
        $paare = [];
        $vergeben = [];
        foreach ($etagenWahl as $key => $wahl) {
            $key = (string) $key;
            $wahl = is_scalar($wahl) ? (string) $wahl : '';
            if ($wahl === '' || $wahl === '0') {
                continue;
            }
            if (!isset($dortEtagen[$key])) {
                return $nein('Eine gewählte Etage gibt es dort nicht (mehr).');
            }
            $floorId = 0;
            if ($wahl !== 'neu') {
                $floorId = ctype_digit($wahl) ? (int) $wahl : 0;
                if (!isset($hierEtagen[$floorId])) {
                    return $nein('Eine Etage ist einer Etage zugeordnet, die nicht zu diesem Standort gehört.');
                }
                if (isset($vergeben[$floorId])) {
                    return $nein(sprintf('Zwei Etagen von dort sind „%s“ zugeordnet – jede Etage hier nimmt nur eine.', (string) $hierEtagen[$floorId]['name']));
                }
                $schonDort = (string) ($hierEtagen[$floorId]['partner_schluessel'] ?? '');
                if ($schonDort !== '' && $schonDort !== $key) {
                    return $nein(sprintf('„%s“ ist schon mit einer anderen Etage dort verknüpft.', (string) $hierEtagen[$floorId]['name']));
                }
                $vergeben[$floorId] = true;
            }
            $schonHier = $verknuepftMit[$key] ?? null;
            if ($schonHier !== null && $floorId !== $schonHier) {
                return $nein(sprintf('Die Etage „%s“ dort ist hier schon mit „%s“ verknüpft – dorthin zuordnen oder „nicht übernehmen“ wählen.',
                    self::text($dortEtagen[$key]['name'] ?? '', 190) ?: $key, (string) $hierEtagen[$schonHier]['name']));
            }
            $paare[$key] = $floorId;
        }
        if ($paare === []) {
            return $nein('Keine Etage gewählt.');
        }

        if ($nurVerknuepfen) {
            return self::verknuepfen($partner, $schluessel, $site, $paare);
        }

        // ------------------------------------------------ Feststrom
        $teile = ['grundriss'];
        if ($feststrom) {
            if (!PartnerService::istPowerManagement($partner)) {
                return $nein('Feststromanschlüsse gibt es nur von einer PowerManagement-Instanz.');
            }
            if (!is_array($geholt['strom']) || !array_key_exists('anlagen', $geholt['strom'])) {
                return $nein('Das Token darf dort keine Feststromanschlüsse lesen (Befugnis „feststrom“) – nichts übernommen.');
            }
            if (!Auth::can('anlagen.manage') || !Auth::can('leitungen.manage')) {
                return $nein('Feststrom übernehmen braucht die Rechte „Anlagen ändern“ und „Kabel einzeichnen“ – nichts übernommen.');
            }
            if (!Zugriff::darfAufStandortAnlegen($site !== null ? $ziel : null)) {
                return $nein('Auf diesem Standort darfst du keine Anlagen anlegen – nichts übernommen.');
            }
            $fehlen = LocationUebertrag::fehlendeSteckertypen($geholt['strom']);
            if ($fehlen !== [] && !Auth::can('stecker.manage')) {
                return $nein('Diese Steckertypen gibt es hier noch nicht: ' . implode(', ', $fehlen)
                    . ' – anlegen darf sie nur, wer die Steckverbinder pflegt. Nichts übernommen.');
            }
            $teile[] = 'feststrom';
        }

        // ------------------------------------------------ Pläne holen und anwenden
        $ordner = null;
        $hinweise = [];
        $downloads = 0;
        try {
            $dateien = [];
            $geladen = [];
            foreach ($paare as $key => $floorId) {
                $plan = $dortEtagen[$key]['plan'] ?? null;
                if (!is_array($plan)) {
                    continue;
                }
                $datei = is_string($plan['datei'] ?? null) ? $plan['datei'] : '';
                $sha = is_string($plan['sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/', strtolower($plan['sha256'])) === 1 ? strtolower($plan['sha256']) : '';
                if (preg_match(self::PLANDATEI, $datei, $m) !== 1) {
                    return $nein('Die Gegenseite nennt eine Plandatei mit unerwartetem Namen: ' . mb_substr($datei, 0, 80) . ' – nichts übernommen.');
                }
                $endung = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                $etagenName = $floorId > 0 ? (string) $hierEtagen[$floorId]['name'] : (self::text($dortEtagen[$key]['name'] ?? '', 190) ?: $key);
                if ($endung === 'webp') {
                    $hinweise[] = sprintf('Grundriss von „%s“ nicht übernommen: webp-Dateien zeigt PowerManagement nicht an.', $etagenName);
                    continue;
                }
                // Derselbe Plan wie hier: nicht noch einmal über die Leitung – die
                // Datei hier geht als Quelle durch (Seite und Name gleichen sich an).
                $lokal = $floorId > 0 ? SiteService::planPruefsumme($hierEtagen[$floorId]) : null;
                if ($sha !== '' && $lokal !== null && hash_equals($lokal['sha256'], $sha)) {
                    $pfad = (string) SiteService::planPath($hierEtagen[$floorId]);
                    if (strtolower(pathinfo($pfad, PATHINFO_EXTENSION)) === $endung || ($endung === 'jpg' && str_ends_with(strtolower($pfad), '.jpeg'))) {
                        $dateien[$datei] = $pfad;
                        continue;
                    }
                }
                if ($sha !== '' && isset($geladen[$sha])) {
                    $dateien[$datei] = $geladen[$sha];
                    continue;
                }
                $ordner ??= self::tempOrdner();
                $antwort = PartnerService::anfrage($partner, 'GET', '/locations/' . rawurlencode($schluessel) . '/datei?pfad=' . rawurlencode($datei), null, true);
                $downloads++;
                if (!$antwort['ok']) {
                    return $nein(sprintf('Der Grundriss von „%s“ kam nicht an: %s – nichts übernommen.', $etagenName, $antwort['message']));
                }
                $inhalt = (string) ($antwort['roh'] ?? '');
                if ($sha !== '' && !hash_equals($sha, hash('sha256', $inhalt))) {
                    return $nein(sprintf('Der Grundriss von „%s“ kam unvollständig an – nichts übernommen.', $etagenName));
                }
                $zwischen = $ordner . '/' . count($geladen) . '-' . bin2hex(random_bytes(4)) . '.' . $endung;
                if (file_put_contents($zwischen, $inhalt) === false) {
                    return $nein('Der Grundriss ließ sich nicht zwischenspeichern – nichts übernommen.');
                }
                @chmod($zwischen, 0600);
                $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($zwischen);
                if ((SiteService::ALLOWED[$mime] ?? null) !== $endung) {
                    return $nein(sprintf('Der Grundriss von „%s“ ist keine gültige %s-Datei – nichts übernommen.', $etagenName, strtoupper($endung)));
                }
                $dateien[$datei] = $zwischen;
                $geladen[$sha !== '' ? $sha : $zwischen] = $zwischen;
            }

            $wurzeln = array_values(array_filter([realpath(SiteService::planDir()), $ordner !== null ? realpath($ordner) : false]));
            $aufloesen = static function (string $pfad) use ($dateien, $wurzeln): ?string {
                $echt = isset($dateien[$pfad]) ? realpath($dateien[$pfad]) : false;
                if ($echt === false) {
                    return null;
                }
                foreach ($wurzeln as $w) {
                    if (str_starts_with($echt, $w . '/')) {
                        return $echt;
                    }
                }
                return null;
            };
            $ergebnis = LocationUebertrag::anwenden($site !== null ? $ziel : null, $dort,
                PartnerService::istPowerManagement($partner) ? $geholt['strom'] : null, $paare, $aufloesen, [
                    'teile'           => $teile,
                    'zuordnung'       => 'partner',
                    'plan_praefix'    => 'sync',
                    'fremdbestand'    => true,
                    'neue_etagen'     => true,
                    // Fehlende, benutzte Typen nur mit dem Recht dazu (sonst schon oben abgelehnt).
                    'stecker_anlegen' => Auth::can('stecker.manage'),
                ]);
        } finally {
            if ($ordner !== null) {
                foreach (glob($ordner . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($ordner);
            }
        }
        if (!$ergebnis['ok']) {
            return $nein($ergebnis['message']);
        }

        // ------------------------------------------------ verknüpfen
        $siteId = (int) $ergebnis['site_id'];
        Database::execute('UPDATE sites SET partner_id = ?, partner_schluessel = ?, partner_stand = ?, partner_at = ? WHERE id = ?',
            [(int) $partner['id'], $schluessel, $geholt['stand'], gmdate('c'), $siteId]);
        foreach ($ergebnis['etagen'] as $dortKey => $floorId) {
            Database::execute('UPDATE floors SET partner_schluessel = ? WHERE id = ? AND site_id = ?', [(string) $dortKey, (int) $floorId, $siteId]);
        }
        // Ohne das, was es nur hier gibt: das kennt die Gegenseite nicht – bis
        // es dort ankommt, bleibt der Zustand „hier geändert“.
        Database::execute('UPDATE sites SET partner_hash = ? WHERE id = ?', [self::hierStand($siteId, true), $siteId]);
        Zugriff::vergessen();
        Audit::log('api.partner.location', 'site', (string) $siteId, [
            'partner'    => (int) $partner['id'],
            'schluessel' => $schluessel,
            'site_id'    => $siteId,
            'neu'        => $site === null,
            'etagen'     => count($ergebnis['etagen']),
            'plaene'     => (int) $ergebnis['plaene'],
            'anlagen'    => (int) $ergebnis['anlagen'],
            'leitungen'  => (int) $ergebnis['leitungen'],
        ]);
        return [
            'ok'        => true,
            'message'   => trim($ergebnis['message'] . ' ' . implode(' ', $hinweise)),
            'site_id'   => $siteId,
            'neu'       => $site === null,
            'etagen'    => $ergebnis['etagen'],
            'plaene'    => (int) $ergebnis['plaene'],
            'anlagen'   => (int) $ergebnis['anlagen'],
            'leitungen' => (int) $ergebnis['leitungen'],
            'downloads' => $downloads,
        ];
    }

    /**
     * Nur verknüpfen: der Bestand hier bleibt, wie er ist. Danach lässt er
     * sich übernehmen (Stand von dort) oder zurückschicken.
     *
     * @param array<string,int> $paare Etage dort → Etage hier (0 = neu, wird übergangen)
     */
    private static function verknuepfen(array $partner, string $schluessel, array $site, array $paare): array
    {
        $paare = array_filter($paare, static fn (int $id): bool => $id > 0);
        if ($paare === []) {
            return ['ok' => false, 'message' => 'Zum Verknüpfen mindestens eine Etage dort einer Etage hier zuordnen.'];
        }
        $siteId = (int) $site['id'];
        Database::execute("UPDATE sites SET partner_id = ?, partner_schluessel = ?, partner_stand = '', partner_hash = '', partner_at = ? WHERE id = ?",
            [(int) $partner['id'], $schluessel, gmdate('c'), $siteId]);
        foreach ($paare as $dortKey => $floorId) {
            Database::execute('UPDATE floors SET partner_schluessel = ? WHERE id = ? AND site_id = ?', [(string) $dortKey, $floorId, $siteId]);
        }
        Audit::log('api.partner.verknuepft', 'site', (string) $siteId, [
            'partner' => (int) $partner['id'], 'schluessel' => $schluessel, 'site_id' => $siteId, 'etagen' => count($paare),
        ]);
        return ['ok' => true, 'site_id' => $siteId, 'etagen' => $paare,
            'message' => sprintf('„%s“ ist mit „%s“ dort verknüpft (%d Etage(n)). Übernommen wurde nichts.', self::siteName($site), $schluessel, count($paare))];
    }

    /**
     * Die Verknüpfung eines Standorts lösen – der Bestand bleibt, wie er
     * ist, er wird nur nicht mehr abgeglichen.
     *
     * @param int|null $partnerId nur, wenn der Standort mit dieser Instanz verknüpft ist
     * @return array{ok:bool,message:string}
     */
    public static function loesen(int $siteId, ?int $partnerId = null): array
    {
        $site = SiteService::find($siteId);
        if ($site === null || $site['partner_id'] === null || ($partnerId !== null && (int) $site['partner_id'] !== $partnerId)) {
            return ['ok' => false, 'message' => 'Dieser Standort ist mit keiner anderen Instanz verknüpft.'];
        }
        if (!Zugriff::darfStandortAendern($siteId)) {
            return ['ok' => false, 'message' => sprintf('An „%s“ darfst du nichts ändern – dafür braucht es eine Freigabe mit „bearbeiten“.', self::siteName($site))];
        }
        PartnerService::verknuepfungLoesen($siteId);
        Audit::log('api.partner.geloest', 'site', (string) $siteId, [
            'partner' => (int) $site['partner_id'], 'schluessel' => (string) $site['partner_schluessel'], 'site_id' => $siteId,
        ]);
        return ['ok' => true, 'message' => sprintf('Verknüpfung von „%s“ gelöst. Der Standort bleibt, wie er ist.', self::siteName($site))];
    }

    /**
     * Änderungen hier an die verknüpfte Location dort schicken.
     *
     * Hat das Token dort „plaene.schreiben“, übernimmt die Gegenseite sie
     * gleich (Hochladen) – aber nur auf dem Stand, den sie zuletzt geliefert
     * hat (basis = partner_stand), sonst antwortet sie mit 409; $erzwingen
     * schreibt ausdrücklich darüber hinweg. Mit nur „plaene.vorschlagen“
     * kommt es dort als Vorschlag an, den jemand bestätigt.
     *
     * Vor dem Senden werden die Befugnisse dort neu geholt (GET /info): die
     * Seite bot an, was beim letzten Prüfen galt. Passt die gewählte Weise
     * ($weise: 'hochladen' | 'vorschlag') nicht mehr dazu – etwa weil dort
     * inzwischen „schreiben“ dazukam und ein „Vorschlag“ gleich übernommen
     * würde –, geht nichts hinaus.
     *
     * Geschickt werden nur verknüpfte Etagen, die dort bearbeitet werden
     * dürfen – mit den Schlüsseln, Namen, Ebenen und der Reihenfolge von dort
     * (die bleiben dort ohnehin), dem Plan, Maßstab, Maßstab-Text, Notizen
     * und der Georeferenz von hier. Eine Plandatei nur, wenn sie sich von der
     * dort unterscheidet. Nie Dosen, Racks, APs, Messpunkte oder Mobiles.
     * Feststrom nur zu einer PowerManagement-Instanz, deren Token ihn liest,
     * und nur mit „Anlagen ansehen“ hier:
     *
     *   - verknüpfte Anlagen und Kabel unter ihrem Schlüssel dort – aber nur,
     *     solange es sie dort noch gibt (dort Gelöschtes kommt nicht still
     *     zurück; hier bleibt es);
     *   - eigene unter ihrem Schlüssel hier oder, wenn der dort schon etwas
     *     anderes meint, unter einem freien (partner_gesendet merkt ihn sich);
     *   - was dort aus einer früheren Sendung angelegt wurde („gesendet_als“),
     *     wird vorher verknüpft – es geht unter dem Schlüssel dort hinüber.
     *
     * Läuft wie uebernehmen() unter der Sperre der Location: zweimal
     * „Hochladen“ oder ein Übernehmen daneben warteten sonst gleichzeitig
     * auf dort, auf demselben Stand.
     *
     * @param string|null $weise was die Seite angeboten hat (null: nicht prüfen)
     * @return array{ok:bool,message:string,uebernommen?:bool,id?:int}
     */
    public static function zurueckschicken(int $siteId, string $notiz, bool $erzwingen = false, ?string $weise = null): array
    {
        $site = SiteService::find($siteId);
        if ($site === null || $site['partner_id'] === null || (string) $site['partner_schluessel'] === '') {
            return ['ok' => false, 'message' => 'Dieser Standort ist mit keiner anderen Instanz verknüpft.'];
        }
        $partnerId = (int) $site['partner_id'];
        $dortKey = (string) $site['partner_schluessel'];
        return self::gesperrt($partnerId, $dortKey,
            static fn (): array => self::zurueckschickenGesperrt($siteId, $partnerId, $dortKey, $notiz, $erzwingen, $weise));
    }

    /** zurueckschicken() unter der Sperre der Location – der Standort frisch gelesen. */
    private static function zurueckschickenGesperrt(int $siteId, int $partnerId, string $dortKey, string $notiz, bool $erzwingen, ?string $weise): array
    {
        $nein = static fn (string $meldung): array => ['ok' => false, 'message' => $meldung];
        $site = SiteService::find($siteId);
        if ($site === null || (int) ($site['partner_id'] ?? 0) !== $partnerId || (string) $site['partner_schluessel'] !== $dortKey) {
            return $nein('Dieser Standort ist mit keiner anderen Instanz verknüpft.');
        }
        $partner = PartnerService::find($partnerId);
        if ($partner === null) {
            return $nein('Die Instanz, mit der dieser Standort verknüpft ist, ist hier nicht mehr eingetragen.');
        }
        if (!Auth::can('api.partner') || !Auth::can('plans.manage') || !Zugriff::darfStandortAendern($siteId)) {
            return $nein('Zurückschicken braucht das Recht „Standorte, Etagen und Grundrisse pflegen“ und eine Freigabe mit „bearbeiten“ an diesem Standort.');
        }
        // Was das Token dort jetzt darf – nicht, was beim letzten Prüfen galt.
        $frisch = PartnerService::pruefen((int) $partner['id']);
        if (!$frisch['ok']) {
            return $nein($frisch['message']);
        }
        $partner = PartnerService::find((int) $partner['id']) ?? $partner;
        $schreiben = PartnerService::darfDort($partner, 'plaene.schreiben');
        if (!$schreiben && !PartnerService::darfDort($partner, 'plaene.vorschlagen')) {
            return $nein('Das Token darf dort weder schreiben noch Änderungen vorschlagen – nichts geschickt.');
        }
        if (($weise === 'hochladen' && !$schreiben) || ($weise === 'vorschlag' && $schreiben)) {
            return $nein('Die Befugnisse dort haben sich geändert – Seite neu laden. Nichts geschickt.');
        }
        $geholt = self::holen($partner, $dortKey);
        if (!$geholt['ok']) {
            return $nein($geholt['message']);
        }
        $dortEtagen = $geholt['location']['etagen'];
        $pm = PartnerService::istPowerManagement($partner);
        $mitFeststrom = $pm && PartnerService::darfDort($partner, 'feststrom') && is_array($geholt['strom'])
            && array_key_exists('anlagen', $geholt['strom']) && Auth::can('anlagen.view');

        // Erst lesen (vergibt fehlende eigene Schlüssel), dann übersetzen.
        $hier = ApiBestand::locationDaten($siteId, $mitFeststrom);
        $nachDort = [];
        foreach (Database::select("SELECT sync_key, partner_schluessel FROM floors WHERE site_id = ? AND partner_schluessel <> ''", [$siteId]) as $f) {
            $nachDort[(string) $f['sync_key']] = (string) $f['partner_schluessel'];
        }
        $etagen = [];
        $dateien = [];
        $groesse = 0;
        $gesendet = [];
        $dateiFuerSha = [];
        /*
         * Grundrisse, die nicht in eine Sendung passen. Die Schnittstelle
         * nimmt wie MasterAdmin höchstens 30 MiB je Datei und 48 MiB je
         * Körper an (ApiBestand::MAX_DATEI_BYTES, VorschlagService::MAX_KOERPER);
         * hier dürfen Pläne bis 100 MB groß sein. So einer bleibt hier und
         * dort, wie er ist – der Rest geht trotzdem hinüber, und die Meldung
         * sagt, welcher fehlt. Früher scheiterte daran die ganze Sendung.
         */
        $ausgelassen = [];
        $planFehlt = [];
        foreach ($hier['location']['etagen'] as $e) {
            $dk = $nachDort[(string) $e['schluessel']] ?? null;
            $gegen = $dk !== null ? ($dortEtagen[$dk] ?? null) : null;
            if (!is_array($gegen) || ($gegen['bearbeiten'] ?? false) !== true) {
                continue;
            }
            // Name, Ebene und Reihenfolge von dort – MasterAdmin übernähme sonst die Namen von hier.
            $etage = [
                'schluessel'  => $dk,
                'name'        => self::text($gegen['name'] ?? '', 190) ?: (string) $e['name'],
                'ebene'       => is_numeric($gegen['ebene'] ?? null) ? (int) $gegen['ebene'] : (int) $e['ebene'],
                'reihenfolge' => is_numeric($gegen['reihenfolge'] ?? null) ? (int) $gegen['reihenfolge'] : (int) $e['reihenfolge'],
                'massstab'    => (string) $e['massstab'],
                'notizen'     => (string) $e['notizen'],
                'plan'        => null,
            ];
            if (is_array($e['plan'])) {
                $sha = (string) $e['plan']['sha256'];
                $endung = strtolower(pathinfo((string) $e['plan']['datei'], PATHINFO_EXTENSION));
                // Ein PDF für mehrere Etagen geht einmal hinüber.
                $datei = $dateiFuerSha[$sha] ??= 'plaene/' . $dk . '.' . $endung;
                $etage['plan'] = [
                    'datei'        => $datei,
                    'typ'          => (string) $e['plan']['typ'],
                    'seite'        => (int) $e['plan']['seite'],
                    'originalname' => (string) $e['plan']['originalname'],
                    'sha256'       => $sha,
                ];
                $dortSha = is_array($gegen['plan'] ?? null) && is_string($gegen['plan']['sha256'] ?? null) ? strtolower($gegen['plan']['sha256']) : '';
                if (isset($ausgelassen[$sha])) {
                    // Dieselbe Datei für eine weitere Etage – auch hier nicht.
                    $etage['plan'] = null;
                } elseif ($dortSha !== $sha && !isset($dateien[$datei])) {
                    $pfad = (string) ($hier['dateien'][(string) $e['plan']['datei']] ?? '');
                    $bytes = $pfad !== '' ? (int) @filesize($pfad) : 0;
                    if ($bytes <= 0) {
                        return $nein(sprintf('Der Grundriss von „%s“ ließ sich hier nicht lesen – nichts geschickt.', (string) $e['name']));
                    }
                    $alsText = (int) ceil($bytes / 3) * 4;
                    if ($bytes > ApiBestand::MAX_DATEI_BYTES || $groesse + $alsText > VorschlagService::MAX_KOERPER - self::PLATZ_FUER_JSON) {
                        // Ohne Plan heißt dort „Grundriss unverändert“ – bei
                        // MasterAdmin wie hier (plan: null löscht nichts).
                        $ausgelassen[$sha] = sprintf('„%s“ (%s)', (string) $e['name'], SiteService::mb($bytes, true));
                        $etage['plan'] = null;
                    } else {
                        $dateien[$datei] = base64_encode((string) file_get_contents($pfad));
                        $groesse += strlen($dateien[$datei]);
                    }
                }
            }
            /*
             * Ohne ihren Plan auch ohne Maßstab und Georeferenz: Beide sind am
             * Plan hier gemessen, dort läge aber weiter der alte – „referenz“
             * und „georeferenz“ fehlen, und das heißt dort „unverändert“.
             */
            $ohnePlan = is_array($e['plan']) && $etage['plan'] === null;
            if ($ohnePlan) {
                $planFehlt[(string) $e['schluessel']] = true;
            } elseif (array_key_exists('referenz', $e)) {
                $etage['referenz'] = $e['referenz'];
            }
            $etagen[] = $etage;
            $gesendet[(string) $e['schluessel']] = $dk;
        }
        if ($etagen === []) {
            return $nein('Dort ist keine der verknüpften Etagen zum Bearbeiten freigegeben – zurückschicken geht nicht.');
        }
        $dortLoc = $geholt['location'];
        $location = [
            'format'     => LocationBundle::FORMAT_LOCATION,
            'art'        => 'location',
            'schluessel' => $dortKey,
            'name'       => self::text($dortLoc['name'] ?? '', 190) ?: (string) $site['name'],
            'adresse'    => self::text($dortLoc['adresse'] ?? '', 255),
            'notizen'    => is_string($dortLoc['notizen'] ?? null) ? mb_substr($dortLoc['notizen'], 0, 5000) : '',
            'etagen'     => $etagen,
        ];

        $strom = null;
        $anlagenZahl = $leitungenZahl = 0;
        $dortEntfernt = 0;
        $eigene = ['objekte' => [], 'leitungen' => []];
        if ($pm) {
            $geo = [];
            foreach ((array) $hier['strom']['georeferenz'] as $hk => $g) {
                if (isset($gesendet[(string) $hk]) && !isset($planFehlt[(string) $hk]) && is_array($g)) {
                    $geo[$gesendet[(string) $hk]] = $g;
                }
            }
            $strom = ['format' => LocationBundle::FORMAT_STROM, 'art' => 'strom', 'schluessel' => $dortKey, 'georeferenz' => $geo === [] ? new \stdClass() : $geo];
            if ($mitFeststrom) {
                // Was dort aus einer früheren Sendung entstand, erst verknüpfen.
                self::bekannteVerknuepfen($siteId, (array) $geholt['strom']);
                $dortHat = ['objekte' => [], 'leitungen' => []];
                foreach (['objekte' => 'anlagen', 'leitungen' => 'leitungen'] as $tabelle => $liste) {
                    foreach ((array) ($geholt['strom'][$liste] ?? []) as $x) {
                        if (is_array($x) && is_string($x['schluessel'] ?? null)) {
                            $dortHat[$tabelle][$x['schluessel']] = true;
                        }
                    }
                }
                $anlageKey = self::sendeSchluessel($siteId, 'objekte', $dortHat['objekte']);
                $leitungKey = self::sendeSchluessel($siteId, 'leitungen', $dortHat['leitungen']);
                $anlagen = [];
                foreach ((array) $hier['strom']['anlagen'] as $a) {
                    $hk = (string) ($a['etage'] ?? '');
                    $s = $anlageKey[(string) $a['schluessel']] ?? null;
                    if (!isset($gesendet[$hk]) || $s === null) {
                        continue;
                    }
                    if (!$s['eigen'] && !isset($dortHat['objekte'][$s['key']])) {
                        // Dort entfernt: kommt nicht wieder – hier bleibt es.
                        $dortEntfernt++;
                        continue;
                    }
                    unset($a['messpunkte']);
                    $a['schluessel'] = $s['key'];
                    $a['etage'] = $gesendet[$hk];
                    $anlagen[] = $a;
                    if ($s['eigen']) {
                        $eigene['objekte'][$s['key']] = $s['id'];
                    }
                }
                $leitungen = [];
                foreach ((array) $hier['strom']['leitungen'] as $l) {
                    $hk = (string) ($l['etage'] ?? '');
                    $s = $leitungKey[(string) $l['schluessel']] ?? null;
                    if (!isset($gesendet[$hk]) || $s === null) {
                        continue;
                    }
                    if (!$s['eigen'] && !isset($dortHat['leitungen'][$s['key']])) {
                        $dortEntfernt++;
                        continue;
                    }
                    $l['schluessel'] = $s['key'];
                    $l['etage'] = $gesendet[$hk];
                    foreach (['von', 'zu'] as $ende) {
                        if (is_string($l[$ende] ?? null)) {
                            $l[$ende] = $anlageKey[$l[$ende]]['key'] ?? $l[$ende];
                        }
                    }
                    $leitungen[] = $l;
                    if ($s['eigen']) {
                        $eigene['leitungen'][$s['key']] = $s['id'];
                    }
                }
                $strom['steckertypen'] = array_values((array) $hier['strom']['steckertypen']);
                $strom['anlagen'] = $anlagen;
                $strom['leitungen'] = $leitungen;
                $anlagenZahl = count($anlagen);
                $leitungenZahl = count($leitungen);
            }
        }

        $koerper = [
            'location' => $location,
            'dateien'  => $dateien === [] ? new \stdClass() : $dateien,
            'notiz'    => mb_substr($notiz, 0, 2000),
            'absender' => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) Config::get('app.name', 'PowerManagement'))), 0, 120),
            'basis'    => (string) $site['partner_stand'],
        ];
        if ($strom !== null) {
            $koerper['strom'] = $strom;
        }
        if ($erzwingen && $schreiben) {
            $koerper['erzwingen'] = true;
        }
        $ohneDateien = $koerper;
        $ohneDateien['dateien'] = [];
        if ($groesse + strlen((string) json_encode($ohneDateien, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > VorschlagService::MAX_KOERPER) {
            return $nein(sprintf('Zusammen sind die Grundrisse zu groß für eine Sendung (höchstens %d MiB) – nichts geschickt.',
                intdiv(VorschlagService::MAX_KOERPER, 1048576)));
        }
        unset($ohneDateien);

        $dateienZahl = count($dateien);
        $r = PartnerService::anfrage($partner, 'POST', '/locations/' . rawurlencode($dortKey) . '/vorschlaege', $koerper);
        unset($koerper, $dateien);
        if (!$r['ok']) {
            if ((int) $r['status'] === 409) {
                return $nein('Dort hat sich etwas geändert – erst übernehmen (oder ausdrücklich überschreiben).');
            }
            return $nein($r['message']);
        }
        $id = is_int($r['daten']['id'] ?? null) ? (int) $r['daten']['id'] : 0;
        $uebernommen = ($r['daten']['uebernommen'] ?? false) === true;
        // Eigene Einträge: unter welchem Schlüssel sie dort stehen (direkt
        // übernommen) – sonst merken, unter welchem sie hinübergingen.
        $zuordnung = $uebernommen && is_array($r['daten']['schluessel'] ?? null) ? $r['daten']['schluessel'] : [];
        foreach (['objekte' => 'anlagen', 'leitungen' => 'leitungen'] as $tabelle => $art) {
            $dortSagt = is_array($zuordnung[$art] ?? null) ? $zuordnung[$art] : [];
            foreach ($eigene[$tabelle] as $gesendetAls => $eintragId) {
                $dortKeyNeu = $dortSagt[(string) $gesendetAls] ?? null;
                if (is_string($dortKeyNeu) && LocationBundle::schluesselFormat($dortKeyNeu)) {
                    Database::execute('UPDATE ' . $tabelle . " SET partner_schluessel = ?, partner_gesendet = '' WHERE id = ? AND partner_schluessel = ''",
                        [$dortKeyNeu, (int) $eintragId]);
                } else {
                    Database::execute('UPDATE ' . $tabelle . " SET partner_gesendet = ? WHERE id = ? AND partner_schluessel = ''", [(string) $gesendetAls, (int) $eintragId]);
                }
            }
        }
        $details = [
            'partner'    => (int) $partner['id'],
            'schluessel' => $dortKey,
            'site_id'    => $siteId,
            'etagen'     => count($etagen),
            'dateien'    => $dateienZahl,
            'feststrom'  => $mitFeststrom,
            'id'         => $id,
        ];
        $was = sprintf('%d Etage(n)%s', count($etagen), $mitFeststrom ? sprintf(', %d Feststromanschluss/-anschlüsse, %d feste(s) Kabel', $anlagenZahl, $leitungenZahl) : '');
        $hinweis = $dortEntfernt > 0 ? sprintf(' %d Eintrag/Einträge, die es dort nicht mehr gibt, blieben hier und gingen nicht mit.', $dortEntfernt) : '';
        if ($ausgelassen !== []) {
            $details['ausgelassen'] = count($ausgelassen);
            $hinweis .= sprintf(' Nicht mitgeschickt – größer, als die Schnittstelle annimmt (höchstens %s je Plan, %s zusammen): Grundriss von %s.'
                . ' Dort bleibt der bisherige, mit seinem Maßstab und seiner Georeferenz; den Plan dort von Hand hinterlegen oder kleiner exportieren.'
                . ' Bis dahin gilt der Standort hier als geändert – Übernehmen fragt vorher nach.',
                // zusammen: was als base64 in den Körper passt, umgerechnet auf die Dateien
                SiteService::mb(ApiBestand::MAX_DATEI_BYTES), SiteService::mb(intdiv((VorschlagService::MAX_KOERPER - self::PLATZ_FUER_JSON) * 3, 4)),
                implode(', ', $ausgelassen));
        }
        if ($uebernommen) {
            // Den Stand von dort nur übernehmen, wenn er auf dem aufsetzte, den
            // hier zuletzt geholt wurde – nach „erzwingen“ kann es dort mehr
            // geben, als hier bekannt ist (dann „dort geändert“: übernehmen).
            $passte = is_bool($r['daten']['basis_passte'] ?? null) ? $r['daten']['basis_passte'] : !$erzwingen;
            $stand = $r['daten']['stand'] ?? null;
            if ($passte && is_string($stand) && preg_match('/^[0-9a-f]{1,64}$/', $stand) === 1) {
                Database::execute('UPDATE sites SET partner_stand = ?, partner_at = ? WHERE id = ?', [$stand, gmdate('c'), $siteId]);
            }
            /*
             * Der Fingerabdruck hier gilt als „dort angekommen“ – aber nur,
             * wenn alles mitging. Fehlt ein Plan (zu groß), läge dort der
             * alte: Mit dem neuen Fingerabdruck hieße das „aktuell“, und das
             * nächste Übernehmen holte ohne Rückfrage den alten Plan zurück.
             * So bleibt es bei „hier geändert“ (bzw. „beide“).
             */
            if ($ausgelassen === []) {
                Database::execute('UPDATE sites SET partner_hash = ? WHERE id = ?', [self::hierStand($siteId, true), $siteId]);
            }
            Audit::log('api.partner.hochgeladen', 'site', (string) $siteId, $details + ($passte ? [] : ['erzwungen' => true]));
            return ['ok' => true, 'uebernommen' => true, 'id' => $id,
                'message' => sprintf('Bei „%s“ übernommen (%s).', (string) $partner['name'], $was)
                    . ($passte ? '' : ' Dort gibt es mehr, als hier bekannt ist – erst übernehmen.') . $hinweis];
        }
        Audit::log('api.partner.vorschlag', 'site', (string) $siteId, $details);
        return ['ok' => true, 'uebernommen' => false, 'id' => $id, 'message' => sprintf('Vorschlag Nr. %d ist dort eingegangen.', $id) . $hinweis];
    }

    /**
     * Was dort aus einer früheren Sendung von hier angelegt wurde, trägt dort
     * „gesendet_als“ – den Schlüssel, unter dem es von hier kam. Der eigene
     * Eintrag hier wird damit verknüpft (partner_schluessel), bevor er wieder
     * hinübergeht; sonst legte die nächste Sendung ihn dort noch einmal an.
     */
    private static function bekannteVerknuepfen(int $siteId, array $strom): void
    {
        foreach (['objekte' => 'anlagen', 'leitungen' => 'leitungen'] as $tabelle => $liste) {
            foreach ((array) ($strom[$liste] ?? []) as $x) {
                $dk = is_array($x) && is_string($x['schluessel'] ?? null) ? $x['schluessel'] : '';
                $als = is_array($x) && is_string($x['gesendet_als'] ?? null) ? $x['gesendet_als'] : '';
                if (!LocationBundle::schluesselFormat($dk) || !LocationBundle::schluesselFormat($als)) {
                    continue;
                }
                $schon = $tabelle === 'objekte'
                    ? Database::scalar('SELECT COUNT(*) FROM objekte WHERE site_id = ? AND partner_schluessel = ?', [$siteId, $dk])
                    : Database::scalar('SELECT COUNT(*) FROM leitungen l JOIN floors f ON f.id = l.floor_id WHERE f.site_id = ? AND l.partner_schluessel = ?', [$siteId, $dk]);
                if ((int) $schon > 0) {
                    continue;
                }
                $treffer = $tabelle === 'objekte'
                    ? Database::scalar("SELECT id FROM objekte WHERE site_id = ? AND fest = 1 AND ist_vorlage = 0 AND partner_schluessel = ''
                        AND (partner_gesendet = ? OR (partner_gesendet = '' AND sync_key = ?)) ORDER BY id LIMIT 1", [$siteId, $als, $als])
                    : Database::scalar("SELECT l.id FROM leitungen l JOIN floors f ON f.id = l.floor_id WHERE f.site_id = ? AND l.fest = 1
                        AND l.partner_schluessel = '' AND (l.partner_gesendet = ? OR (l.partner_gesendet = '' AND l.sync_key = ?)) ORDER BY l.id LIMIT 1",
                        [$siteId, $als, $als]);
                if ($treffer !== false && $treffer !== null) {
                    Database::execute('UPDATE ' . $tabelle . " SET partner_schluessel = ?, partner_gesendet = '' WHERE id = ?", [$dk, (int) $treffer]);
                }
            }
        }
    }

    /**
     * Unter welchem Schlüssel jeder Eintrag des Standorts hinübergeht – je
     * eigener sync_key → {key, id, eigen}.
     *
     * Verknüpfte unter ihrem Schlüssel dort. Eigene (ohne partner_schluessel)
     * unter dem, unter dem sie zuletzt hinübergingen (partner_gesendet), sonst
     * unter ihrem eigenen – es sei denn, der meint dort schon etwas anderes
     * (ein verknüpfter Eintrag hier, ein Eintrag dort) oder ein anderer hier
     * schickt ihn: dann ein freier, „-2“, „-3“ …, geprüft gegen alle Schlüssel
     * hier und dort. Jeder Schlüssel kommt so genau einmal vor.
     *
     * @param array<string,bool> $dortHat Schlüssel, die es dort gibt
     * @return array<string,array{key:string,id:int,eigen:bool}>
     */
    private static function sendeSchluessel(int $siteId, string $tabelle, array $dortHat): array
    {
        $zeilen = $tabelle === 'objekte'
            ? Database::select('SELECT id, sync_key, partner_schluessel, partner_gesendet FROM objekte WHERE site_id = ? AND ist_vorlage = 0 ORDER BY id', [$siteId])
            : Database::select('SELECT l.id, l.sync_key, l.partner_schluessel, l.partner_gesendet FROM leitungen l JOIN floors f ON f.id = l.floor_id
                WHERE f.site_id = ? ORDER BY l.id', [$siteId]);
        $out = [];
        $vergeben = [];
        $eigeneKeys = [];
        foreach ($zeilen as $z) {
            if ((string) $z['sync_key'] !== '') {
                $eigeneKeys[(string) $z['sync_key']] = true;
            }
            if ((string) $z['partner_schluessel'] !== '') {
                $vergeben[(string) $z['partner_schluessel']] = true;
                $out[(string) $z['sync_key']] = ['key' => (string) $z['partner_schluessel'], 'id' => (int) $z['id'], 'eigen' => false];
            }
        }
        foreach ($zeilen as $z) {
            $eigen = (string) $z['sync_key'];
            if ((string) $z['partner_schluessel'] !== '' || $eigen === '') {
                continue;
            }
            $frei = static fn (string $k): bool => LocationBundle::schluesselFormat($k) && !isset($vergeben[$k]) && !isset($dortHat[$k])
                && ($k === $eigen || !isset($eigeneKeys[$k]));
            $wunsch = (string) $z['partner_gesendet'] !== '' ? (string) $z['partner_gesendet'] : $eigen;
            if (!$frei($wunsch)) {
                $wunsch = $eigen;
                for ($i = 2; !$frei($wunsch); $i++) {
                    $wunsch = LocationBundle::mitNummer($eigen, $i);
                }
            }
            $vergeben[$wunsch] = true;
            $out[$eigen] = ['key' => $wunsch, 'id' => (int) $z['id'], 'eigen' => true];
        }
        return $out;
    }

    /**
     * Temp-Ordner von Plan-Downloads, die ein abgebrochener Lauf liegen ließ
     * (älter als ein Tag). Nur Ordner, die genau so heißen, wie tempOrdner()
     * sie anlegt. Läuft in bin/power wartung und vor jedem neuen Download:
     * unter systemd hat php-fpm meist ein eigenes /tmp (PrivateTmp), das der
     * Wartungsdienst nicht sieht.
     *
     * @return list<string>
     */
    public static function wartung(?string $temp = null, ?int $jetzt = null): array
    {
        $temp = rtrim($temp ?? sys_get_temp_dir(), '/');
        $jetzt ??= time();
        $weg = 0;
        foreach (glob($temp . '/pm-partner-*', GLOB_ONLYDIR) ?: [] as $ordner) {
            if (is_link($ordner) || preg_match('/^pm-partner-[0-9a-f]{12}$/', basename($ordner)) !== 1 || (int) @filemtime($ordner) >= $jetzt - 86400) {
                continue;
            }
            foreach (glob($ordner . '/*') ?: [] as $f) {
                if (is_link($f) || is_file($f)) {
                    @unlink($f);
                }
            }
            if (@rmdir($ordner)) {
                $weg++;
            }
        }
        return [sprintf('Andere Instanzen: %d liegengebliebene Temp-Ordner entfernt.', $weg)];
    }

    // ---------------------------------------------------------------- Stand hier

    /**
     * Fingerabdruck dessen, was hier an einem verknüpften Standort zur
     * Location dort gehört – direkt nach dem Übernehmen gespeichert
     * (partner_hash); weicht er später ab, ist hier etwas geändert.
     *
     * Gezählt wird nur, was von dort kommt und zurückgehen könnte: je
     * verknüpfter Etage Plan (Prüfsumme, Seite), Maßstab-Text, Notizen,
     * Maßstab und Georeferenz (ohne wer/wann); bei einer PowerManagement-
     * Instanz dazu die Feststromanschlüsse auf diesen Etagen oder mit
     * Schlüssel von dort und die festen Kabel dort – ohne Messpunkte und ohne
     * den Inhalt je mm, den ein Tank hier gelernt hat
     * (TankService::ohneKennlinieHier()). Namen zählen nicht: sie bleiben,
     * wie diese beiden, ohnehin hier.
     *
     * $ohneNurHier: ohne die Anlagen und Kabel, die es nur hier gibt (ohne
     * partner_schluessel) – so wird partner_hash nach dem Übernehmen und
     * Hochladen gespeichert. Verglichen wird mit dem vollen Stand: solange
     * hier etwas ist, das dort fehlt, steht „hier geändert“ da.
     */
    public static function hierStand(int $siteId, bool $ohneNurHier = false): string
    {
        $site = SiteService::find($siteId);
        if ($site === null) {
            return '';
        }
        $etagen = [];
        $ids = [];
        foreach (SiteService::floors($siteId) as $f) {
            $dortKey = (string) ($f['partner_schluessel'] ?? '');
            if ($dortKey === '') {
                continue;
            }
            $ids[] = (int) $f['id'];
            $summe = SiteService::planPruefsumme($f);
            $referenz = PlanMassstabService::lesen($f);
            $geo = PlanGeoService::alsDaten(PlanGeoService::lesen($f));
            unset($referenz['von'], $referenz['am'], $geo['von'], $geo['am']);
            $etagen[] = [
                'dort'     => $dortKey,
                'massstab' => (string) ($f['scale_note'] ?? ''),
                'notizen'  => (string) ($f['notes'] ?? ''),
                'plan_sha' => $summe['sha256'] ?? '',
                'seite'    => $summe !== null ? max(1, (int) ($f['plan_page'] ?? 1)) : 0,
                'referenz' => $referenz,
                'geo'      => $geo,
            ];
        }
        usort($etagen, static fn (array $a, array $b): int => strcmp($a['dort'], $b['dort']));
        $daten = ['etagen' => $etagen];

        $partner = $site['partner_id'] !== null ? PartnerService::find((int) $site['partner_id']) : null;
        if ($partner !== null && PartnerService::istPowerManagement($partner)) {
            $strom = LocationBundle::stromLesen($siteId);
            $etagenKeys = $ids === [] ? [] : array_map('strval', array_column(Database::select(
                'SELECT sync_key FROM floors WHERE id IN (' . implode(',', $ids) . ')'), 'sync_key'));
            $vonDort = array_map('strval', array_column(Database::select(
                "SELECT sync_key FROM objekte WHERE site_id = ? AND partner_schluessel <> ''", [$siteId]), 'sync_key'));
            $leitungenVonDort = array_map('strval', array_column(Database::select(
                "SELECT l.sync_key FROM leitungen l JOIN floors f ON f.id = l.floor_id WHERE f.site_id = ? AND l.partner_schluessel <> ''", [$siteId]), 'sync_key'));
            $anlagen = [];
            foreach ((array) $strom['anlagen'] as $a) {
                $verknuepft = in_array((string) $a['schluessel'], $vonDort, true);
                if ($ohneNurHier ? $verknuepft : (in_array((string) ($a['etage'] ?? ''), $etagenKeys, true) || $verknuepft)) {
                    unset($a['messpunkte']);
                    $anlagen[] = TankService::ohneKennlinieHier($a);
                }
            }
            $leitungen = array_values(array_filter((array) $strom['leitungen'],
                static fn (array $l): bool => in_array((string) ($l['etage'] ?? ''), $etagenKeys, true)
                    && (!$ohneNurHier || in_array((string) $l['schluessel'], $leitungenVonDort, true))));
            $nachSchluessel = static fn (array $a, array $b): int => strcmp((string) $a['schluessel'], (string) $b['schluessel']);
            usort($anlagen, $nachSchluessel);
            usort($leitungen, $nachSchluessel);
            $daten['anlagen'] = $anlagen;
            $daten['leitungen'] = $leitungen;
        }
        return Fingerabdruck::von($daten);
    }

    // ---------------------------------------------------------------- Hilfen

    /** „2 Feststromanschlüsse, 1 festes Kabel“ – für die Etagen dort. */
    public static function feststromText(int $anlagen, int $leitungen): string
    {
        if ($anlagen === 0 && $leitungen === 0) {
            return 'kein Feststrom';
        }
        return ($anlagen === 1 ? '1 Feststromanschluss' : $anlagen . ' Feststromanschlüsse')
            . ($leitungen > 0 ? ', ' . ($leitungen === 1 ? '1 festes Kabel' : $leitungen . ' feste Kabel') : '');
    }

    /**
     * GET /locations/{k} – geprüft: eine Location im Format 1 mit gültigen
     * Etagenschlüsseln und einem Stand; strom nur von PowerManagement.
     *
     * @return array{ok:bool,message:string,location?:array<string,mixed>,strom?:?array<string,mixed>,stand?:string}
     */
    private static function holen(array $partner, string $schluessel): array
    {
        if (preg_match(self::SCHLUESSEL, $schluessel) !== 1) {
            return ['ok' => false, 'message' => 'Diese Location gibt es dort nicht.'];
        }
        if (!PartnerService::darfDort($partner, 'plaene.lesen')) {
            return ['ok' => false, 'message' => 'Das Token darf dort keine Grundrisse lesen.'];
        }
        $r = PartnerService::anfrage($partner, 'GET', '/locations/' . rawurlencode($schluessel));
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['message']];
        }
        $loc = $r['daten']['location'] ?? null;
        if (!is_array($loc) || ($loc['art'] ?? '') !== 'location' || ($loc['schluessel'] ?? '') !== $schluessel) {
            return ['ok' => false, 'message' => 'Die Gegenseite schickt keine passende Location.'];
        }
        if (!is_int($loc['format'] ?? null) || $loc['format'] > LocationBundle::FORMAT_LOCATION) {
            return ['ok' => false, 'message' => 'Die Location dort stammt aus einer neueren Fassung – erst PowerManagement aktualisieren.'];
        }
        $etagen = [];
        foreach ((array) ($loc['etagen'] ?? []) as $e) {
            $k = is_array($e) && is_string($e['schluessel'] ?? null) ? $e['schluessel'] : '';
            if (!LocationBundle::schluesselFormat($k) || isset($etagen[$k])) {
                return ['ok' => false, 'message' => 'Die Gegenseite nennt eine Etage mit ungültigem oder doppeltem Schlüssel.'];
            }
            $etagen[$k] = $e;
        }
        $loc['etagen'] = $etagen;
        $stand = $r['daten']['stand'] ?? null;
        if (!is_string($stand) || preg_match('/^[0-9a-f]{1,64}$/', $stand) !== 1) {
            return ['ok' => false, 'message' => 'Die Gegenseite schickt keinen gültigen Stand.'];
        }
        $strom = null;
        if (PartnerService::istPowerManagement($partner) && ($r['daten']['strom'] ?? null) !== null) {
            $strom = $r['daten']['strom'];
            if (!is_array($strom) || ($strom['art'] ?? '') !== 'strom' || !is_int($strom['format'] ?? null) || $strom['format'] > LocationBundle::FORMAT_STROM) {
                return ['ok' => false, 'message' => 'Die Feststromanschlüsse dort haben ein unbekanntes Format – erst PowerManagement aktualisieren.'];
            }
        }
        return ['ok' => true, 'message' => '', 'location' => $loc, 'strom' => $strom, 'stand' => $stand];
    }

    /** Der Standort hier, der mit dieser Location dort verknüpft ist. */
    private static function verknuepft(int $partnerId, string $schluessel): ?array
    {
        return Database::first(
            'SELECT id, name, partner_stand, partner_hash, synced_at FROM sites WHERE partner_id = ? AND partner_schluessel = ? ORDER BY id LIMIT 1',
            [$partnerId, $schluessel]
        );
    }

    /**
     * Ein Standort hier, der zu einer Location dort passen könnte: noch mit
     * nichts verknüpft, änderbar und mit demselben Schlüssel, sonst mit
     * demselben Namen (ohne Rücksicht auf Groß- und Kleinschreibung).
     *
     * @return array{id:int,name:string,grund:string}|null
     */
    private static function vorschlagStandort(string $schluessel, string $name): ?array
    {
        $name = mb_strtolower(trim($name));
        $nachName = null;
        foreach (Database::select('SELECT id, name, sync_key FROM sites WHERE partner_id IS NULL ORDER BY name, id') as $s) {
            if (!Zugriff::darfStandortAendern((int) $s['id'])) {
                continue;
            }
            if ((string) $s['sync_key'] === $schluessel) {
                return ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'grund' => 'schluessel'];
            }
            if ($nachName === null && $name !== '' && mb_strtolower(trim((string) $s['name'])) === $name) {
                $nachName = ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'grund' => 'name'];
            }
        }
        return $nachName;
    }

    /**
     * Je Etage dort die passende Etage im Standort hier: die verknüpfte,
     * sonst eine mit demselben Schlüssel, sonst eine mit demselben Namen.
     * Jede Etage hier nur einmal; eine mit einer anderen Etage dort
     * verknüpfte wird nicht angeboten. null, wenn keine passt.
     *
     * @param list<array<string,mixed>> $dortEtagen
     * @return array<string,array{id:int,name:string,grund:string}|null>
     */
    private static function etagenVorschlag(array $dortEtagen, int $siteId): array
    {
        $hier = $siteId > 0 ? Database::select('SELECT id, name, sync_key, partner_schluessel FROM floors WHERE site_id = ? ORDER BY sort_order, level_no, name, id', [$siteId]) : [];
        $out = [];
        $vergeben = [];
        foreach (array_keys(self::GRUENDE) as $grund) {
            foreach ($dortEtagen as $e) {
                $key = (string) ($e['schluessel'] ?? '');
                if ($key === '' || isset($out[$key])) {
                    continue;
                }
                foreach ($hier as $f) {
                    $fid = (int) $f['id'];
                    if (isset($vergeben[$fid]) || ($grund !== 'verknuepft' && (string) $f['partner_schluessel'] !== '')) {
                        continue;
                    }
                    $passt = match ($grund) {
                        'verknuepft' => (string) $f['partner_schluessel'] === $key,
                        'schluessel' => (string) $f['sync_key'] === $key,
                        default      => mb_strtolower(trim((string) $f['name'])) === mb_strtolower(trim((string) ($e['name'] ?? ''))),
                    };
                    if ($passt) {
                        $out[$key] = ['id' => $fid, 'name' => (string) $f['name'], 'grund' => $grund];
                        $vergeben[$fid] = true;
                        break;
                    }
                }
            }
        }
        foreach ($dortEtagen as $e) {
            $out[(string) ($e['schluessel'] ?? '')] ??= null;
        }
        return $out;
    }

    /** Darf der Benutzer Feststrom auf diesen Standort übernehmen (null = neuer)? */
    private static function darfFeststrom(?int $siteId): bool
    {
        return Auth::can('anlagen.manage') && Auth::can('leitungen.manage') && Zugriff::darfAufStandortAnlegen($siteId);
    }

    /**
     * $arbeit unter der Sperre einer Location dort (Instanz, Schlüssel):
     * Übernehmen, Verknüpfen und Zurückschicken laufen je Location nur
     * einmal zur Zeit. Sie warten Sekunden bis Minuten auf dort – ohne
     * Sitzungssperre (Session::ohneSperre()), also auch nebeneinander aus
     * demselben Browser.
     *
     * Nicht blockierend: Wer die Sperre nicht bekommt, hört mit einer
     * Meldung auf. Warten ginge nicht – die zweite Anfrage hielte dabei die
     * Sitzung, und die erste bekäme sie nach der Antwort von dort nie
     * wieder.
     *
     * Die Datei in storage/cache gilt für diese Datenbank. Wer freigibt,
     * löscht sie vorher; wer sie danach noch über den alten Eintrag sperrt,
     * merkt das am Inode und gilt als „belegt“. So bleibt nichts liegen, und
     * zwei halten nie zugleich eine Sperre.
     *
     * @param callable():array{ok:bool,message:string} $arbeit
     * @return array{ok:bool,message:string}
     */
    private static function gesperrt(int $partnerId, string $schluessel, callable $arbeit): array
    {
        $pfad = self::sperrDatei($partnerId, $schluessel);
        if (!is_dir(dirname($pfad))) {
            @mkdir(dirname($pfad), 0775, true);
        }
        $griff = @fopen($pfad, 'c');
        if ($griff === false) {
            Logger::error('Andere Instanz: Sperrdatei ließ sich nicht anlegen: ' . $pfad);
            return ['ok' => false, 'message' => 'storage/cache ist nicht beschreibbar – nichts übernommen oder geschickt.'];
        }
        $frei = flock($griff, LOCK_EX | LOCK_NB);
        if ($frei) {
            // Noch dieselbe Datei? Sonst hat sie der Vorgänger schon gelöscht.
            clearstatcache(true, $pfad);
            $jetzt = @stat($pfad);
            $meine = fstat($griff);
            $frei = $jetzt !== false && $meine !== false && $jetzt['ino'] === $meine['ino'] && $jetzt['dev'] === $meine['dev'];
            if (!$frei) {
                flock($griff, LOCK_UN);
            }
        }
        if (!$frei) {
            fclose($griff);
            return ['ok' => false, 'message' => 'Diese Location wird gerade schon übernommen oder zurückgeschickt – nichts doppelt gemacht. Gleich die Seite neu laden.'];
        }
        try {
            return $arbeit();
        } finally {
            @unlink($pfad);
            flock($griff, LOCK_UN);
            fclose($griff);
        }
    }

    /** Die Sperrdatei einer Location dort – je Datenbank: Prüfskripte betreiben eigene Instanzen mit demselben Code, deren Instanz 1 ist nicht die hier. */
    private static function sperrDatei(int $partnerId, string $schluessel): string
    {
        $datenbank = [];
        foreach (['driver', 'path', 'host', 'port', 'database'] as $k) {
            $datenbank[$k] = Config::get('database.' . $k);
        }
        return dirname(__DIR__, 2) . '/storage/cache/partner-'
            . substr(hash('sha256', json_encode($datenbank) . '|' . $partnerId . '|' . $schluessel), 0, 40) . '.sperre';
    }

    private static function tempOrdner(): string
    {
        self::wartung();
        $ordner = sys_get_temp_dir() . '/pm-partner-' . bin2hex(random_bytes(6));
        if (!mkdir($ordner, 0700, true) && !is_dir($ordner)) {
            Logger::error('Andere Instanz: Temp-Ordner ließ sich nicht anlegen: ' . $ordner);
            throw new \RuntimeException('Der Temp-Ordner für Grundrisse ließ sich nicht anlegen.');
        }
        return $ordner;
    }

    private static function siteName(array $site): string
    {
        return Zugriff::siehtStandort((int) $site['id']) ? (string) $site['name'] : 'einem Standort, den du nicht siehst';
    }

    /** Ein Text von außen: nur Zeichenketten und Zahlen, ohne Steuerzeichen, gekürzt. */
    private static function text(mixed $wert, int $laenge): string
    {
        if (!is_string($wert) && !is_int($wert) && !is_float($wert)) {
            return '';
        }
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $wert)), 0, $laenge);
    }
}
