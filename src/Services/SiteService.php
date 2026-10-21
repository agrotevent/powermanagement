<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Berichte\BerichtService;
use App\Kunden\KundenService;
use App\Support\Anfragezeit;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Response;

/**
 * Standorte und Etagen inklusive der hochgeladenen Grundrisse.
 *
 * Ein Grundriss-PDF kann mehrere Etagen enthalten: die Datei wird einmal
 * gespeichert, jede Etage verweist auf dieselbe Datei mit ihrer Seitenzahl.
 * Übernommen aus MasterAdmin – beide Werkzeuge teilen sich über den
 * Location-Abgleich dieselben Grundrisse.
 */
final class SiteService
{
    /**
     * Größte Plandatei – die eine Zahl, an der alles hängt: Formular und
     * Prüfung im Browser (data-max-bytes, app.js), die Prüfung hier, das
     * Übernehmen aus dem Repository (LocationBundle) und das Holen von einer
     * anderen Instanz (PartnerService). Gerechnet in MiB, angezeigt als
     * „100 MB“ wie früher „40 MB“.
     *
     * Die Vorlagen in deploy/ passen dazu: upload_max_filesize 100M und
     * post_max_size 105M im PHP-FPM-Pool, client_max_body_size 105m für
     * /standorte/grundriss in der nginx-Site. Was PHP hier tatsächlich
     * annimmt, sagt uploadGrenze().
     */
    public const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

    /**
     * So viele Sekunden kachelt der Upload selbst, bevor er antwortet.
     *
     * Ein gewöhnlicher Plan ist in dieser Zeit fertig. Ein 100-MB-Plan
     * nicht: gemessen 73 Sekunden für einen eingebetteten Scan, 270 für
     * eine A0-Seite mit 104 MB Vektordaten, eine halbe Stunde für 60
     * Seiten. Was dann noch fehlt, rechnet ein eigener Prozess weiter
     * (PlanTileService::imHintergrund) – der Browser wartet nicht, bis
     * nginx aufgibt.
     */
    public const KACHELZEIT_IM_UPLOAD = 30;
    public const ALLOWED = [
        'application/pdf' => 'pdf',
        'image/png'       => 'png',
        'image/jpeg'      => 'jpg',
        'image/svg+xml'   => 'svg',
    ];

    public static function planDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/plans';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    // ------------------------------------------------------------- Standorte

