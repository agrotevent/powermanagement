<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Support\Audit;
use App\Support\Database;

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
    public const MAX_UPLOAD_BYTES = 40 * 1024 * 1024;
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
        Database::execute('UPDATE objekte SET site_id = NULL, floor_id = NULL, plan_x = NULL, plan_y = NULL WHERE site_id = ?', [$id]);
        foreach (Database::select('SELECT id FROM floors WHERE site_id = ?', [$id]) as $etage) {
            Database::execute('DELETE FROM leitungen WHERE floor_id = ?', [(int) $etage['id']]);
        }
        Database::execute('DELETE FROM floors WHERE site_id = ?', [$id]);
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
        Database::execute('DELETE FROM leitungen WHERE floor_id = ?', [$id]);
        Database::delete('floors', $id);

        // Plandatei nur löschen, wenn keine andere Etage sie benutzt.
        if ($floor && $floor['plan_file'] !== '') {
            $stillUsed = (int) Database::scalar('SELECT COUNT(*) FROM floors WHERE plan_file = ?', [$floor['plan_file']]);
            if ($stillUsed === 0) {
                @unlink(self::planDir() . '/' . basename((string) $floor['plan_file']));
                PlanTileService::aufraeumen($floor);
            }
        }
        Audit::log('floor.delete', 'floor', (string) $id);
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
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{ok:bool,message:string,pages:int,floors:array<int,int>}
     */
    public static function uploadPlan(int $siteId, array $file, array $options = []): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => self::uploadError((int) ($file['error'] ?? 4)), 'pages' => 0, 'floors' => []];
        }
        if ((int) $file['size'] > self::MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'message' => 'Datei zu groß (maximal ' . (self::MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB).', 'pages' => 0, 'floors' => []];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) {
            return ['ok' => false, 'message' => 'Nur PDF, PNG, JPEG oder SVG sind erlaubt (erkannt: ' . $mime . ').', 'pages' => 0, 'floors' => []];
        }

        $extension = self::ALLOWED[$mime];

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

        $name = sprintf('site%d_%s.%s', $siteId, bin2hex(random_bytes(8)), $extension);
        $target = self::planDir() . '/' . $name;

        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $target)
            : rename($file['tmp_name'], $target);
        if (!$moved) {
            return ['ok' => false, 'message' => 'Datei konnte nicht gespeichert werden.', 'pages' => 0, 'floors' => []];
        }
        chmod($target, 0640);

        $pages = $extension === 'pdf' ? self::countPdfPages($target) : 1;
        $type  = $extension === 'pdf' ? 'pdf' : 'image';

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
        foreach ($floorIds as $floorId) {
            $etage = self::floor((int) $floorId);
            if ($etage === null) {
                continue;
            }
            $namen[] = (string) $etage['name'];

            $ergebnis = PlanTileService::bauen($etage, true);
            if ($ergebnis['ok'] && $ergebnis['kacheln'] > 0) {
                $gebaut++;
                $kachelN += (int) $ergebnis['kacheln'];
            } elseif (!$ergebnis['ok'] && $extension === 'pdf' && $klemmt === '') {
                $klemmt = ' ' . $ergebnis['message'];
            }
        }

        $kacheln = $gebaut > 0
            ? sprintf(' %d Kacheln berechnet%s.', $kachelN, $gebaut > 1 ? ' (' . $gebaut . ' Seiten)' : '')
            : $klemmt;

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
            'pages'  => $pages,
            'floors' => $floorIds,
        ]);

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
     */
    public static function countPdfPages(string $path): int
    {
        $content = (string) file_get_contents($path);

        if (preg_match_all('/\/Type\s*\/Page\b(?!s)/', $content, $matches)) {
            $count = count($matches[0]);
            if ($count > 0) {
                return min($count, self::MAX_PDF_SEITEN);
            }
        }
        if (preg_match_all('/\/Count\s+(\d+)/', $content, $matches)) {
            $counts = array_map('intval', $matches[1]);
            $max = $counts === [] ? 0 : max($counts);
            if ($max > 0) {
                // Die Zahl steht im PDF – „/Count 99999999“ legte sonst so
                // viele Etagen an und kachelte jede.
                return min($max, self::MAX_PDF_SEITEN);
            }
        }
        return 1;
    }

    /** Mehr Seiten werden aus einem PDF nicht auf Etagen verteilt. */
    public const MAX_PDF_SEITEN = 60;

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei überschreitet das Upload-Limit von PHP (upload_max_filesize/post_max_size).',
            UPLOAD_ERR_PARTIAL   => 'Der Upload wurde abgebrochen.',
            UPLOAD_ERR_NO_FILE   => 'Es wurde keine Datei ausgewählt.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Der Server konnte die Datei nicht zwischenspeichern.',
            default              => 'Upload fehlgeschlagen (Code ' . $code . ').',
        };
    }
}
