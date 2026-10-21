<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Anfragezeit;
use App\Support\Config;
use App\Support\Database;

/**
 * Locations als Dateien: location.json (MasterAdmin) und strom.json (hier).
 *
 * location.json hat das Format von MasterAdmin (FORMAT 1) und wird von hier
 * aus nur so weit angefasst, wie es PowerManagement betrifft: Etagen,
 * Grundriss, Maßstab. Dosen, Racks und Accesspoints werden gelesen und als
 * „Fremdbestand“ zur Orientierung auf der Karte gezeigt – gepflegt werden
 * sie in MasterAdmin.
 *
 * strom.json gehört PowerManagement und enthält die **Feststromanschlüsse**
 * der Location – was fest installiert ist und bei jeder Veranstaltung an
 * derselben Stelle steht (Hausanschluss, Energiesäulen, Wandverteiler …):
 *
 *   { format: 2, art: "strom", schluessel, stand, von,
 *     steckertypen: [{schluessel, name, strom_a, phasen, spannung_v, farbe}],
 *     anlagen: [{schluessel, art, fest: true, name, …, etage, x, y,
 *                schutzorgane: [{ref, art, bezeichnung, …, vor}],
 *                anschluesse: [{richtung, stecker, nr, bezeichnung, schutz, phase, notizen}],
 *                messpunkte: [{geraet, kanal, rolle, bezeichnung, anschluss, schutz}]}],
 *     leitungen: [{schluessel, art, fest: true, etage, von, von_anschluss, zu, zu_anschluss, …, punkte}],
 *     georeferenz: {etage: {a: {x, y, lat, lon}, b: {…}, verhaeltnis, von, am}} }
 *
 * Dazu kommen die **fest verlegten** Kabel und Kabelwege zwischen diesen
 * Feststromanschlüssen (Erdkabel, Trassen). Mobile Anlagen, mobile Kabel und
 * Kabelbrücken gehören zur Veranstaltung und bleiben in der Installation,
 * die sie plant. Format 1 (bis September 2026) enthielt noch alles; davon
 * werden beim Übernehmen nur die Stromanschlüsse gelesen, keine Leitungen.
 * `georeferenz` (Passpunkte je Etage) gehört zur Location wie der Maßstab,
 * steht aber hier, damit location.json das Format von MasterAdmin behält.
 * `leitungen` ist in Format 2 ein Zusatz – eine ältere Fassung, die ihn
 * nicht kennt, übernimmt die Anlagen und übergeht ihn.
 *
 * Anschlüsse werden über „richtung:nr“ angesprochen (abgang:3), Schutzorgane
 * über eine Referenz innerhalb der Anlage – so bleiben die Verweise über
 * Installationen hinweg gültig, obwohl die Datenbank-IDs überall andere sind.
 */
final class LocationBundle
{
    /** Das Format von location.json, das MasterAdmin schreibt und liest. */
    public const FORMAT_LOCATION = 1;

    /** Das Format von strom.json – 2: nur Feststromanschlüsse und fest verlegte Leitungen. */
    public const FORMAT_STROM = 2;

    // ------------------------------------------------------------ Schlüssel