    public static function all(): array
    {
        $sites = Database::select('SELECT * FROM sites ORDER BY name');
        foreach ($sites as &$site) {
            $site['floors'] = self::floors((int) $site['id']);
            $site['objekt_count'] = (int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE site_id = ? AND ist_vorlage = 0', [(int) $site['id']]);
        }
        return $sites;
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM sites WHERE id = ?', [$id]);
    }

    public static function saveSite(?int $id, array $data): int
    {
        if ($id === null || $id === 0) {
            $id = Database::insert('sites', [
                'name'       => (string) $data['name'],
                'address'    => (string) ($data['address'] ?? ''),
                'notes'      => (string) ($data['notes'] ?? ''),
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            ]);
            Audit::log('site.create', 'site', (string) $id, ['name' => $data['name']]);
            return $id;
        }

        Database::update('sites', $id, [
            'name'       => (string) $data['name'],
            'address'    => (string) ($data['address'] ?? ''),
            'notes'      => (string) ($data['notes'] ?? ''),
            'updated_at' => gmdate('c'),
        ]);
        Audit::log('site.update', 'site', (string) $id, ['name' => $data['name']]);
        return $id;
    }

    public static function deleteSite(int $id): void
    {
        foreach (self::floors($id) as $floor) {
            self::deleteFloor((int) $floor['id']);
        }
        // Anlagen bleiben erhalten, stehen danach aber auf keinem Standort –
        // ein Generator verschwindet nicht, weil sein Einsatzort gelöscht wird.
        BereichService::standortEntfernt($id);
        AufbauService::standortEntfernt($id);
        // Was ein Token hier angelegt hat, gehört zu diesem Standort – ohne ihn
        // findet die Schnittstelle es nicht mehr wieder.
        Database::execute("DELETE FROM api_zuordnungen WHERE tabelle = 'objekte' AND ziel_id IN (SELECT id FROM objekte WHERE site_id = ?)", [$id]);
        // Übergabeberichte bleiben (mit dem Namen des Standorts), nur Entwürfe ohne Nummer gehen mit.
        BerichtService::standortEntfernt($id);
        // Freigaben von Kunden gehen mit; ihre Vorgänge und Prüfnachweise bleiben, nur ohne Standort.
        KundenService::standortEntfernt($id);
        Database::execute('UPDATE objekte SET site_id = NULL, floor_id = NULL, plan_x = NULL, plan_y = NULL WHERE site_id = ?', [$id]);
        foreach (Database::select('SELECT id FROM floors WHERE site_id = ?', [$id]) as $etage) {
            Database::execute('DELETE FROM leitungen WHERE floor_id = ?', [(int) $etage['id']]);
        }
        Database::execute('DELETE FROM floors WHERE site_id = ?', [$id]);
        // Kein Token sieht einen gelöschten Standort mehr; Vorschläge dazu
        // bleiben zur Nachvollziehbarkeit stehen, nur ohne Standort.
        Database::execute('DELETE FROM api_token_standorte WHERE site_id = ?', [$id]);
        Database::execute('UPDATE api_vorschlaege SET site_id = NULL WHERE site_id = ?', [$id]);
        Database::delete('sites', $id);
        Audit::log('site.delete', 'site', (string) $id);
    }

    // --------------------------------------------------------------- Etagen

    public static function floors(int $siteId): array
    {
        $floors = Database::select('SELECT * FROM floors WHERE site_id = ? ORDER BY sort_order, level_no, name', [$siteId]);
        foreach ($floors as &$floor) {
            $floor['objekt_count']  = (int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE floor_id = ?', [(int) $floor['id']]);
            $floor['leitung_count'] = (int) Database::scalar('SELECT COUNT(*) FROM leitungen WHERE floor_id = ?', [(int) $floor['id']]);
        }
        return $floors;
    }

    public static function allFloors(): array
    {
        return Database::select(
            'SELECT f.*, s.name AS site_name FROM floors f
             JOIN sites s ON s.id = f.site_id ORDER BY s.name, f.sort_order, f.level_no',
        );
    }

    public static function floor(int $id): ?array
    {
        return Database::first(
            'SELECT f.*, s.name AS site_name FROM floors f JOIN sites s ON s.id = f.site_id WHERE f.id = ?',
            [$id]
        );
    }

    public static function saveFloor(?int $id, array $data): int
    {
        $fields = [
            'site_id'    => (int) $data['site_id'],
            'name'       => (string) $data['name'],
            'level_no'   => (int) ($data['level_no'] ?? 0),
            'sort_order' => (int) ($data['sort_order'] ?? ($data['level_no'] ?? 0)),
            'scale_note' => (string) ($data['scale_note'] ?? ''),
            'notes'      => (string) ($data['notes'] ?? ''),
            'updated_at' => gmdate('c'),
        ];
        if (array_key_exists('plan_page', $data)) {
            $fields['plan_page'] = max(1, (int) $data['plan_page']);
        }

        if ($id === null || $id === 0) {
            $fields['created_at'] = gmdate('c');
            $id = Database::insert('floors', $fields);
            Audit::log('floor.create', 'floor', (string) $id, ['name' => $data['name']]);
            return $id;
        }

        Database::update('floors', $id, $fields);
        Audit::log('floor.update', 'floor', (string) $id, ['name' => $data['name']]);
        return $id;
    }

    public static function deleteFloor(int $id): void
    {
        $floor = self::floor($id);
        // Anlagen verlieren nur ihren Platz im Plan, Leitungen dieser Etage
        // gehen mit: ein Kabelweg ohne Plan hat keine Lage mehr.
        Database::execute('UPDATE objekte SET floor_id = NULL, plan_x = NULL, plan_y = NULL WHERE floor_id = ?', [$id]);
        Database::execute("DELETE FROM api_zuordnungen WHERE tabelle = 'leitungen' AND ziel_id IN (SELECT id FROM leitungen WHERE floor_id = ?)", [$id]);
        Database::execute('DELETE FROM leitungen WHERE floor_id = ?', [$id]);
        Database::delete('floors', $id);

        // Plandatei nur löschen, wenn keine andere Etage sie benutzt.
        if ($floor && $floor['plan_file'] !== '') {
            self::planAufraeumen([$floor]);
        }
        Audit::log('floor.delete', 'floor', (string) $id);
    }

    /**
     * Pläne, die diese Etagen bisher hatten, wegräumen – soweit keine Etage
     * sie noch benutzt: die Kacheln, wenn keine mehr auf sie zeigt (bei
     * einem PDF je Seite), die Datei, wenn keine Etage sie mehr nennt.
     * Gerechnet wird gerade daran? Dann räumt der Bau am Ende selbst auf
     * (PlanTileService::verwaist()).
     *
     * @param list<array<string,mixed>> $etagen Zeilen von floors, wie sie vorher waren
     */
    public static function planAufraeumen(array $etagen): void
    {
        foreach ($etagen as $f) {
            $datei = basename((string) ($f['plan_file'] ?? ''));
            if ($datei === '') {
                continue;
            }
            if (PlanTileService::verwaist($f)) {
                PlanTileService::aufraeumen($f);
            }
            if ((int) Database::scalar('SELECT COUNT(*) FROM floors WHERE plan_file = ?', [$datei]) === 0) {
                @unlink(self::planDir() . '/' . $datei);
            }
        }
    }

    /**
     * Die erste Etage, für die überhaupt ein Grundriss hinterlegt ist.
     *
     * Die Grundriss-Ansicht öffnet sonst stur die erste Etage der Liste.
     * Wer eine Halle hochlädt und danach einen leeren Plan sieht, hält das
     * zu Recht für einen Fehler – dabei liegt der Grundriss nur auf einer
     * anderen Etage.
     *
     * @param array<int,array<string,mixed>> $floors
     */
    public static function ersteMitPlan(array $floors): ?array
    {
        foreach ($floors as $floor) {
            if ((string) ($floor['plan_file'] ?? '') !== '') {
                return $floor;
            }
        }
        return null;
    }

    /**
     * Nimmt einen Upload an, legt die Datei ab und verknüpft sie mit den
     * gewählten Etagen (eine Seite je Etage) bzw. legt Etagen automatisch an.
     *
     * Gekachelt wird höchstens KACHELZEIT_IM_UPLOAD Sekunden lang
     * ($options['kachelzeit'] für Prüfskripte); den Rest rechnet
     * PlanTileService::imHintergrund().
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @param array{floor_ids?:array<int|string>,neue_etagen?:bool,kachelzeit?:float|int} $options
     * @return array{ok:bool,message:string,pages:int,floors:array<int,int>}
     */
    public static function uploadPlan(int $siteId, array $file, array $options = []): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => self::uploadError((int) ($file['error'] ?? 4)), 'pages' => 0, 'floors' => []];
        }
        if ((int) $file['size'] > self::MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'message' => self::zuGross((int) $file['size']), 'pages' => 0, 'floors' => []];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) {
            return ['ok' => false, 'message' => 'Nur PDF, PNG, JPEG oder SVG sind erlaubt (erkannt: ' . $mime . ').', 'pages' => 0, 'floors' => []];
        }

        $extension = self::ALLOWED[$mime];
        if ($extension === 'png' || $extension === 'jpg') {
            $bildFehler = self::bildPruefen((string) $file['tmp_name']);
            if ($bildFehler !== null) {
                return ['ok' => false, 'message' => $bildFehler, 'pages' => 0, 'floors' => []];
            }
        }

        /*
         * Ohne Auswahl wurde bisher stillschweigend eine neue Etage
         * „Grundriss" angelegt. Der Upload meldete Erfolg, die Ansicht
         * öffnete aber weiterhin die alte Etage – der Grundriss schien
         * verschwunden. Wo er hingehört, weiß nur der Benutzer; hat der
         * Standort schon Etagen, wird deshalb nachgefragt statt geraten.
         */
        $vorhandene       = self::floors($siteId);
        // Nur Etagen dieses Standorts – eine fremde Etagen-ID aus dem
        // Formular bekäme sonst diesen Grundriss.
        $floorIdsGewaehlt = array_values(array_intersect(
            array_filter(array_map('intval', (array) ($options['floor_ids'] ?? []))),
            array_map(static fn ($f) => (int) $f['id'], $vorhandene)
        ));
        if ($floorIdsGewaehlt === [] && $vorhandene !== [] && empty($options['neue_etagen'])) {
            return [
                'ok'      => false,
                'message' => 'Bitte die Etage auswählen, zu der der Grundriss gehört – '
                    . 'oder „neue Etage anlegen" ankreuzen, wenn er auf eine eigene Etage soll.',
                'pages'   => 0,
                'floors'  => [],
            ];
        }

        // Die Seiten zählen, solange die Datei noch im Upload-Ordner liegt:
        // Scheitert es hart, räumt PHP sie dort selbst weg – in storage/plans
        // bliebe sie ohne Etage liegen, bei jedem Versuch 100 MB mehr.
        $pages = $extension === 'pdf' ? self::countPdfPages((string) $file['tmp_name']) : 1;
        $type  = $extension === 'pdf' ? 'pdf' : 'image';

        $name = sprintf('site%d_%s.%s', $siteId, bin2hex(random_bytes(8)), $extension);
        $target = self::planDir() . '/' . $name;

        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $target)
            : rename($file['tmp_name'], $target);
        if (!$moved) {
            return ['ok' => false, 'message' => 'Datei konnte nicht gespeichert werden.', 'pages' => 0, 'floors' => []];
        }
        chmod($target, 0640);

        $floorIds = $floorIdsGewaehlt;
        $created  = [];

        if ($floorIds === []) {
            // Für jede Seite eine Etage anlegen, Namen später anpassbar.
            for ($page = 1; $page <= $pages; $page++) {
                $floorIds[] = self::saveFloor(null, [
                    'site_id'  => $siteId,
                    'name'     => $pages > 1 ? 'Etage (Seite ' . $page . ')' : 'Grundriss',
                    'level_no' => $page - 1,
                    'sort_order' => $page - 1,
                ]);
                $created[] = end($floorIds);
            }
        }

        // Was die Etagen bisher hatten – wird es danach von keiner mehr
        // benutzt, kommt es weg (planAufraeumen()).
        $vorher = array_values(array_filter(array_map(static fn ($id) => self::floor((int) $id), $floorIds),
            static fn (?array $f): bool => $f !== null && (string) $f['plan_file'] !== ''));

        $page = 1;
        foreach ($floorIds as $floorId) {
            Database::update('floors', (int) $floorId, [
                'plan_file'          => $name,
                'plan_type'          => $type,
                'plan_page'          => min($page, max(1, $pages)),
                'plan_original_name' => substr((string) $file['name'], 0, 255),
                'plan_uploaded_at'   => gmdate('c'),
                'plan_uploaded_by'   => (string) (Auth::user()['username'] ?? 'system'),
                'updated_at'         => gmdate('c'),
            ]);
            $page++;
        }
        self::planAufraeumen($vorher);

        /*
         * Pläne gleich in Kacheln zerlegen – dann ist der erste Aufruf der
         * Karte so schnell wie jeder weitere.
         *
         * Und zwar für *jede* betroffene Etage, nicht nur bis die erste
         * geklappt hat: bei einem mehrseitigen PDF hat jede Seite ihre
         * eigene Pyramide. Sonst rastert die Grundriss-Seite Seite 2 beim
         * ersten Öffnen nach – und steht dabei eine Viertelminute.
         */
        $gebaut  = 0;
        $kachelN = 0;
        $klemmt  = '';
        $namen   = [];
        $spaeter = [];
        $grossesBild = $extension !== 'pdf' && $extension !== 'svg' && PlanTileService::ueberGd($target);
        $bis = microtime(true) + Anfragezeit::hoechstens((float) ($options['kachelzeit'] ?? self::KACHELZEIT_IM_UPLOAD));
        foreach ($floorIds as $floorId) {
            $etage = self::floor((int) $floorId);
            if ($etage === null) {
                continue;
            }
            $namen[] = (string) $etage['name'];

            // Die Zeit im Upload ist um: der Rest kommt in den Hintergrund.
            if (microtime(true) > $bis) {
                if (PlanTileService::lohnt($target)) {
                    $spaeter[] = (int) $floorId;
                }
                continue;
            }
            $ergebnis = PlanTileService::bauen($etage, true, true, $bis);
            if (!empty($ergebnis['abgebrochen'])) {
                $spaeter[] = (int) $floorId;
            } elseif ($ergebnis['ok'] && $ergebnis['kacheln'] > 0) {
                $gebaut++;
                $kachelN += (int) $ergebnis['kacheln'];
            } elseif (!$ergebnis['ok'] && ($extension === 'pdf' || $grossesBild) && $klemmt === '') {
                $klemmt = ' ' . $ergebnis['message'];
            }
        }

        $kacheln = $gebaut > 0
            ? sprintf(' %d Kacheln berechnet%s.', $kachelN, $gebaut > 1 ? ' (' . $gebaut . ' Seiten)' : '')
            : $klemmt;

        if ($spaeter !== []) {
            $wieviele = count($spaeter) > 1 ? count($spaeter) . ' Etagen' : 'diesen Plan';
            $kacheln .= PlanTileService::imHintergrund($spaeter)
                ? sprintf(' Die Kacheln für %s rechnet der Server im Hintergrund weiter – bei so einem Plan einige Minuten.'
                    . ' Bis dahin zeichnet der Browser den Plan selbst (langsamer, weniger scharf); danach die Karte neu laden.', $wieviele)
                : sprintf(' Die Kacheln für %s fehlen noch: bin/power plan:kacheln rechnet sie nach, sonst die nächtliche Wartung.', $wieviele);
        }

        /*
         * Ohne poppler kann der Server nicht rastern – der Browser kann es
         * aber, einmalig, auf der Grundriss-Seite. Das gehört als Weg
         * gesagt, nicht als Mangel.
         */
        if ($extension === 'pdf' && $gebaut === 0 && !PdfRasterService::verfuegbar()) {
            $kacheln = ' Auf dem Server fehlt poppler-utils – der Plan wird deshalb im Browser'
                . ' gezeichnet (mit begrenzter Zoomtiefe). Schärfer wird er nach'
                . ' apt-get install -y poppler-utils und bin/power plan:kacheln.';
        }

        Audit::log('plan.upload', 'site', (string) $siteId, [
            'file'   => $file['name'],
            'bytes'  => (int) $file['size'],
            'pages'  => $pages,
            'floors' => $floorIds,
        ] + ($spaeter !== [] ? ['im_hintergrund' => $spaeter] : []));

        // Ausdrücklich sagen, wo der Grundriss jetzt liegt – sonst sucht ihn
        // der Benutzer auf der falschen Etage.
        $wo = $namen === [] ? '' : ' Zu finden unter: ' . implode(', ', $namen) . '.';

        return [
            'ok'      => true,
            'message' => ($pages > 1
                ? sprintf('%d Seiten erkannt und auf %d Etage(n) verteilt.', $pages, count($floorIds))
                : 'Grundriss hinterlegt.') . $wo . $kacheln,
            'pages'   => $pages,
            'floors'  => array_map('intval', $floorIds),
        ];
    }

    /** Absoluter Pfad einer Plandatei – oder null, wenn nichts hinterlegt ist. */
    public static function planPath(array $floor): ?string
    {
        if (($floor['plan_file'] ?? '') === '') {
            return null;
        }
        $path = self::planDir() . '/' . basename((string) $floor['plan_file']);
        return is_file($path) ? $path : null;
    }

    /**
     * Prüfsumme und Größe der Plandatei einer Etage – null ohne Plan.
     *
     * Zwischengespeichert in plan_sha256: die Schnittstelle fragt sie für
     * jede Etage jeder Liste ab, ein großes PDF jedes Mal zu lesen wäre
     * teuer. Die Marke (Datei|Größe|Änderungszeit) sagt, ob der Wert noch
     * gilt; sonst wird neu gerechnet – für alle Etagen mit dieser Datei.
     *
     * @return array{sha256:string,bytes:int}|null
     */
    public static function planPruefsumme(array $floor): ?array
    {
        $pfad = self::planPath($floor);
        if ($pfad === null) {
            return null;
        }
        clearstatcache(true, $pfad);
        $bytes = filesize($pfad);
        if ($bytes === false) {
            return null;
        }
        $marke = self::planMarke((string) $floor['plan_file'], $pfad);
        $sha = (string) ($floor['plan_sha256'] ?? '');
        if ((string) ($floor['plan_sha_marke'] ?? '') === $marke && preg_match('/^[0-9a-f]{64}$/', $sha) === 1) {
            return ['sha256' => $sha, 'bytes' => (int) $bytes];
        }
        $sha = hash_file('sha256', $pfad);
        if ($sha === false) {
            return null;
        }
        Database::execute('UPDATE floors SET plan_sha256 = ?, plan_sha_marke = ? WHERE plan_file = ?', [$sha, $marke, (string) $floor['plan_file']]);
        return ['sha256' => $sha, 'bytes' => (int) $bytes];
    }

    /** Woran planPruefsumme() erkennt, dass die Datei noch dieselbe ist. */
    public static function planMarke(string $planFile, string $pfad): string
    {
        clearstatcache(true, $pfad);
        return substr(basename($planFile) . '|' . (int) @filesize($pfad) . '|' . (int) @filemtime($pfad), 0, 255);
    }

    /**
     * Die Plandatei einer Etage senden – nach der Prüfung, ob der Anfragende
     * sie sehen darf (das ist Sache des Aufrufers). false: kein Plan da,
     * nichts gesendet; die passende 404 schickt der Aufrufer.
     *
     * Ein Grundriss ist ein Bild und sonst nichts – ein SVG mit Skript soll
     * beim direkten Aufruf nichts ausführen dürfen (sandbox). Für das
     * Personal unverändert wie bisher (KarteController::planDatei). Für
     * Kunden ($fuerKunden):
     *  - nie zwischengespeichert (ein Firmenrechner wird geteilt),
     *  - ein PDF als Anhang – die sandbox-Richtlinie sperrt Chromes
     *    PDF-Ansicht, inline bliebe die Seite leer,
     *  - ein neutraler Dateiname nur aus [A-Za-z0-9._-] statt des Namens,
     *    unter dem der Plan einmal hochgeladen wurde.
     */
    public static function planAusliefern(array $etage, bool $fuerKunden = false): bool
    {
        $pfad = self::planPath($etage);
        if ($pfad === null) {
            return false;
        }
        $mime = self::planMime($etage);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($pfad));
        if ($fuerKunden) {
            $endung = self::ALLOWED[$mime] ?? 'bin';
            $name = strtr('plan-' . (string) $etage['name'], ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
            header('Content-Disposition: ' . ($mime === 'application/pdf' ? 'attachment' : 'inline') . '; filename="'
                . Response::sichererDateiname($name . '.' . $endung, 'plan.' . $endung) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
        } else {
            header('Content-Disposition: inline; filename="' . basename((string) $etage['plan_original_name'] ?: 'grundriss') . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=600');
        }
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('Referrer-Policy: no-referrer');
        readfile($pfad);
        return true;
    }

    public static function planMime(array $floor): string
    {
        return match (pathinfo((string) $floor['plan_file'], PATHINFO_EXTENSION)) {
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg'  => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    /**
     * Seitenzahl eines PDFs ohne externe Werkzeuge bestimmen.
     * Reicht für die üblichen CAD-Exporte; im Zweifel korrigiert der Browser
     * die Anzeige und die Seite lässt sich je Etage von Hand setzen.
     *
     * Gezählt wird ohne Liste der Treffer: Ein PDF mit 100 MB aus lauter
     * „/Type/Page“ hat fast zehn Millionen davon, und als Array hielten sie
     * gemessen über 512 MB – die Anfrage starb an memory_limit. So bleibt es
     * bei der Datei selbst im Speicher (100 MB) und einer Drittelsekunde.
     */
    public static function countPdfPages(string $path): int
    {
        $content = (string) file_get_contents($path);

        $count = (int) preg_match_all('/\/Type\s*\/Page\b(?!s)/', $content);
        if ($count > 0) {
            return min($count, self::MAX_PDF_SEITEN);
        }
        // Die größte Zahl hinter /Count – der Reihe nach, höchstens so viele
        // Treffer, wie ein echtes PDF Seitenbäume hat. Die Zahl steht im PDF:
        // „/Count 99999999“ legte sonst so viele Etagen an und kachelte jede.
        $max = 0;
        $ab = 0;
        for ($i = 0; $i < 10_000 && $max < self::MAX_PDF_SEITEN
            && preg_match('/\/Count\s+(\d+)/', $content, $m, PREG_OFFSET_CAPTURE, $ab) === 1; $i++) {
            $max = max($max, (int) $m[1][0]);
            $ab = (int) $m[0][1] + strlen((string) $m[0][0]);
        }
        return $max > 0 ? min($max, self::MAX_PDF_SEITEN) : 1;
    }

    /** Mehr Seiten werden aus einem PDF nicht auf Etagen verteilt. */
    public const MAX_PDF_SEITEN = 60;

    /**
     * Wie viel PHP hier wirklich annimmt – höchstens MAX_UPLOAD_BYTES.
     *
     * Steht im PHP-FPM-Pool weniger (eine alte Vorlage, ein eigener Pool,
     * der eingebaute Server mit 2 MB), zeigt das Formular diese Zahl und
     * prüft der Browser gegen sie – statt 100 MB zu versprechen, die nach
     * dem Hochladen an upload_max_filesize scheitern. Vom Körper geht ein
     * wenig für die übrigen Formularfelder ab.
     */
    public static function uploadGrenze(): int
    {
        $datei = self::iniBytes('upload_max_filesize');
        $koerper = self::iniBytes('post_max_size');
        $grenze = self::MAX_UPLOAD_BYTES;
        if ($datei > 0) {
            $grenze = min($grenze, $datei);
        }
        if ($koerper > 0) {
            $grenze = min($grenze, max(0, $koerper - 64 * 1024));
        }
        return $grenze;
    }

    /**
     * Eine Byte-Zahl, wie die Oberfläche sie nennt: „100 MB“, „123,4 MB“
     * (gerechnet in MiB). $aufrunden für Dateien über einer Grenze – sonst
     * hieße es bei einem Byte zu viel „100 MB groß, erlaubt 100 MB“.
     */
    public static function mb(int $bytes, bool $aufrunden = false): string
    {
        if ($bytes % 1048576 === 0) {
            return number_format(intdiv($bytes, 1048576), 0, ',', '.') . ' MB';
        }
        $zehntel = $bytes / 104857.6;
        return number_format(($aufrunden ? ceil($zehntel) : round($zehntel)) / 10, 1, ',', '.') . ' MB';
    }

    /** Die Meldung für eine zu große Datei. */
    public static function zuGross(int $bytes): string
    {
        return sprintf('Die Datei ist %s groß – Grundrisse lassen sich bis %s hochladen.', self::mb($bytes, true), self::mb(self::MAX_UPLOAD_BYTES))
            . ' Ein PDF wird meist deutlich kleiner, wenn eingebettete Bilder mit geringerer Auflösung exportiert werden.';
    }

    /** Eine Größenangabe aus php.ini in Bytes (0 = ohne Grenze oder unlesbar). */
    private static function iniBytes(string $schluessel): int
    {
        $wert = trim((string) ini_get($schluessel));
        if ($wert === '' || $wert === '0' || $wert === '-1') {
            return 0;
        }
        return max(0, (int) @ini_parse_quantity($wert));
    }

    /**
     * Passt ein Rasterbild (PNG, JPEG) zu dem, was hier aufbereitet wird?
     *
     * Bis PlanTileService::MAX_PIXEL schneidet gd es, darüber poppler
     * (BildPdf) – sofern es sich dafür eignet und nicht größer ist als
     * MAX_PIXEL_QUELLE. Geprüft wird nur der Kopf der Datei. Ein Bild, das
     * keinen Weg hat, käme sonst ganz und unverkleinert im Browser an –
     * ein Tablet scheitert an 139 Megapixeln.
     *
     * @return string|null die Meldung, oder null, wenn es passt
     */
    public static function bildPruefen(string $pfad): ?string
    {
        $info = @getimagesize($pfad);
        if ($info === false || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            return 'Die Bilddatei ist beschädigt oder unvollständig – sie ließ sich nicht lesen.';
        }
        $pixel = (int) $info[0] * (int) $info[1];
        if ($pixel <= PlanTileService::MAX_PIXEL) {
            return null;
        }
        $wie = sprintf('%s Megapixel (%s × %s Pixel)', number_format($pixel / 1_000_000, 0, ',', '.'),
            number_format((int) $info[0], 0, ',', '.'), number_format((int) $info[1], 0, ',', '.'));
        if ($pixel > PlanTileService::MAX_PIXEL_QUELLE) {
            return sprintf('Das Bild hat %s – aufbereitet werden höchstens %d Megapixel. Bitte verkleinern oder als PDF hochladen.',
                $wie, intdiv(PlanTileService::MAX_PIXEL_QUELLE, 1_000_000));
        }
        $pruefung = BildPdf::pruefen($pfad);
        if (!$pruefung['ok']) {
            return sprintf('Das Bild hat %s. Über %d Megapixel rechnet der Server es mit poppler herunter, und dafür muss es so gespeichert sein: '
                . 'JPEG nicht progressiv, in RGB oder Graustufen – PNG ohne Transparenz und ohne Zeilensprung. %s '
                . 'Anders gespeichert oder auf höchstens %d Megapixel verkleinert klappt es.',
                $wie, intdiv(PlanTileService::MAX_PIXEL, 1_000_000), $pruefung['grund'], intdiv(PlanTileService::MAX_PIXEL, 1_000_000));
        }
        if (!PdfRasterService::verfuegbar()) {
            return sprintf('Das Bild hat %s. Über %d Megapixel rechnet der Server es mit poppler herunter. %s',
                $wie, intdiv(PlanTileService::MAX_PIXEL, 1_000_000), PdfRasterService::werkzeug()['hinweis']);
        }
        return null;
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf('Die Datei ist größer, als der Server annimmt (höchstens %s). '
                . 'Grenzen: upload_max_filesize und post_max_size im PHP-FPM-Pool, client_max_body_size in nginx – siehe docs/installation-debian-lxc.md.',
                self::mb(self::uploadGrenze())),
            UPLOAD_ERR_PARTIAL   => 'Der Upload wurde abgebrochen.',
            UPLOAD_ERR_NO_FILE   => 'Es wurde keine Datei ausgewählt.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Der Server konnte die Datei nicht zwischenspeichern.',
            default              => 'Upload fehlgeschlagen (Code ' . $code . ').',
        };
    }
}
