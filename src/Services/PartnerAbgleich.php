<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Fingerabdruck;
use App\Support\Logger;

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
    ];

    /** Woran eine Etage oder ein Standort hier als passend erkannt wurde. */
    public const GRUENDE = ['verknuepft' => 'verknüpft', 'schluessel' => 'gleicher Schlüssel', 'name' => 'gleicher Name'];

    /** Schlüssel einer Location dort (wie MasterAdmin sie in Adressen nimmt). */
    private const SCHLUESSEL = '/^[a-z0-9-]{1,190}$/';

    /** Plandatei dort: wie MasterAdmin sie nennt; webp kann PowerManagement nicht anzeigen. */
    private const PLANDATEI = '#^plaene/[a-z0-9-]+\.(pdf|png|jpe?g|svg|webp)$#';

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
                if (!LocationBundle::schluesselGueltig($ek) || isset($etagen[$ek])) {
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

            if ($hier !== null) {
                if ((string) $hier['partner_stand'] === '') {
                    $zustand = 'verknuepft';
                } else {
                    $dort = $stand !== (string) $hier['partner_stand'];
                    $hiesig = self::hierStand((int) $hier['id']) !== (string) $hier['partner_hash'];
                    $zustand = $dort && $hiesig ? 'beide' : ($dort ? 'dort' : ($hiesig ? 'hier' : 'aktuell'));
                }
            } else {
                $zustand = $vorschlag !== null ? 'passt' : 'neu';
            }
            $aendern = Auth::can('plans.manage') && ($zielId > 0 ? Zugriff::darfStandortAendern($zielId) : true);
            $liste[] = [
                'schluessel'  => $key,
                'name'        => $name,
                'stand'       => $stand,
                'etagen'      => $etagen,
                'hier'        => $hier,
                'sichtbar'    => $hier === null || Zugriff::siehtStandort((int) $hier['id']),
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
        return [
            'ok'             => true,
            'message'        => '',
            'location'       => ['schluessel' => $schluessel, 'name' => self::text($dort['name'] ?? '', 190) ?: $schluessel],
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
     * @param int|null $ziel Standort hier; 0 = neu; null = automatisch
     * @param array<string,string|int>|null $etagenWahl je Etage dort: '' (nicht übernehmen), 'neu' oder eine Etage hier (ID)
     * @return array{ok:bool,message:string,site_id?:int,neu?:bool,etagen?:array<string,int>,plaene?:int,anlagen?:int,leitungen?:int,downloads?:int}
     */
    public static function uebernehmen(array $partner, string $schluessel, ?int $ziel, ?array $etagenWahl, bool $feststrom, bool $nurVerknuepfen = false): array
    {
        $nein = static fn (string $meldung): array => ['ok' => false, 'message' => $meldung];
        if (preg_match(self::SCHLUESSEL, $schluessel) !== 1) {
            return $nein('Diese Location gibt es dort nicht.');
        }
        if (!Auth::can('plans.manage')) {
            return $nein('Übernehmen und verknüpfen braucht das Recht „Standorte, Etagen und Grundrisse pflegen“.');
        }
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
                    'teile'        => $teile,
                    'zuordnung'    => 'partner',
                    'plan_praefix' => 'sync',
                    'fremdbestand' => true,
                    'neue_etagen'  => true,
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
        Database::execute('UPDATE sites SET partner_hash = ? WHERE id = ?', [self::hierStand($siteId), $siteId]);
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
     * Änderungen hier an die verknüpfte Location dort schicken – hochladen
     * (Gegenseite mit „plaene.schreiben“) oder als Vorschlag.
     *
     * @return array{ok:bool,message:string}
     */
    public static function zurueckschicken(int $siteId, string $notiz, bool $erzwingen = false): array
    {
        return ['ok' => false, 'message' => 'Zurückschicken ist in dieser Fassung noch nicht verfügbar.'];
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
     * Schlüssel von dort und die festen Kabel dort – ohne Messpunkte. Namen
     * zählen nicht: sie bleiben ohnehin hier.
     */
    public static function hierStand(int $siteId): string
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
            $anlagen = [];
            foreach ((array) $strom['anlagen'] as $a) {
                if (in_array((string) ($a['etage'] ?? ''), $etagenKeys, true) || in_array((string) $a['schluessel'], $vonDort, true)) {
                    unset($a['messpunkte']);
                    $anlagen[] = $a;
                }
            }
            $leitungen = array_values(array_filter((array) $strom['leitungen'],
                static fn (array $l): bool => in_array((string) ($l['etage'] ?? ''), $etagenKeys, true)));
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
            if (!LocationBundle::schluesselGueltig($k) || isset($etagen[$k])) {
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

    private static function tempOrdner(): string
    {
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