    /** Wie in MasterAdmin: aus „Bühne Süd“ wird „buehne-sued“. */
    public static function schluessel(string $name, string $ersatz = 'ohne-namen'): string
    {
        $wert = strtr(mb_strtolower(trim($name)), [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
        $wert = (string) preg_replace('/[^a-z0-9]+/', '-', $wert);
        // Erst kürzen, dann die Bindestriche am Rand – sonst endete ein
        // langer Name auf „-“ (und wäre selbst kein gültiger Schlüssel).
        $wert = trim(substr(trim($wert, '-'), 0, 64), '-');
        return $wert !== '' ? $wert : $ersatz;
    }

    /**
     * Sieht der Text aus wie ein Schlüssel, den diese Instanz selbst so
     * erzeugt – nur a–z, 0–9 und einzelne Bindestriche, höchstens 64 Zeichen?
     * Für den Location-Abgleich über Git (Ordner- und Dateinamen).
     */
    public static function schluesselGueltig(string $schluessel): bool
    {
        return $schluessel !== '' && self::schluessel($schluessel, '') === $schluessel;
    }

    /**
     * Das Format eines Schlüssels an der Schnittstelle: a–z, 0–9 und
     * Bindestriche, 1 bis 190 Zeichen – wie in Adressen (/api/v1/locations/…)
     * und bei MasterAdmin. Weiter als schluesselGueltig(): MasterAdmin hängt
     * „-2“ an einen schon 64 Zeichen langen Schlüssel, und ältere Fassungen
     * von hier ließen einen Bindestrich am Ende stehen. Weder „/“ noch „.“ –
     * als Ordner- oder Dateiname taugt jeder solche Schlüssel.
     */
    public static function schluesselFormat(string $schluessel): bool
    {
        return preg_match('/^[a-z0-9-]{1,190}$/', $schluessel) === 1;
    }

    /** „basis-2“, „basis-3“ … – gekürzt, damit es mit der Nummer 64 Zeichen bleiben. */
    public static function mitNummer(string $basis, int $nummer): string
    {
        $endung = '-' . $nummer;
        return rtrim(substr($basis, 0, 64 - strlen($endung)), '-') . $endung;
    }

    /** Schlüssel einer Zeile holen oder einmalig vergeben und festschreiben. */
    public static function schluesselSichern(string $tabelle, int $id, string $name, string $ersatz = ''): string
    {
        if (!in_array($tabelle, ['sites', 'floors', 'objekte', 'leitungen'], true)) {
            throw new \InvalidArgumentException('Unbekannte Tabelle.');
        }
        $zeile = Database::first('SELECT sync_key FROM ' . $tabelle . ' WHERE id = ?', [$id]);
        $vorhanden = (string) ($zeile['sync_key'] ?? '');
        // Ein Schlüssel wird zum Ordner- oder Dateinamen im Repository: was
        // nicht wie ein Schlüssel aussieht („../..“), wird neu vergeben. Ein
        // vergebener bleibt – auch einer, den eine ältere Fassung mit „-“ am
        // Ende erzeugt hat: die Gegenseite kennt ihn unter genau diesem.
        if ($vorhanden !== '' && self::schluesselFormat($vorhanden)) {
            return $vorhanden;
        }
        $basis = self::schluessel($name, $ersatz !== '' ? $ersatz : 'eintrag-' . $id);
        $neu = $basis;
        for ($i = 2; (int) Database::scalar('SELECT COUNT(*) FROM ' . $tabelle . ' WHERE sync_key = ? AND id <> ?', [$neu, $id]) > 0; $i++) {
            $neu = self::mitNummer($basis, $i);
        }
        Database::execute('UPDATE ' . $tabelle . ' SET sync_key = ? WHERE id = ?', [$neu, $id]);
        return $neu;
    }

    /**
     * Ein Schlüssel, den in der Tabelle noch niemand hat – aus dem Wunsch,
     * sonst mit -2, -3 … dahinter (höchstens 64 Zeichen).
     *
     * Für Übernommenes von einer anderen Instanz: deren Schlüssel kann hier
     * schon vergeben sein, auf einem ganz anderen Standort.
     *
     * @param list<string> $auchBelegt Schlüssel, die gerade vergeben, aber noch nicht gespeichert sind
     */
    public static function freierSchluessel(string $tabelle, string $wunsch, array $auchBelegt = []): string
    {
        if (!in_array($tabelle, ['sites', 'floors', 'objekte', 'leitungen'], true)) {
            throw new \InvalidArgumentException('Unbekannte Tabelle.');
        }
        $basis = rtrim(self::schluessel($wunsch, 'eintrag'), '-');
        $basis = $basis !== '' ? $basis : 'eintrag';
        $belegt = static fn (string $k): bool => in_array($k, $auchBelegt, true)
            || (int) Database::scalar('SELECT COUNT(*) FROM ' . $tabelle . ' WHERE sync_key = ?', [$k]) > 0;
        $kandidat = $basis;
        for ($i = 2; $belegt($kandidat); $i++) {
            $kandidat = self::mitNummer($basis, $i);
        }
        return $kandidat;
    }

    // ------------------------------------------------------------ location.json

    /**
     * location.json anwenden: Standort, Etagen, Grundrisse, Maßstab.
     *
     * Optionen (Vorgaben = Git-Abgleich):
     *   plan_praefix  'sync' | 'vorschlag' – Anfang des Dateinamens übernommener
     *                 Pläne („vorschlag…“ zählt beim Hochladen als Änderung hier)
     *   fremdbestand  true: Dosen/Racks/APs als Fremdbestand übernehmen
     *   kacheln       true: Kacheln geänderter Pläne gleich bauen; false: der
     *                 Aufrufer baut sie selbst (Liste in 'kacheln'), etwa erst
     *                 nach dem Ende seiner Transaktion
     *   schluessel_format  false: Schlüssel wie im Repository (schluesselGueltig);
     *                 true: wie an der Schnittstelle (schluesselFormat) – für
     *                 LocationUebertrag, das nur Schlüssel von hier einsetzt
     *
     * @param callable(string):?string $datei Pfad im Repository → Pfad auf der Platte
     * @param array{plan_praefix?:string,fremdbestand?:bool,kacheln?:bool,schluessel_format?:bool} $optionen
     * @return array{ok:bool,message:string,site_id:int,etagen:array<string,int>,plaene:int,kacheln:list<int>}
     */
    public static function locationAnwenden(array $daten, callable $datei, array $optionen = []): array
    {
        $praefix = ($optionen['plan_praefix'] ?? 'sync') === 'vorschlag' ? 'vorschlag' : 'sync';
        $mitFremdbestand = (bool) ($optionen['fremdbestand'] ?? true);
        $kachelnJetzt = (bool) ($optionen['kacheln'] ?? true);
        $passt = ($optionen['schluessel_format'] ?? false) === true
            ? static fn (string $k): bool => self::schluesselFormat($k)
            : static fn (string $k): bool => self::schluesselGueltig($k);
        $fehler = static fn (string $meldung): array => ['ok' => false, 'message' => $meldung, 'site_id' => 0, 'etagen' => [], 'plaene' => 0, 'kacheln' => []];
        if ((string) ($daten['art'] ?? '') !== 'location') {
            return $fehler('Das ist keine Location.');
        }
        if ((int) ($daten['format'] ?? 0) > self::FORMAT_LOCATION) {
            return $fehler('Die Location stammt aus einer neueren MasterAdmin-Fassung. Erst PowerManagement aktualisieren.');
        }
        $schluessel = (string) ($daten['schluessel'] ?? '');
        if ($schluessel === '') {
            return $fehler('Der Location fehlt der Schlüssel.');
        }
        // Die Schlüssel werden später Ordner- und Dateinamen beim Hochladen –
        // was von außen kommt, muss wie ein Schlüssel aussehen.
        if (!$passt($schluessel)) {
            return $fehler('Der Schlüssel der Location ist ungültig.');
        }
        foreach ((array) ($daten['etagen'] ?? []) as $etage) {
            $etageKey = (string) ($etage['schluessel'] ?? '');
            if ($etageKey !== '' && !$passt($etageKey)) {
                return $fehler('Der Schlüssel einer Etage ist ungültig: ' . mb_substr($etageKey, 0, 40));
            }
        }

        $vorhanden = Database::first('SELECT id FROM sites WHERE sync_key = ?', [$schluessel]);
        $siteId = SiteService::saveSite($vorhanden === null ? null : (int) $vorhanden['id'], [
            'name'    => (string) ($daten['name'] ?? $schluessel),
            'address' => (string) ($daten['adresse'] ?? ''),
            'notes'   => (string) ($daten['notizen'] ?? ''),
        ]);
        Database::execute('UPDATE sites SET sync_key = ? WHERE id = ?', [$schluessel, $siteId]);

        $etagen = 0;
        $plaene = 0;
        $etagenIds = [];
        $neuePlaene = [];
        foreach ((array) ($daten['etagen'] ?? []) as $etage) {
            $etageKey = (string) ($etage['schluessel'] ?? '');
            if ($etageKey === '') {
                continue;
            }
            $alt = Database::first('SELECT * FROM floors WHERE site_id = ? AND sync_key = ?', [$siteId, $etageKey]);
            $etageId = SiteService::saveFloor($alt === null ? null : (int) $alt['id'], [
                'site_id'    => $siteId,
                'name'       => (string) ($etage['name'] ?? $etageKey),
                'level_no'   => (int) ($etage['ebene'] ?? 0),
                'sort_order' => (int) ($etage['reihenfolge'] ?? 0),
                'scale_note' => (string) ($etage['massstab'] ?? ''),
                'notes'      => (string) ($etage['notizen'] ?? ''),
            ]);
            Database::execute('UPDATE floors SET sync_key = ? WHERE id = ?', [$etageKey, $etageId]);
            $etagen++;
            $etagenIds[$etageKey] = $etageId;

            $plan = self::planUebernehmen($etageId, $etage['plan'] ?? null, $datei, $alt, $praefix);
            if ($plan !== '') {
                $plaene++;
            }
            if ($plan === 'neu') {
                $neuePlaene[] = $etageId;
            }
            if (is_array($etage['referenz'] ?? null)) {
                PlanMassstabService::uebernehmen($etageId, $etage['referenz']);
            }
            if ($mitFremdbestand) {
                self::fremdbestandSetzen($etageId, $etage, $alt);
            }
        }
        if ($kachelnJetzt) {
            self::kachelnBauen($neuePlaene);
        }

        return [
            'ok'      => true,
            'site_id' => $siteId,
            'message' => sprintf('%s übernommen: %d Etage(n), %d Grundriss(e).', (string) ($daten['name'] ?? $schluessel), $etagen, $plaene),
            'etagen'  => $etagenIds,
            'plaene'  => $plaene,
            'kacheln' => $kachelnJetzt ? [] : $neuePlaene,
        ];
    }

    /**
     * Was MasterAdmin auf der Etage führt (Dosen, Racks, Accesspoints) – nur
     * zur Orientierung.
     *
     * Je Schlüssel: was die Datei nicht nennt (oder ohne Liste), bleibt, wie
     * es war – ein fehlender Schlüssel heißt „unbekannt“, nicht „keine“. Nennt
     * sie keinen der drei, bleibt der Fremdbestand ganz unberührt.
     */
    private static function fremdbestandSetzen(int $etageId, array $etage, ?array $alt): void
    {
        $teile = array_values(array_filter(['dosen', 'racks', 'aps'], static fn (string $k): bool => is_array($etage[$k] ?? null)));
        if ($teile === []) {
            return;
        }
        $bisher = json_decode((string) ($alt['fremdbestand'] ?? ''), true);
        $fremd = [];
        foreach (['dosen', 'racks', 'aps'] as $k) {
            $fremd[$k] = is_array($bisher) && is_array($bisher[$k] ?? null) ? array_values($bisher[$k]) : [];
        }
        if (in_array('dosen', $teile, true)) {
            $fremd['dosen'] = array_values(array_map(static fn ($d) => [
                'label' => (string) ($d['label'] ?? ''), 'raum' => (string) ($d['raum'] ?? ''),
                'x' => (float) ($d['x'] ?? 0), 'y' => (float) ($d['y'] ?? 0),
            ], array_filter($etage['dosen'], static fn ($d) => isset($d['x'], $d['y']))));
        }
        if (in_array('racks', $teile, true)) {
            $fremd['racks'] = array_values(array_map(static fn ($r) => [
                'name' => (string) ($r['name'] ?? ''), 'raum' => (string) ($r['raum'] ?? ''),
                'x' => (float) ($r['x'] ?? 0), 'y' => (float) ($r['y'] ?? 0),
                'watt' => array_sum(array_map(static fn ($e) => (int) ($e['watt'] ?? 0), (array) ($r['einbauten'] ?? []))),
            ], array_filter($etage['racks'], static fn ($r) => isset($r['x'], $r['y']))));
        }
        if (in_array('aps', $teile, true)) {
            $fremd['aps'] = array_values(array_map(static fn ($a) => [
                'name' => (string) ($a['name'] ?? ''), 'x' => (float) ($a['x'] ?? 0), 'y' => (float) ($a['y'] ?? 0),
            ], array_filter($etage['aps'], static fn ($a) => isset($a['x'], $a['y']))));
        }
        Database::update('floors', $etageId, ['fremdbestand' => json_encode($fremd, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Plandatei übernehmen – nur wenn sie sich geändert hat, sonst würden bei
     * jedem Abgleich die Kacheln neu gerechnet.
     *
     * Die Datei wird geprüft wie ein Upload: Inhalt (finfo) PDF, PNG, JPEG
     * oder SVG, passend zur Endung, nicht größer als ein Upload sein darf.
     * Sonst bleibt der Plan hier, wie er ist.
     *
     * @return string '' nicht übernommen, 'gleich' schon so hier, 'neu' übernommen
     */
    private static function planUebernehmen(int $etageId, mixed $plan, callable $datei, ?array $alt, string $praefix = 'sync'): string
    {
        if (!is_array($plan) || (string) ($plan['datei'] ?? '') === '') {
            return '';
        }
        $quelle = $datei((string) $plan['datei']);
        if (!is_string($quelle) || $quelle === '' || !is_file($quelle)) {
            return '';
        }
        $bytes = filesize($quelle);
        if ($bytes === false || $bytes <= 0 || $bytes > SiteService::MAX_UPLOAD_BYTES) {
            return '';
        }
        $endung = strtolower(pathinfo($quelle, PATHINFO_EXTENSION));
        $endung = $endung === 'jpeg' ? 'jpg' : $endung;
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($quelle);
        if ((SiteService::ALLOWED[$mime] ?? null) !== $endung) {
            return '';
        }
        $sha = (string) hash_file('sha256', $quelle);
        $seite = min(SiteService::MAX_PDF_SEITEN, max(1, (int) ($plan['seite'] ?? 1)));
        $name = $praefix . $etageId . '_' . substr($sha, 0, 16) . '.' . $endung;
        $ziel = SiteService::planDir() . '/' . $name;
        if ($alt !== null && (string) $alt['plan_file'] === $name && (int) $alt['plan_page'] === $seite && is_file($ziel)) {
            return 'gleich';
        }
        if (is_file($ziel)) {
            // Liegt die Datei noch von früher da (A → B → A), gilt sie ab
            // jetzt als frisch: Die Wartung räumt nur Pläne weg, die seit
            // einem Tag keine Etage nutzt (PlanTileService::wartung()) – und
            // diese Übernahme ist vielleicht noch nicht eingetragen.
            @touch($ziel);
        } elseif (!copy($quelle, $ziel)) {
            return '';
        }
        $typ = strtolower(trim(is_scalar($plan['typ'] ?? null) ? (string) $plan['typ'] : ''));
        Database::update('floors', $etageId, [
            'plan_file'          => $name,
            'plan_type'          => preg_match('/^[a-z]{1,16}$/', $typ) === 1 ? $typ : $endung,
            'plan_page'          => $seite,
            'plan_original_name' => mb_substr(is_scalar($plan['originalname'] ?? null) ? (string) $plan['originalname'] : $name, 0, 255),
            'plan_uploaded_at'   => gmdate('c'),
            'updated_at'         => gmdate('c'),
            // Die Prüfsumme ist bekannt – die Schnittstelle muss sie nicht neu rechnen.
            'plan_sha256'        => $sha,
            'plan_sha_marke'     => SiteService::planMarke($name, $ziel),
        ]);
        return 'neu';
    }

    /**
     * Kacheln für übernommene Pläne bauen (ohne auf einen laufenden Bau zu warten).
     *
     * In einer Web-Anfrage (Git- und Partner-Abgleich, Vorschlag annehmen,
     * direktes Schreiben über die Schnittstelle) gar nicht selbst: Ein Plan
     * mit 100 MB braucht Minuten, und solange hielte die Anfrage die Sitzung
     * und liefe in die Frist von nginx (Anfragezeit). Die Pläne gehen gleich
     * an einen eigenen Prozess (PlanTileService::imHintergrund), der sie
     * nacheinander rechnet. Auf der Kommandozeile ohne Frist hier und jetzt.
     *
     * @param list<int> $etagenIds
     * @return list<int> die Etagen, die im Hintergrund gerechnet werden
     */
    public static function kachelnBauen(array $etagenIds): array
    {
        $faellig = [];
        foreach (array_unique(array_map('intval', $etagenIds)) as $etageId) {
            $etage = SiteService::floor($etageId);
            if ($etage !== null && PlanTileService::lohnt((string) SiteService::planPath($etage))) {
                $faellig[$etageId] = $etage;
            }
        }
        if ($faellig === []) {
            return [];
        }
        if (Anfragezeit::imWeb()) {
            PlanTileService::imHintergrund(array_keys($faellig));
            return array_keys($faellig);
        }
        foreach ($faellig as $etage) {
            PlanTileService::bauen($etage, true, false);
        }
        return [];
    }

    /**
     * location.json für das Hochladen: neu anlegen oder behutsam ergänzen.
     *
     * @param array<string,mixed>|null $vorhanden was im Repository steht
     * @return array{daten:array<string,mixed>,dateien:array<string,string>,geaendert:bool}
     */
    public static function locationFuerRepo(int $siteId, ?array $vorhanden): array
    {
        $site = SiteService::find($siteId);
        $schluessel = self::schluesselSichern('sites', $siteId, (string) $site['name'], 'standort-' . $siteId);
        $daten = $vorhanden ?? [
            'format'     => self::FORMAT_LOCATION,
            'art'        => 'location',
            'schluessel' => $schluessel,
            'name'       => (string) $site['name'],
            'adresse'    => (string) $site['address'],
            'notizen'    => (string) ($site['notes'] ?? ''),
            'etagen'     => [],
        ];
        $geaendert = $vorhanden === null;
        $dateien = [];
        $nachSchluessel = [];
        foreach ((array) $daten['etagen'] as $i => $etage) {
            $nachSchluessel[(string) ($etage['schluessel'] ?? '')] = $i;
        }

        foreach (SiteService::floors($siteId) as $etage) {
            $etageId = (int) $etage['id'];
            $etageKey = self::schluesselSichern('floors', $etageId, (string) $etage['name'], 'etage-' . $etageId);
            $plan = null;
            $pfad = SiteService::planPath($etage);
            if ($pfad !== null && is_file($pfad)) {
                $plan = [
                    'datei'        => 'plaene/' . $etageKey . '.' . strtolower(pathinfo($pfad, PATHINFO_EXTENSION)),
                    'typ'          => (string) $etage['plan_type'],
                    'seite'        => (int) $etage['plan_page'],
                    'originalname' => (string) $etage['plan_original_name'],
                ];
            }
            $referenz = PlanMassstabService::lesen($etage);

            if (!isset($nachSchluessel[$etageKey])) {
                $daten['etagen'][] = [
                    'schluessel'  => $etageKey,
                    'name'        => (string) $etage['name'],
                    'ebene'       => (int) $etage['level_no'],
                    'reihenfolge' => (int) $etage['sort_order'],
                    'massstab'    => (string) $etage['scale_note'],
                    'notizen'     => (string) ($etage['notes'] ?? ''),
                    'plan'        => $plan,
                    'referenz'    => $referenz,
                    'dosen'       => [],
                    'racks'       => [],
                    'aps'         => [],
                ];
                if ($plan !== null) {
                    $dateien[$plan['datei']] = $pfad;
                }
                $geaendert = true;
                continue;
            }
            $i = $nachSchluessel[$etageKey];
            // Ein Plan, der dort fehlt oder den es hier neuer gibt, wandert mit.
            $dortPlan = $daten['etagen'][$i]['plan'] ?? null;
            $hierNeuer = $plan !== null && !empty($etage['plan_uploaded_at']) && !empty($site['synced_at'])
                && strtotime((string) $etage['plan_uploaded_at']) > strtotime((string) $site['synced_at'])
                && !str_starts_with((string) $etage['plan_file'], 'sync');
            if ($plan !== null && (empty($dortPlan['datei']) || $hierNeuer)) {
                $daten['etagen'][$i]['plan'] = $plan;
                $dateien[$plan['datei']] = $pfad;
                $geaendert = true;
            }
            if ($referenz !== null && empty($daten['etagen'][$i]['referenz'])) {
                $daten['etagen'][$i]['referenz'] = $referenz;
                $geaendert = true;
            }
        }
        return ['daten' => $daten, 'dateien' => $dateien, 'geaendert' => $geaendert];
    }

    // ------------------------------------------------------------ strom.json

    /**
     * Die Feststromanschlüsse eines Standorts als strom.json.
     *
     * Fest installierte Anlagen mit ihren Anschlüssen, Schutzorganen und
     * Messgeräten, dazu die fest verlegten Kabel und Kabelwege. Mobile
     * Anlagen, mobile Kabel und Kabelbrücken bleiben hier.
     */
    public static function stromLesen(int $siteId): array
    {
        $site = SiteService::find($siteId);
        $siteKey = self::schluesselSichern('sites', $siteId, (string) $site['name'], 'standort-' . $siteId);
        $etagenKeys = [];
        $georeferenz = [];
        foreach (SiteService::floors($siteId) as $etage) {
            $etagenKeys[(int) $etage['id']] = self::schluesselSichern('floors', (int) $etage['id'], (string) $etage['name'], 'etage-' . $etage['id']);
            $geo = PlanGeoService::alsDaten(PlanGeoService::lesen($etage));
            if ($geo !== null) {
                $georeferenz[$etagenKeys[(int) $etage['id']]] = $geo;
            }
        }
        $stecker = AnlagenService::steckertypenNachId();
        $benutzteStecker = [];

        $anlagen = [];
        $objektKeys = [];
        $anschlussRef = [];
        foreach (AnlagenService::liste(['site_id' => $siteId, 'fest' => true]) as $o) {
            $id = (int) $o['id'];
            $key = self::schluesselSichern('objekte', $id, (string) $o['name'], $o['art'] . '-' . $id);
            $objektKeys[$id] = $key;

            $belegung = self::belegungLesen($id, $stecker, $benutzteStecker);
            $schutzRef = $belegung['schutz_ref'];
            $anschlussRef += $belegung['anschluss_ref'];
            $schutzorgane = $belegung['schutzorgane'];
            $anschluesse = $belegung['anschluesse'];
            $messpunkte = [];
            // Nur fest eingebaute Messgeräte – ein Inventar-Verteiler gehört nicht zur Location.
            foreach (Database::select('SELECT * FROM messpunkte WHERE objekt_id = ? AND einsatz_id IS NULL AND gueltig_bis IS NULL ORDER BY id', [$id]) as $m) {
                $messpunkte[] = [
                    'geraet'      => $m['geraet'],
                    'kanal'       => $m['kanal'],
                    'rolle'       => $m['rolle'],
                    'bezeichnung' => $m['bezeichnung'],
                    'anschluss'   => $m['anschluss_id'] !== null ? ($anschlussRef[(int) $m['anschluss_id']] ?? null) : null,
                    'schutz'      => $m['schutzorgan_id'] !== null ? ($schutzRef[(int) $m['schutzorgan_id']] ?? null) : null,
                ];
            }
            $anlagen[] = [
                'schluessel'       => $key,
                'art'              => $o['art'],
                'fest'             => true,
                'name'             => $o['name'],
                'kennung'          => $o['kennung'],
                'etage'            => $o['floor_id'] !== null ? ($etagenKeys[(int) $o['floor_id']] ?? null) : null,
                'x'                => $o['plan_x'] !== null ? (float) $o['plan_x'] : null,
                'y'                => $o['plan_y'] !== null ? (float) $o['plan_y'] : null,
                'hersteller'       => $o['hersteller'],
                'modell'           => $o['modell'],
                'seriennummer'     => $o['seriennummer'],
                'nennleistung_kva' => $o['nennleistung_kva'] !== null ? (float) $o['nennleistung_kva'] : null,
                'nennstrom_a'      => $o['nennstrom_a'] !== null ? (float) $o['nennstrom_a'] : null,
                'spannung_v'       => (int) $o['spannung_v'],
                'phasen'           => (int) $o['phasen'],
                'status'           => $o['status'],
                'eigenschaften'    => $o['eigenschaften'],
                'steuerung_gesperrt' => (int) $o['steuerung_gesperrt'] === 1,
                'notizen'          => (string) ($o['notizen'] ?? ''),
                'schutzorgane'     => $schutzorgane,
                'anschluesse'      => $anschluesse,
                'messpunkte'       => $messpunkte,
            ];
        }

        // Fest verlegte Kabel und Kabelwege. Ein Ende an einer Anlage, die
        // nicht (mehr) fest ist, geht als offenes Ende hinaus.
        $leitungen = [];
        foreach (array_keys($etagenKeys) as $floorId) {
            foreach (LeitungService::fuerEtage($floorId) as $l) {
                if ($l['fest'] !== 1 || $l['art'] === 'kabelbruecke') {
                    continue;
                }
                $key = self::schluesselSichern('leitungen', (int) $l['id'], (string) ($l['name'] ?: $l['art']), $l['art'] . '-' . $l['id']);
                $ende = static fn (?int $objekt, ?int $anschluss): array => $objekt !== null && isset($objektKeys[$objekt])
                    ? [$objektKeys[$objekt], $anschluss !== null ? ($anschlussRef[$anschluss] ?? null) : null]
                    : [null, null];
                [$von, $vonAnschluss] = $ende($l['von_objekt_id'] !== null ? (int) $l['von_objekt_id'] : null, $l['von_anschluss_id'] !== null ? (int) $l['von_anschluss_id'] : null);
                [$zu, $zuAnschluss] = $ende($l['zu_objekt_id'] !== null ? (int) $l['zu_objekt_id'] : null, $l['zu_anschluss_id'] !== null ? (int) $l['zu_anschluss_id'] : null);
                $leitungen[] = [
                    'schluessel'      => $key,
                    'art'             => $l['art'],
                    'fest'            => true,
                    'etage'           => $etagenKeys[$floorId],
                    'name'            => $l['name'],
                    'von'             => $von,
                    'von_anschluss'   => $vonAnschluss,
                    'zu'              => $zu,
                    'zu_anschluss'    => $zuAnschluss,
                    'kabeltyp'        => $l['kabeltyp'],
                    'adern'           => $l['adern'] !== null ? (int) $l['adern'] : null,
                    'querschnitt_mm2' => $l['querschnitt_mm2'] !== null ? (float) $l['querschnitt_mm2'] : null,
                    'laenge_m'        => $l['laenge_m'] !== null ? (float) $l['laenge_m'] : null,
                    'anzahl'          => (int) $l['anzahl'],
                    'modell'          => $l['modell'],
                    'punkte'          => array_map(static fn ($p) => [$p['x'], $p['y']], $l['punkte']),
                    'farbe'           => $l['farbe'],
                    'notizen'         => (string) ($l['notizen'] ?? ''),
                ];
            }
        }

        return [
            'format'       => self::FORMAT_STROM,
            'art'          => 'strom',
            'schluessel'   => $siteKey,
            'stand'        => gmdate('c'),
            'von'          => (string) Config::get('app.name', 'PowerManagement'),
            'steckertypen' => self::steckertypenFuerDatei($benutzteStecker),
            'anlagen'      => $anlagen,
            'leitungen'    => $leitungen,
            'georeferenz'  => (object) $georeferenz,
        ];
    }

    /**
     * Schutzorgane und Anschlüsse einer Anlage für eine Datei.
     *
     * Verweise gehen nicht über Datenbank-IDs, sondern über s1, s2 … (in der
     * Reihenfolge des Schutzorgan-Baums) und „richtung:nr“ – so bleiben sie
     * in jeder Installation gültig. Dieselbe Form für strom.json und für die
     * Standard-Verteiler im Verteiler-Abgleich.
     *
     * @param array<int,array<string,mixed>> $stecker steckertyp_id => Steckertyp
     * @param array<int,array<string,mixed>> $benutzt sammelt die vorkommenden Steckertypen
     * @return array{schutzorgane:list<array<string,mixed>>,anschluesse:list<array<string,mixed>>,schutz_ref:array<int,string>,anschluss_ref:array<int,string>}
     */
    public static function belegungLesen(int $objektId, array $stecker, array &$benutzt): array
    {
        $schutzRef = [];
        $schutzorgane = [];
        $liste = SchutzService::liste($objektId);
        foreach ($liste as $n => $s) {
            $schutzRef[(int) $s['id']] = 's' . ($n + 1);
        }
        foreach ($liste as $s) {
            $schutzorgane[] = [
                'ref'            => $schutzRef[(int) $s['id']],
                'art'            => $s['art'],
                'bezeichnung'    => $s['bezeichnung'],
                'nennstrom_a'    => $s['nennstrom_a'] !== null ? (float) $s['nennstrom_a'] : null,
                'charakteristik' => $s['charakteristik'],
                'fehlerstrom_ma' => $s['fehlerstrom_ma'] !== null ? (int) $s['fehlerstrom_ma'] : null,
                'rcd_typ'        => $s['rcd_typ'],
                'pole'           => (int) $s['pole'],
                'vor'            => $s['vorgeschaltet_id'] !== null ? ($schutzRef[(int) $s['vorgeschaltet_id']] ?? null) : null,
                'notizen'        => (string) ($s['notizen'] ?? ''),
            ];
        }
        $anschlussRef = [];
        $anschluesse = [];
        foreach (Database::select('SELECT * FROM anschluesse WHERE objekt_id = ? ORDER BY richtung, nr', [$objektId]) as $a) {
            $typ = $stecker[(int) $a['steckertyp_id']] ?? null;
            if ($typ === null) {
                continue;
            }
            $benutzt[(int) $typ['id']] = $typ;
            $anschlussRef[(int) $a['id']] = $a['richtung'] . ':' . (int) $a['nr'];
            $anschluesse[] = [
                'richtung'    => $a['richtung'],
                'stecker'     => $typ['schluessel'],
                'nr'          => (int) $a['nr'],
                'bezeichnung' => $a['bezeichnung'],
                'schutz'      => $a['schutzorgan_id'] !== null ? ($schutzRef[(int) $a['schutzorgan_id']] ?? null) : null,
                'phase'       => $a['phase'],
                'notizen'     => (string) ($a['notizen'] ?? ''),
            ];
        }
        return ['schutzorgane' => $schutzorgane, 'anschluesse' => $anschluesse, 'schutz_ref' => $schutzRef, 'anschluss_ref' => $anschlussRef];
    }

    /**
     * Die Steckertypen, die in einer Datei vorkommen – damit die andere
     * Seite fehlende anlegen kann.
     *
     * @param array<int,array<string,mixed>> $benutzt
     * @return list<array<string,mixed>>
     */
    public static function steckertypenFuerDatei(array $benutzt): array
    {
        return array_values(array_map(static fn ($t) => [
            'schluessel' => $t['schluessel'], 'name' => $t['name'], 'strom_a' => (float) $t['strom_a'],
            'phasen' => (int) $t['phasen'], 'spannung_v' => (int) $t['spannung_v'], 'farbe' => $t['farbe'],
        ], $benutzt));
    }

    /**
     * Steckertypen aus einer Datei: vorhandene über ihren Schlüssel finden,
     * fehlende anlegen.
     *
     * @param list<array<string,mixed>> $liste
     * @param bool $anlegen false: nur vorhandene zuordnen, nichts im Katalog anlegen
     * @return array<string,int> schluessel => steckertyp_id
     */
    public static function steckertypenSichern(array $liste, bool $anlegen = true): array
    {
        $stecker = [];
        foreach (AnlagenService::steckertypen() as $t) {
            $stecker[(string) $t['schluessel']] = (int) $t['id'];
        }
        foreach ($liste as $t) {
            $k = is_array($t) ? (string) ($t['schluessel'] ?? '') : '';
            if ($k === '' || isset($stecker[$k])) {
                continue;
            }
            // „CEE63“ aus einer von Hand geschriebenen Datei ist hier „cee63“ –
            // ein zweites Anlegen scheiterte am eindeutigen Schlüssel.
            $sauber = self::schluessel($k, 'stecker');
            if (isset($stecker[$sauber])) {
                $stecker[$k] = $stecker[$sauber];
                continue;
            }
            if (!$anlegen) {
                continue;
            }
            $stecker[$k] = $stecker[$sauber] = Database::insert('steckertypen', [
                'schluessel' => $sauber, 'name' => (string) ($t['name'] ?? $k),
                'strom_a' => (float) ($t['strom_a'] ?? 16), 'phasen' => (int) ($t['phasen'] ?? 3) === 1 ? 1 : 3,
                'spannung_v' => (int) ($t['spannung_v'] ?? 400),
                // Die Farbe landet in style-Attributen – nur #rrggbb, sonst
                // könnte eine Datei dort CSS unterbringen (etwa url(…)).
                'farbe' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($t['farbe'] ?? '')) === 1 ? (string) $t['farbe'] : '#64748b',
                'reihenfolge' => 500, 'ist_standard' => 0, 'created_at' => gmdate('c'),
            ]);
        }
        return $stecker;
    }

    /**
     * Die Anlagen einer strom.json, die als Feststromanschluss gelten.
     *
     * Ab Format 2 steht nichts anderes darin. Format 1 enthielt noch die
     * ganze Planung; davon zählen die Stromanschlüsse – dieselbe Regel, mit
     * der die Migration 006 vorhandene Anlagen eingeordnet hat.
     *
     * @return list<array<string,mixed>>
     */
    public static function festeAnlagen(array $daten): array
    {
        $format = (int) ($daten['format'] ?? 0);
        return array_values(array_filter(
            (array) ($daten['anlagen'] ?? []),
            static fn ($a) => is_array($a) && ($format >= 2 ? !empty($a['fest']) : ($a['art'] ?? '') === 'anschluss')
        ));
    }

    /**
     * Die fest verlegten Kabel und Kabelwege einer strom.json.
     *
     * Erst ab Format 2 und nur, was als fest gekennzeichnet ist – Format 1
     * enthielt auch die mobilen Kabel einer Veranstaltung.
     *
     * @return list<array<string,mixed>>
     */
    public static function festeLeitungen(array $daten): array
    {
        if ((int) ($daten['format'] ?? 0) < 2) {
            return [];
        }
        return array_values(array_filter(
            (array) ($daten['leitungen'] ?? []),
            static fn ($l) => is_array($l) && !empty($l['fest']) && in_array($l['art'] ?? '', ['kabel', 'kabelweg'], true)
        ));
    }

    /**
     * Die Feststromanschlüsse aus strom.json auf einen Standort anwenden.
     *
     * Wie in MasterAdmin wird beim Übernehmen nichts gelöscht, was hier
     * zusätzlich steht – schon gar nicht die mobilen Anlagen und Kabel der
     * laufenden Planung. Ein Feststromanschluss aus der Datei bekommt seine
     * Anschlüsse und Schutzorgane so, wie sie dort stehen; Kabel, die hier
     * an einem seiner Abgänge hängen, bleiben über „richtung:nr“ daran.
     * Danach kommen die fest verlegten Kabel und Kabelwege dazwischen.
     *
     * Optionen (Vorgaben = Git-Abgleich, wie bisher):
     *   etagen              Etagen-Schlüssel der Datei → Etage hier. Ohne:
     *                       über die eigenen Schlüssel der Etagen. Mit: nur
     *                       diese Etagen; eine Anlage auf einer anderen Etage
     *                       wird übergangen.
     *   zuordnung           'git': über den (instanzweiten) sync_key, wie
     *                       bisher. 'partner': innerhalb des Standorts über
     *                       partner_schluessel, dann über den Schlüssel, unter
     *                       dem dieser Eintrag dorthin geschickt wurde
     *                       („gesendet_als“ ↔ partner_gesendet), sonst über
     *                       einen noch nicht verknüpften sync_key; schreibt
     *                       partner_schluessel, nie sync_key. 'standort':
     *                       innerhalb des Standorts über die Zuordnung des
     *                       Tokens (api_zuordnungen), sonst über sync_key; Neues
     *                       bekommt einen freien und wird für das Token
     *                       vermerkt. Ohne Etage („etage“: null) gibt es bei
     *                       'standort' nicht – übergangen. Bei beiden zählen
     *                       nur feste Anlagen und feste Kabel/Kabelwege: trifft
     *                       ein Schlüssel einen mobilen Eintrag (oder eine
     *                       Kabelbrücke), wird er übergangen – die mobile
     *                       Planung hier bleibt, wie sie ist.
     *   token_id            bei 'standort': das Token, von dem die Datei kommt
     *   georeferenz_leeren  true: Georeferenz aus der Datei, eine dort
     *                       fehlende wird hier entfernt (wie bisher); false:
     *                       Georeferenz hier unberührt
     *   sperre_behalten     true: eine Schaltsperre hier bleibt, auch wenn die
     *                       Datei keine hat
     *   ohne_messpunkte     true: Messpunkte hier bleiben, wie sie sind
     *   stecker_anlegen     false: fehlende Steckertypen nicht anlegen (deren
     *                       Anschlüsse fallen weg – vorher prüfen)
     *
     * Kabelenden bei 'partner' und 'standort': ein Ende an einer Anlage, die
     * dieser Lauf nicht anwendet (andere Etage, ohne Etage, nicht gewählt),
     * wird über ihren Schlüssel auf dem Standort gesucht und ihr Anschluss
     * über „richtung:nr“. Findet sich nichts, behält ein vorhandenes Kabel
     * sein bisheriges Ende; geleert wird es nur, wenn die Anlage dieses Endes
     * in diesem Lauf angewandt wurde und den Anschluss nicht mehr hat.
     *
     * @param array{etagen?:array<string,int>,zuordnung?:string,token_id?:int,georeferenz_leeren?:bool,sperre_behalten?:bool,ohne_messpunkte?:bool,stecker_anlegen?:bool} $optionen
     * @return array{ok:bool,message:string,anlagen:int,leitungen:int,uebergangen:int,schluessel:array{anlagen:array<string,string>,leitungen:array<string,string>}}
     *         schluessel: je angewandtem Eintrag Schlüssel in der Datei → Schlüssel hier (nur bei 'standort')
     */
    public static function stromAnwenden(int $siteId, array $daten, array $optionen = []): array
    {
        $leer = ['anlagen' => 0, 'leitungen' => 0, 'uebergangen' => 0, 'schluessel' => ['anlagen' => [], 'leitungen' => []]];
        if ((string) ($daten['art'] ?? '') !== 'strom') {
            return ['ok' => false, 'message' => 'strom.json ist ungültig.'] + $leer;
        }
        if ((int) ($daten['format'] ?? 0) > self::FORMAT_STROM) {
            return ['ok' => false, 'message' => 'Die Feststromanschlüsse stammen aus einer neueren PowerManagement-Fassung.'] + $leer;
        }
        $zuordnung = in_array($optionen['zuordnung'] ?? 'git', ['git', 'partner', 'standort'], true) ? (string) ($optionen['zuordnung'] ?? 'git') : 'git';
        $tokenId = $zuordnung === 'standort' && (int) ($optionen['token_id'] ?? 0) > 0 ? (int) $optionen['token_id'] : null;
        $sperreBehalten = (bool) ($optionen['sperre_behalten'] ?? false);
        $ohneMesspunkte = (bool) ($optionen['ohne_messpunkte'] ?? false);
        $stecker = self::steckertypenSichern((array) ($daten['steckertypen'] ?? []), (bool) ($optionen['stecker_anlegen'] ?? true));
        // Schlüssel von außen: bei Git wie bisher, sonst im Format der Schnittstelle.
        $schluesselOk = static fn (string $k): bool => $k !== '' && ($zuordnung === 'git' || self::schluesselFormat($k));
        $gesendetAls = static fn (array $eintrag): ?string => $zuordnung === 'partner' && is_string($eintrag['gesendet_als'] ?? null)
            && self::schluesselFormat($eintrag['gesendet_als']) ? $eintrag['gesendet_als'] : null;

        $nurEtagen = is_array($optionen['etagen'] ?? null);
        $etagen = [];
        if ($nurEtagen) {
            // Nur Etagen dieses Standorts – eine fremde ID bekäme sonst Anlagen.
            $hier = array_map('intval', array_column(Database::select('SELECT id FROM floors WHERE site_id = ?', [$siteId]), 'id'));
            foreach ($optionen['etagen'] as $etageKey => $etageId) {
                if (in_array((int) $etageId, $hier, true)) {
                    $etagen[(string) $etageKey] = (int) $etageId;
                }
            }
        } else {
            foreach (Database::select('SELECT id, sync_key FROM floors WHERE site_id = ?', [$siteId]) as $e) {
                $etagen[(string) $e['sync_key']] = (int) $e['id'];
            }
        }

        // Georeferenz je Etage – nur, wenn die Datei sie kennt (ältere Fassungen
        // schreiben den Schlüssel nicht; dann bleibt die hiesige).
        if (($optionen['georeferenz_leeren'] ?? true) && is_array($daten['georeferenz'] ?? null)) {
            foreach ($etagen as $etageKey => $etageId) {
                // Etagen ohne Schlüssel kennt die Datei nicht – ihre bleibt.
                if ($etageKey !== '') {
                    PlanGeoService::uebernehmen($etageId, $daten['georeferenz'][$etageKey] ?? null);
                }
            }
        }

        $objektIds = [];
        $anschlussIds = [];
        $anlagenZahl = 0;
        $uebergangen = 0;
        $zuordnungAnlagen = $zuordnungLeitungen = [];
        foreach (self::festeAnlagen($daten) as $a) {
            $key = (string) ($a['schluessel'] ?? '');
            if (!$schluesselOk($key) || !isset(AnlagenService::ARTEN[$a['art'] ?? ''])) {
                $uebergangen++;
                continue;
            }
            $etageKey = isset($a['etage']) && $a['etage'] !== null ? (string) $a['etage'] : null;
            $floorId = $etageKey !== null ? ($etagen[$etageKey] ?? null) : null;
            if (($etageKey !== null && $floorId === null && $nurEtagen) || ($etageKey === null && $zuordnung === 'standort')) {
                $uebergangen++;
                continue;
            }
            $vorhanden = self::anlageFinden($siteId, $key, $zuordnung, $tokenId, $gesendetAls($a));
            if ($vorhanden !== null && $zuordnung !== 'git' && (int) $vorhanden['fest'] !== 1) {
                // Hier inzwischen mobil: gehört zur Planung der Veranstaltung –
                // weder wird er wieder fest noch kommt ein zweiter dazu.
                $uebergangen++;
                continue;
            }
            $gesperrt = !empty($a['steuerung_gesperrt'])
                || ($sperreBehalten && $vorhanden !== null && (int) $vorhanden['steuerung_gesperrt'] === 1);
            $ergebnis = AnlagenService::speichern($vorhanden !== null ? (int) $vorhanden['id'] : null, [
                'art' => $a['art'], 'name' => (string) ($a['name'] ?? $key), 'kennung' => (string) ($a['kennung'] ?? ''),
                'site_id' => $siteId, 'floor_id' => $floorId,
                'hersteller' => (string) ($a['hersteller'] ?? ''), 'modell' => (string) ($a['modell'] ?? ''),
                'seriennummer' => (string) ($a['seriennummer'] ?? ''),
                'nennleistung_kva' => $a['nennleistung_kva'] ?? '', 'nennstrom_a' => $a['nennstrom_a'] ?? '',
                'spannung_v' => $a['spannung_v'] ?? 400, 'phasen' => $a['phasen'] ?? 3, 'status' => (string) ($a['status'] ?? 'geplant'),
                'eigenschaften' => (array) ($a['eigenschaften'] ?? []),
                'steuerung_gesperrt' => $gesperrt,
                'notizen' => (string) ($a['notizen'] ?? ''),
                'fest' => 1,
                'ohne_anschluesse' => true,
            ]);
            if (!$ergebnis['ok']) {
                $uebergangen++;
                continue;
            }
            $id = (int) $ergebnis['id'];
            $punkt = static fn (mixed $wert): ?float => $floorId !== null && is_numeric($wert)
                ? ($zuordnung === 'git' ? (float) $wert : max(0.0, min(100.0, (float) $wert))) : null;
            $felder = ['plan_x' => $punkt($a['x'] ?? null), 'plan_y' => $punkt($a['y'] ?? null)];
            if ($zuordnung === 'git') {
                $felder['sync_key'] = $key;
            } elseif ($zuordnung === 'partner') {
                $felder['partner_schluessel'] = $key;
                $felder['partner_gesendet'] = '';
            } elseif ($vorhanden === null) {
                $felder['sync_key'] = self::freierSchluessel('objekte', $key);
            }
            Database::update('objekte', $id, $felder);
            if ($zuordnung === 'standort') {
                if ($vorhanden === null && $tokenId !== null) {
                    self::zuordnungMerken($tokenId, 'objekte', $key, $id);
                }
                $zuordnungAnlagen[$key] = (string) ($felder['sync_key'] ?? $vorhanden['sync_key'] ?? '');
            }
            $objektIds[$key] = $id;
            if ($ohneMesspunkte) {
                unset($a['messpunkte']);
            }
            $anschlussIds[$key] = self::belegungErsetzen($id, $a, $stecker);
            $anlagenZahl++;
        }
        $angewandt = array_flip(array_values($objektIds));

        $leitungenZahl = 0;
        foreach (self::festeLeitungen($daten) as $l) {
            $key = (string) ($l['schluessel'] ?? '');
            $floorId = $etagen[(string) ($l['etage'] ?? '')] ?? null;
            if (!$schluesselOk($key) || $floorId === null) {
                $uebergangen++;
                continue;
            }
            $vorhanden = self::leitungFinden($siteId, $key, $zuordnung, $tokenId, $gesendetAls($l));
            if ($vorhanden !== null && $zuordnung !== 'git' && ((int) $vorhanden['fest'] !== 1 || (string) $vorhanden['art'] === 'kabelbruecke')) {
                $uebergangen++;
                continue;
            }
            $vorhandenId = $vorhanden !== null ? (int) $vorhanden['id'] : null;
            $text = static fn (mixed $wert): ?string => is_string($wert) ? $wert : null;
            $ende = function (?string $objekt, ?string $anschluss, string $seite) use ($objektIds, $anschlussIds, $angewandt, $vorhanden, $zuordnung, $siteId, $tokenId): array {
                $bisher = $vorhanden !== null && $vorhanden[$seite . '_objekt_id'] !== null
                    ? [(int) $vorhanden[$seite . '_objekt_id'], $vorhanden[$seite . '_anschluss_id'] !== null ? (int) $vorhanden[$seite . '_anschluss_id'] : null]
                    : null;
                if ($objekt !== null && isset($objektIds[$objekt])) {
                    // In diesem Lauf angewandt: der Anschluss kommt aus der Datei – fehlt er dort, ist das Ende offen.
                    return [$objektIds[$objekt], $anschluss !== null ? ($anschlussIds[$objekt][$anschluss] ?? null) : null];
                }
                if ($zuordnung === 'git') {
                    return [null, null];
                }
                if ($objekt === null) {
                    // Offen in der Datei. Hängt das Kabel hier an etwas, das dieser
                    // Lauf nicht anfasst (eine mobile Anlage, eine andere Etage), bleibt das.
                    return $bisher !== null && !isset($angewandt[$bisher[0]]) ? $bisher : [null, null];
                }
                $gefunden = self::anlageFinden($siteId, $objekt, $zuordnung, $tokenId, null);
                if ($gefunden === null || (int) $gefunden['fest'] !== 1) {
                    return $bisher ?? [null, null];
                }
                $oid = (int) $gefunden['id'];
                if ($anschluss === null) {
                    return [$oid, null];
                }
                $aid = self::anschlussNach($oid, $anschluss);
                if ($aid !== null) {
                    return [$oid, $aid];
                }
                // Die Anlage hat hier keinen solchen Anschluss – ein vorhandenes Ende bleibt.
                return $bisher !== null && $bisher[0] === $oid ? $bisher : [$oid, null];
            };
            [$vonO, $vonA] = $ende($text($l['von'] ?? null), $text($l['von_anschluss'] ?? null), 'von');
            [$zuO, $zuA] = $ende($text($l['zu'] ?? null), $text($l['zu_anschluss'] ?? null), 'zu');
            // Vorher von den Anschlüssen lösen, damit die Prüfung „Anschluss
            // schon belegt“ nicht am eigenen alten Stand scheitert.
            if ($vorhandenId !== null) {
                Database::update('leitungen', $vorhandenId, ['von_anschluss_id' => null, 'zu_anschluss_id' => null]);
            }
            $felder = [
                'art' => (string) $l['art'], 'fest' => 1, 'floor_id' => $floorId, 'name' => (string) ($l['name'] ?? ''),
                'von_objekt_id' => $vonO, 'von_anschluss_id' => $vonA, 'zu_objekt_id' => $zuO, 'zu_anschluss_id' => $zuA,
                'kabeltyp' => (string) ($l['kabeltyp'] ?? ''), 'adern' => $l['adern'] ?? '',
                'querschnitt_mm2' => $l['querschnitt_mm2'] ?? '', 'laenge_m' => $l['laenge_m'] ?? '',
                'anzahl' => $l['anzahl'] ?? 1, 'modell' => (string) ($l['modell'] ?? ''),
                'punkte' => (array) ($l['punkte'] ?? []), 'farbe' => (string) ($l['farbe'] ?? ''), 'notizen' => (string) ($l['notizen'] ?? ''),
            ];
            $ergebnis = LeitungService::speichern($vorhandenId, $felder);
            if (!$ergebnis['ok'] && ($vonA !== null || $zuA !== null)) {
                // Steckt hier schon ein mobiles Kabel im Anschluss, kommt das
                // feste trotzdem an – es endet dann an der Anlage selbst.
                $ergebnis = LeitungService::speichern($vorhandenId, ['von_anschluss_id' => null, 'zu_anschluss_id' => null] + $felder);
            }
            if ($ergebnis['ok']) {
                $lid = (int) $ergebnis['id'];
                $felder = match (true) {
                    $zuordnung === 'git'      => ['sync_key' => $key],
                    $zuordnung === 'partner'  => ['partner_schluessel' => $key, 'partner_gesendet' => ''],
                    $vorhandenId === null     => ['sync_key' => self::freierSchluessel('leitungen', $key)],
                    default                   => [],
                };
                Database::update('leitungen', $lid, $felder);
                if ($zuordnung === 'standort') {
                    if ($vorhandenId === null && $tokenId !== null) {
                        self::zuordnungMerken($tokenId, 'leitungen', $key, $lid);
                    }
                    $zuordnungLeitungen[$key] = (string) ($felder['sync_key'] ?? $vorhanden['sync_key'] ?? '');
                }
                $leitungenZahl++;
            } else {
                $uebergangen++;
            }
        }
        \App\Auth\Zugriff::vergessen();
        $meldung = $anlagenZahl === 1 ? '1 Feststromanschluss' : $anlagenZahl . ' Feststromanschlüsse';
        if ($leitungenZahl > 0) {
            $meldung .= ' und ' . ($leitungenZahl === 1 ? '1 fest verlegte Leitung' : $leitungenZahl . ' fest verlegte Leitungen');
        }
        return ['ok' => true, 'message' => $meldung . ' übernommen.', 'anlagen' => $anlagenZahl, 'leitungen' => $leitungenZahl, 'uebergangen' => $uebergangen,
            'schluessel' => ['anlagen' => $zuordnungAnlagen, 'leitungen' => $zuordnungLeitungen]];
    }

    /** Der Anschluss „richtung:nr“ einer Anlage hier – oder null. */
    private static function anschlussNach(int $objektId, string $ref): ?int
    {
        if (preg_match('/^(eingang|abgang):(\d{1,6})$/', $ref, $m) !== 1) {
            return null;
        }
        $id = Database::scalar('SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = ? AND nr = ? ORDER BY id LIMIT 1', [$objektId, $m[1], (int) $m[2]]);
        return $id !== false && $id !== null ? (int) $id : null;
    }

    /**
     * Vermerken, unter welchem Schlüssel ein Token einen Eintrag geschickt
     * hat, der hier neu angelegt wurde – beim nächsten Mal wird er darüber
     * wiedergefunden, auch wenn er hier einen anderen Schlüssel bekam.
     */
    private static function zuordnungMerken(int $tokenId, string $tabelle, string $fremd, int $zielId): void
    {
        Database::execute('DELETE FROM api_zuordnungen WHERE token_id = ? AND tabelle = ? AND fremd_schluessel = ?', [$tokenId, $tabelle, $fremd]);
        Database::insert('api_zuordnungen', [
            'token_id' => $tokenId, 'tabelle' => $tabelle, 'fremd_schluessel' => mb_substr($fremd, 0, 190), 'ziel_id' => $zielId, 'created_at' => gmdate('c'),
        ]);
    }

    /**
     * Die Anlage hier zu einem Schlüssel aus einer Datei.
     *
     * 'git' sucht instanzweit über sync_key (wie seit jeher), die beiden
     * anderen nur auf diesem Standort – sonst zöge eine übernommene Location
     * eine gleichnamige Anlage von einem ganz anderen Standort herüber.
     * Katalog-Typen führen ihre Schlüssel für den Verteiler-Abgleich – ein
     * Feststromanschluss mit demselben Schlüssel ist etwas anderes.
     *
     * Bei 'partner' und 'standort' gilt der erste Schritt, der etwas findet;
     * innerhalb eines Schritts geht ein fester Eintrag vor. Ist der Treffer
     * mobil, übergeht der Aufrufer ihn (Spalte fest).
     *
     * @return array{id:int,steuerung_gesperrt:int,fest:int,sync_key:string}|null
     */
    private static function anlageFinden(int $siteId, string $key, string $zuordnung, ?int $tokenId = null, ?string $gesendetAls = null): ?array
    {
        $spalten = 'SELECT id, steuerung_gesperrt, fest, sync_key FROM objekte WHERE ';
        if ($zuordnung === 'git') {
            return Database::first($spalten . 'sync_key = ? AND ist_vorlage = 0', [$key]);
        }
        $schritte = [];
        if ($zuordnung === 'partner') {
            $schritte[] = ['partner_schluessel = ?', [$key]];
            if ($gesendetAls !== null) {
                $schritte[] = ["partner_schluessel = '' AND (partner_gesendet = ? OR (partner_gesendet = '' AND sync_key = ?))", [$gesendetAls, $gesendetAls]];
            }
            $schritte[] = ["sync_key = ? AND partner_schluessel = '' AND partner_gesendet = ''", [$key]];
        } else {
            if ($tokenId !== null) {
                $schritte[] = ["id IN (SELECT ziel_id FROM api_zuordnungen WHERE token_id = ? AND tabelle = 'objekte' AND fremd_schluessel = ?)", [$tokenId, $key]];
            }
            $schritte[] = ['sync_key = ?', [$key]];
        }
        foreach ($schritte as [$bedingung, $werte]) {
            $z = Database::first($spalten . 'site_id = ? AND ist_vorlage = 0 AND ' . $bedingung . ' ORDER BY fest DESC, id LIMIT 1', [$siteId, ...$werte]);
            if ($z !== null) {
                return $z;
            }
        }
        return null;
    }

    /**
     * Die Leitung hier zu einem Schlüssel aus einer Datei – Regeln wie bei
     * anlageFinden(); innerhalb eines Schritts geht ein festes Kabel oder ein
     * fester Kabelweg vor (Spalten fest, art für den Aufrufer).
     *
     * @return array<string,mixed>|null id, fest, art, sync_key und die Enden
     */
    private static function leitungFinden(int $siteId, string $key, string $zuordnung, ?int $tokenId = null, ?string $gesendetAls = null): ?array
    {
        $spalten = 'SELECT l.id, l.fest, l.art, l.sync_key, l.von_objekt_id, l.von_anschluss_id, l.zu_objekt_id, l.zu_anschluss_id FROM leitungen l ';
        if ($zuordnung === 'git') {
            return Database::first($spalten . 'WHERE l.sync_key = ?', [$key]);
        }
        $schritte = [];
        if ($zuordnung === 'partner') {
            $schritte[] = ['l.partner_schluessel = ?', [$key]];
            if ($gesendetAls !== null) {
                $schritte[] = ["l.partner_schluessel = '' AND (l.partner_gesendet = ? OR (l.partner_gesendet = '' AND l.sync_key = ?))", [$gesendetAls, $gesendetAls]];
            }
            $schritte[] = ["l.sync_key = ? AND l.partner_schluessel = '' AND l.partner_gesendet = ''", [$key]];
        } else {
            if ($tokenId !== null) {
                $schritte[] = ["l.id IN (SELECT ziel_id FROM api_zuordnungen WHERE token_id = ? AND tabelle = 'leitungen' AND fremd_schluessel = ?)", [$tokenId, $key]];
            }
            $schritte[] = ['l.sync_key = ?', [$key]];
        }
        foreach ($schritte as [$bedingung, $werte]) {
            $z = Database::first($spalten . 'JOIN floors f ON f.id = l.floor_id WHERE f.site_id = ? AND ' . $bedingung
                . " ORDER BY CASE WHEN l.fest = 1 AND l.art <> 'kabelbruecke' THEN 0 ELSE 1 END, l.id LIMIT 1", [$siteId, ...$werte]);
            if ($z !== null) {
                return $z;
            }
        }
        return null;
    }

    /**
     * Schutzorgane, Anschlüsse und Messpunkte einer Anlage aus der Datei
     * setzen. Was hier daran hängt, behält seinen Platz: Kabel, Messpunkte
     * (auch frühere und die eines eingesetzten Inventarstücks) und Einsätze
     * zeigen danach auf den neuen Anschluss mit derselben „richtung:nr“,
     * Messpunkte auf das Schutzorgan an derselben Stelle (s1, s2 …). Fehlt
     * das Gegenstück, wird der Verweis leer – nie zeigt er ins Leere.
     *
     * @param array<string,int> $stecker schluessel => id
     * @return array<string,int> „richtung:nr“ => neue anschluss_id
     */
    public static function belegungErsetzen(int $objektId, array $a, array $stecker): array
    {
        $alt = [];
        foreach (Database::select('SELECT id, richtung, nr FROM anschluesse WHERE objekt_id = ?', [$objektId]) as $r) {
            $alt[(int) $r['id']] = $r['richtung'] . ':' . (int) $r['nr'];
        }
        // Dieselbe Zählung wie belegungLesen(): s1, s2 … in der Reihenfolge des Baums.
        $altSchutz = [];
        foreach (SchutzService::liste($objektId) as $n => $s) {
            $altSchutz[(int) $s['id']] = 's' . ($n + 1);
        }
        Database::execute('DELETE FROM schutzorgane WHERE objekt_id = ?', [$objektId]);
        Database::execute('DELETE FROM anschluesse WHERE objekt_id = ?', [$objektId]);

        $schutzIds = [];
        foreach ((array) ($a['schutzorgane'] ?? []) as $n => $s) {
            $schutzIds[(string) ($s['ref'] ?? 's' . ($n + 1))] = Database::insert('schutzorgane', [
                'objekt_id' => $objektId, 'art' => isset(SchutzService::ARTEN[$s['art'] ?? '']) ? $s['art'] : 'sonstiges',
                'bezeichnung' => mb_substr((string) ($s['bezeichnung'] ?? ''), 0, 64),
                'nennstrom_a' => isset($s['nennstrom_a']) ? (float) $s['nennstrom_a'] : null,
                'charakteristik' => mb_substr((string) ($s['charakteristik'] ?? ''), 0, 8),
                'fehlerstrom_ma' => isset($s['fehlerstrom_ma']) ? (int) $s['fehlerstrom_ma'] : null,
                'rcd_typ' => mb_substr((string) ($s['rcd_typ'] ?? ''), 0, 8), 'pole' => (int) ($s['pole'] ?? 3),
                'vorgeschaltet_id' => null, 'notizen' => (string) ($s['notizen'] ?? ''), 'reihenfolge' => $n + 1,
                'created_at' => gmdate('c'),
            ]);
        }
        foreach ((array) ($a['schutzorgane'] ?? []) as $n => $s) {
            $ref = (string) ($s['ref'] ?? 's' . ($n + 1));
            if (!empty($s['vor']) && isset($schutzIds[(string) $s['vor']])) {
                Database::update('schutzorgane', $schutzIds[$ref], ['vorgeschaltet_id' => $schutzIds[(string) $s['vor']]]);
            }
        }

        $neu = [];
        foreach ((array) ($a['anschluesse'] ?? []) as $an) {
            $typ = $stecker[(string) ($an['stecker'] ?? '')] ?? null;
            if ($typ === null) {
                continue;
            }
            $richtung = ($an['richtung'] ?? '') === 'eingang' ? 'eingang' : 'abgang';
            $nr = max(1, (int) ($an['nr'] ?? 1));
            $neu[$richtung . ':' . $nr] = Database::insert('anschluesse', [
                'objekt_id' => $objektId, 'richtung' => $richtung, 'steckertyp_id' => $typ, 'nr' => $nr,
                'bezeichnung' => mb_substr((string) ($an['bezeichnung'] ?? ''), 0, 64),
                'schutzorgan_id' => !empty($an['schutz']) ? ($schutzIds[(string) $an['schutz']] ?? null) : null,
                'phase' => mb_substr((string) ($an['phase'] ?? ''), 0, 8), 'notizen' => (string) ($an['notizen'] ?? ''),
                'reihenfolge' => $nr, 'created_at' => gmdate('c'),
            ]);
        }
        // Verweise von Kabeln, die hier nicht aus der Datei kommen, umhängen –
        // ebenso Messpunkte und Einsätze an diesen Anschlüssen.
        foreach ($alt as $altId => $ref) {
            $neuId = $neu[$ref] ?? null;
            Database::execute('UPDATE leitungen SET von_anschluss_id = ? WHERE von_anschluss_id = ?', [$neuId, $altId]);
            Database::execute('UPDATE leitungen SET zu_anschluss_id = ? WHERE zu_anschluss_id = ?', [$neuId, $altId]);
            Database::execute('UPDATE messpunkte SET anschluss_id = ? WHERE anschluss_id = ?', [$neuId, $altId]);
            Database::execute('UPDATE einsaetze SET anschluss_id = ? WHERE anschluss_id = ?', [$neuId, $altId]);
        }
        foreach ($altSchutz as $altId => $ref) {
            Database::execute('UPDATE messpunkte SET schutzorgan_id = ? WHERE schutzorgan_id = ?', [$schutzIds[$ref] ?? null, $altId]);
        }

        if (array_key_exists('messpunkte', $a)) {
            Database::execute('DELETE FROM messpunkte WHERE objekt_id = ? AND einsatz_id IS NULL', [$objektId]);
            foreach ((array) $a['messpunkte'] as $m) {
                if (trim((string) ($m['geraet'] ?? '')) === '') {
                    continue;
                }
                Database::insert('messpunkte', [
                    'objekt_id' => $objektId,
                    'anschluss_id' => !empty($m['anschluss']) ? ($neu[(string) $m['anschluss']] ?? null) : null,
                    'schutzorgan_id' => !empty($m['schutz']) ? ($schutzIds[(string) $m['schutz']] ?? null) : null,
                    'geraet' => mb_substr((string) $m['geraet'], 0, 128), 'kanal' => mb_substr((string) ($m['kanal'] ?? ''), 0, 64),
                    'rolle' => isset(MesspunktService::ROLLEN[$m['rolle'] ?? '']) ? $m['rolle'] : 'einspeisung',
                    'bezeichnung' => mb_substr((string) ($m['bezeichnung'] ?? ''), 0, 190), 'created_at' => gmdate('c'),
                ]);
            }
        }
        return $neu;
    }
}
