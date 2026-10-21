<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\ApiZugang;
use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Fingerabdruck;
use App\Support\Logger;
use App\Support\Zeit;
use App\Tanks\TankService;

/**
 * Änderungen anderer Instanzen an Standorten von hier (Seite A).
 *
 * Eine Instanz, die einen Standort über die Schnittstelle übernommen hat,
 * schickt ihn geändert zurück: POST /api/v1/locations/{k}/vorschlaege. Was
 * darin steht, wird vollständig geprüft – alles oder nichts, ein halber
 * Vorschlag ist schwerer zu beurteilen als keiner. Danach:
 *
 *   - als **Vorschlag**: gespeichert, angewandt wird nichts. Hier sieht
 *     jemand je Etage, was sich ändern würde, und nimmt an oder lehnt ab;
 *   - mit „plaene.schreiben“ und einem mitgeschickten Stand („basis“)
 *     **direkt**: nur, wenn sich hier seit dem Abruf nichts geändert hat
 *     (sonst 409) oder die Gegenseite ausdrücklich überschreibt
 *     („erzwingen“). Ohne „basis“ wird auch daraus ein Vorschlag – keine
 *     stillen Überschreibungen ohne Konflikterkennung.
 *
 * Angewandt wird beides über LocationUebertrag, denselben Weg wie beim
 * Übernehmen von einer anderen Instanz: Namen von Standort und Etagen,
 * Schaltsperren und Messpunkte hier bleiben, ein fehlender Maßstab oder eine
 * fehlende Georeferenz löscht nichts, gelöscht wird nichts, neue Etagen legt
 * diese Instanz selbst an. Zugeordnet wird über die Schlüssel dieses
 * Standorts – nie wandert eine Anlage von einem anderen herüber.
 *
 * Plandateien eines Vorschlags liegen bis zur Entscheidung unter
 * storage/vorschlaege/<id>/ mit Zufallsnamen (die Endung bleibt, sonst
 * nähme die Planprüfung sie nicht an). Beim Eingang wird nichts gerendert
 * und nichts gekachelt.
 */
final class VorschlagService
{
    /** Größter Körper, den die Schnittstelle liest (wie ApiController). */
    public const MAX_KOERPER = 48 * 1024 * 1024;

    /** Offene Vorschläge je Token (je Standort ersetzt ein neuer den alten). */
    public const MAX_OFFEN = 10;

    /**
     * Sendungen je Token in der letzten Stunde – alle, die hier eine Zeile
     * hinterlassen (offen, ersetzt, direkt geschrieben). Ersetzte und direkt
     * geschriebene behalten nur ihre Eckdaten, aber auch die sollen nicht
     * unbegrenzt anwachsen.
     */
    public const MAX_JE_STUNDE = 60;

    public const MAX_ETAGEN = 200;
    public const MAX_ANLAGEN = 500;
    public const MAX_LEITUNGEN = 2000;
    public const MAX_PUNKTE = 300;

    /** Entschiedene Vorschläge werden so lange aufbewahrt (bin/power wartung). */
    public const AUFBEWAHREN_TAGE = 400;

    public const OFFEN = 'offen';
    public const ANGENOMMEN = 'angenommen';
    public const ABGELEHNT = 'abgelehnt';
    public const ERSETZT = 'ersetzt';

    /** Zustand → [Text, Badge-Klasse]. */
    public const ZUSTAENDE = [
        self::OFFEN      => ['offen', 'warn'],
        self::ANGENOMMEN => ['angenommen', 'ok'],
        self::ABGELEHNT  => ['abgelehnt', 'bad'],
        self::ERSETZT    => ['ersetzt', ''],
    ];

    /** Was sich annehmen lässt. */
    public const TEILE = [
        'grundriss' => 'Grundriss, Maßstab, Notizen und Georeferenz',
        'feststrom' => 'Feststromanschlüsse und fest verlegte Kabel',
    ];

    /** Name einer mitgeschickten Datei – wie MasterAdmin sie schickt (Schlüssel bis 190 Zeichen), ohne webp. */
    private const DATEI = '#^plaene/[a-z0-9-]{1,190}\.(pdf|png|jpe?g|svg)$#';

    /** Was in plan.datei stehen darf – nur ein Verweis (MasterAdmin nennt auch webp). */
    private const PLAN_DATEI = '#^plaene/[a-z0-9-]{1,190}\.(pdf|png|jpe?g|svg|webp)$#';

    /** Name einer abgelegten Datei: Zufall mit Endung. */
    private const ABLAGE = '/^[0-9a-f]{24}\.(pdf|png|jpg|svg)$/';

    /** Einfache Werte einer Anlage (wie strom.json, ohne Messpunkte). */
    private const ANLAGE_FELDER = ['schluessel', 'art', 'fest', 'name', 'kennung', 'etage', 'x', 'y', 'hersteller', 'modell', 'seriennummer',
        'nennleistung_kva', 'nennstrom_a', 'spannung_v', 'phasen', 'status', 'steuerung_gesperrt', 'notizen'];

    /** Einfache Werte einer Leitung (die Punkte kommen eigens). */
    private const LEITUNG_FELDER = ['schluessel', 'art', 'fest', 'etage', 'name', 'von', 'von_anschluss', 'zu', 'zu_anschluss', 'kabeltyp', 'adern',
        'querschnitt_mm2', 'laenge_m', 'anzahl', 'modell', 'farbe', 'notizen'];

    private const STECKER_FELDER = ['schluessel', 'name', 'strom_a', 'phasen', 'spannung_v', 'farbe'];

    // ---------------------------------------------------------------- Eingang

    /**
     * Änderungen von außen entgegennehmen – als Vorschlag oder direkt.
     *
     * @param array<string,mixed> $token aus ApiZugang::pruefen()
     * @param array<string,mixed> $koerper {location, strom?, dateien?, notiz?, absender?, basis?, erzwingen?}
     * @return array{ok:bool,status:int,message:string,id?:int,uebernommen?:bool,stand?:string,stecker?:list<string>}
     */
    public static function einreichen(array $token, string $schluessel, array $koerper): array
    {
        if (!ApiZugang::darf($token, 'plaene.vorschlagen')) {
            return self::nein(403, 'Dem Token fehlt die Befugnis „plaene.vorschlagen“.');
        }
        $siteId = self::standortFuer($token, $schluessel);
        if ($siteId === null) {
            return self::nein(404, 'Location nicht gefunden.');
        }
        if (!ApiZugang::bearbeitet($token, $siteId)) {
            return self::nein(403, 'Dieser Standort ist für das Token nur zum Lesen freigegeben.');
        }
        $zuletzt = (int) Database::scalar('SELECT COUNT(*) FROM api_vorschlaege WHERE token_id = ? AND created_at >= ?',
            [(int) $token['id'], gmdate('c', time() - 3600)]);
        if ($zuletzt >= self::MAX_JE_STUNDE) {
            return self::nein(429, sprintf('Von diesem Token kamen in der letzten Stunde schon %d Sendungen – bitte später erneut.', $zuletzt));
        }
        try {
            $v = self::pruefen($token, $siteId, $schluessel, $koerper);
        } catch (\UnexpectedValueException $e) {
            return self::nein($e->getCode() >= 400 ? $e->getCode() : 422, $e->getMessage());
        }
        if (ApiZugang::darf($token, 'plaene.schreiben') && $v['basis'] !== null) {
            return self::direkt($token, $siteId, $schluessel, $v);
        }
        return self::ablegen($token, $siteId, $schluessel, $v);
    }

    /** Der Standort mit diesem Schlüssel, den das Token sieht – sonst null (für die Gegenseite: 404). */
    private static function standortFuer(array $token, string $schluessel): ?int
    {
        if (!LocationBundle::schluesselFormat($schluessel)) {
            return null;
        }
        foreach (Database::select('SELECT id FROM sites WHERE sync_key = ? ORDER BY id', [$schluessel]) as $s) {
            if (ApiZugang::sieht($token, (int) $s['id'])) {
                return (int) $s['id'];
            }
        }
        return null;
    }

    /**
     * Den Körper prüfen und auf das zuschneiden, was hier ankommen darf.
     *
     * @return array{location:array<string,mixed>,strom:?array<string,mixed>,dateien:array<string,array<string,mixed>>,paare:array<string,int>,
     *               feststrom:bool,anlagen:int,leitungen:int,notiz:string,absender:string,basis:?string,erzwingen:bool}
     * @throws \UnexpectedValueException Meldung für die Gegenseite, Code = HTTP-Status
     */
    private static function pruefen(array $token, int $siteId, string $schluessel, array $koerper): array
    {
        $loc = $koerper['location'] ?? null;
        if (!is_array($loc) || ($loc['art'] ?? null) !== 'location') {
            self::ab('Erwartet wird eine Location („art“: „location“).');
        }
        if (!is_numeric($loc['format'] ?? 0) || (int) ($loc['format'] ?? 0) > LocationBundle::FORMAT_LOCATION) {
            self::ab('Die Location hat ein neueres Format, als diese Instanz kennt (location.format 1).');
        }
        if (($loc['schluessel'] ?? null) !== $schluessel) {
            self::ab('Der Schlüssel der Location passt nicht zur Adresse.');
        }
        $roh = $loc['etagen'] ?? null;
        if (!is_array($roh) || !array_is_list($roh) || $roh === [] || count($roh) > self::MAX_ETAGEN) {
            self::ab(sprintf('Ein Vorschlag braucht 1 bis %d Etagen.', self::MAX_ETAGEN));
        }
        $etagen = [];
        $paare = [];
        foreach ($roh as $e) {
            if (!is_array($e)) {
                self::ab('Eine Etage im Vorschlag ist kein Objekt.');
            }
            $key = is_string($e['schluessel'] ?? null) ? $e['schluessel'] : '';
            if (!LocationBundle::schluesselFormat($key)) {
                self::ab('Eine Etage im Vorschlag hat keinen gültigen Schlüssel.');
            }
            if (isset($paare[$key])) {
                self::ab(sprintf('Die Etage „%s“ steht zweimal im Vorschlag.', $key));
            }
            // Nur Etagen dieses Standorts – nie über den Schlüssel allein.
            $floorId = (int) Database::scalar('SELECT id FROM floors WHERE site_id = ? AND sync_key = ? ORDER BY id LIMIT 1', [$siteId, $key]);
            if ($floorId <= 0) {
                self::ab(sprintf('Etage „%s“ gibt es hier nicht – neue Etagen legt diese Instanz selbst an.', $key));
            }
            $paare[$key] = $floorId;
            $etagen[] = self::etageAus($e, $key);
        }
        $planDateien = [];
        foreach ($etagen as $e) {
            if ($e['plan'] !== null) {
                $planDateien[(string) $e['plan']['datei']][] = (string) $e['plan']['sha256'];
            }
        }

        // ------------------------------------------------ strom
        $strom = null;
        $mitFeststrom = false;
        $anlagen = $leitungen = [];
        if (($koerper['strom'] ?? null) !== null) {
            $s = $koerper['strom'];
            if (!is_array($s) || ($s['art'] ?? null) !== 'strom') {
                self::ab('„strom“ ist keine Feststrom-Datei („art“: „strom“).');
            }
            if (!is_numeric($s['format'] ?? 0) || (int) ($s['format'] ?? 0) > LocationBundle::FORMAT_STROM) {
                self::ab('„strom“ hat ein neueres Format, als diese Instanz kennt (strom.format 2).');
            }
            if (($s['schluessel'] ?? null) !== $schluessel) {
                self::ab('Der Schlüssel in „strom“ passt nicht zur Adresse.');
            }
            $mitFeststrom = array_key_exists('anlagen', $s) || array_key_exists('leitungen', $s) || array_key_exists('steckertypen', $s);
            if ($mitFeststrom && !ApiZugang::darf($token, 'feststrom')) {
                self::ab('Dem Token fehlt die Befugnis „feststrom“ – Feststromanschlüsse und Kabel nimmt diese Instanz von ihm nicht an.', 403);
            }
            $geo = [];
            if (($s['georeferenz'] ?? null) !== null) {
                if (!is_array($s['georeferenz'])) {
                    self::ab('„georeferenz“ ist keine Zuordnung Etage → Passpunkte.');
                }
                foreach ($s['georeferenz'] as $k => $g) {
                    $k = (string) $k;
                    if (!isset($paare[$k])) {
                        self::ab(sprintf('Georeferenz für „%s“: diese Etage steht nicht im Vorschlag.', mb_substr($k, 0, 64)));
                    }
                    if ($g !== null) {
                        $geo[$k] = self::geoAus($g, $k);
                    }
                }
            }
            $strom = ['format' => LocationBundle::FORMAT_STROM, 'art' => 'strom', 'schluessel' => $schluessel, 'georeferenz' => $geo];
            if ($mitFeststrom) {
                $strom['steckertypen'] = self::steckerAus($s['steckertypen'] ?? []);
                $strom['anlagen'] = $anlagen = self::anlagenAus($s['anlagen'] ?? [], $paare);
                $strom['leitungen'] = $leitungen = self::leitungenAus($s['leitungen'] ?? [], $paare);
            }
        }

        // ------------------------------------------------ Dateien
        $dateien = [];
        $rohDateien = $koerper['dateien'] ?? null;
        if ($rohDateien !== null) {
            if (!is_array($rohDateien)) {
                self::ab('„dateien“ ist keine Zuordnung Pfad → Inhalt (base64).');
            }
            if (count($rohDateien) > count($etagen)) {
                self::ab('Der Vorschlag bringt mehr Dateien mit, als er Etagen hat.');
            }
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $hoechstens = (int) ceil(ApiBestand::MAX_DATEI_BYTES / 3) * 4 + 8;
            foreach ($rohDateien as $pfad => $inhalt) {
                $pfad = (string) $pfad;
                $name = mb_substr($pfad, 0, 80);
                if (preg_match('#\.webp$#i', $pfad) === 1) {
                    self::ab(sprintf('Die Datei „%s“ ist ein webp-Bild – Grundrisse nimmt diese Instanz als PDF, PNG, JPEG oder SVG.', $name));
                }
                if (preg_match(self::DATEI, $pfad, $m) !== 1) {
                    self::ab(sprintf('Die Datei „%s“ ist kein Grundriss der Form plaene/<name>.pdf|png|jpg|svg.', $name));
                }
                if (!isset($planDateien[$pfad])) {
                    self::ab(sprintf('Die Datei „%s“ gehört zu keinem Plan im Vorschlag.', $name));
                }
                if (!is_string($inhalt)) {
                    self::ab(sprintf('Die Datei „%s“ kommt nicht als base64-Text.', $name));
                }
                if (strlen($inhalt) > $hoechstens) {
                    self::ab(sprintf('Die Datei „%s“ ist zu groß (höchstens %d MiB).', $name, intdiv(ApiBestand::MAX_DATEI_BYTES, 1024 * 1024)));
                }
                $daten = base64_decode($inhalt, true);
                if ($daten === false || $daten === '') {
                    self::ab(sprintf('Die Datei „%s“ ist kein gültiges base64.', $name));
                }
                if (strlen($daten) > ApiBestand::MAX_DATEI_BYTES) {
                    self::ab(sprintf('Die Datei „%s“ ist zu groß (höchstens %d MiB).', $name, intdiv(ApiBestand::MAX_DATEI_BYTES, 1024 * 1024)));
                }
                $sha = hash('sha256', $daten);
                foreach ($planDateien[$pfad] as $soll) {
                    if ($soll === '' || !hash_equals($soll, $sha)) {
                        self::ab(sprintf('Die Datei „%s“ passt nicht zur Prüfsumme im Plan (unvollständig oder eine andere Datei).', $name));
                    }
                }
                $endung = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                $mime = (string) $finfo->buffer($daten);
                // Wie beim Hochladen: der Inhalt muss zur Endung passen – PHP mit „.svg“ dahinter ist kein Grundriss.
                if ((SiteService::ALLOWED[$mime] ?? null) !== $endung) {
                    self::ab(sprintf('Die Datei „%s“ ist keine gültige %s-Datei.', $name, strtoupper($endung)));
                }
                // Mehr als SiteService::MAX_PDF_SEITEN Seiten verteilt countPdfPages() ohnehin nicht – eine eigene Grenze braucht es nicht.
                $dateien[$pfad] = ['roh' => $daten, 'sha256' => $sha, 'bytes' => strlen($daten), 'mime' => $mime, 'endung' => $endung];
                unset($daten);
            }
        }

        // ------------------------------------------------ Rest
        $basis = null;
        if (is_string($koerper['basis'] ?? null)) {
            if (preg_match('/^[0-9a-f]{0,64}$/', $koerper['basis']) !== 1) {
                self::ab('„basis“ ist kein Stand dieser Instanz.');
            }
            $basis = $koerper['basis'];
        }
        $absender = self::text($koerper['absender'] ?? '', 190);
        return [
            'location'  => [
                'format'     => LocationBundle::FORMAT_LOCATION,
                'art'        => 'location',
                'schluessel' => $schluessel,
                'name'       => self::text($loc['name'] ?? '', 190),
                'adresse'    => self::text($loc['adresse'] ?? '', 255),
                'notizen'    => self::text($loc['notizen'] ?? '', 5000, true),
                'etagen'     => $etagen,
            ],
            'strom'     => $strom,
            'dateien'   => $dateien,
            'paare'     => $paare,
            'feststrom' => $mitFeststrom,
            'anlagen'   => count($anlagen),
            'leitungen' => count($leitungen),
            'notiz'     => self::text($koerper['notiz'] ?? '', 2000, true),
            'absender'  => $absender !== '' ? $absender : self::text((string) ($token['name'] ?? ''), 190),
            'basis'     => $basis,
            'erzwingen' => ($koerper['erzwingen'] ?? false) === true,
        ];
    }

    /** Eine Etage aus dem Vorschlag – nur, was hier ankommen darf (nie Dosen, Racks, APs). */
    private static function etageAus(array $e, string $key): array
    {
        $etage = [
            'schluessel'  => $key,
            'name'        => self::text($e['name'] ?? '', 190),
            'ebene'       => is_numeric($e['ebene'] ?? null) ? (int) $e['ebene'] : 0,
            'reihenfolge' => is_numeric($e['reihenfolge'] ?? null) ? (int) $e['reihenfolge'] : 0,
        ];
        // Fehlt ein Text (oder ist er null), bleibt der hiesige.
        foreach (['massstab' => 190, 'notizen' => 5000] as $feld => $laenge) {
            if (!array_key_exists($feld, $e) || $e[$feld] === null) {
                continue;
            }
            if (!is_string($e[$feld]) && !is_int($e[$feld]) && !is_float($e[$feld])) {
                self::ab(sprintf('Etage „%s“: „%s“ ist kein Text.', $key, $feld));
            }
            $etage[$feld] = self::text($e[$feld], $laenge, $feld === 'notizen');
        }
        $etage['plan'] = null;
        if (($e['plan'] ?? null) !== null) {
            $p = $e['plan'];
            if (!is_array($p) || !is_string($p['datei'] ?? null) || preg_match(self::PLAN_DATEI, $p['datei']) !== 1) {
                self::ab(sprintf('Etage „%s“: der Plan nennt keine Datei der Form plaene/<name>.pdf|png|jpg|svg.', $key));
            }
            $sha = $p['sha256'] ?? '';
            if (!is_string($sha) || ($sha !== '' && preg_match('/^[0-9a-fA-F]{64}$/', $sha) !== 1)) {
                self::ab(sprintf('Etage „%s“: die Prüfsumme des Plans ist keine sha256.', $key));
            }
            $etage['plan'] = [
                'datei'        => $p['datei'],
                'typ'          => is_string($p['typ'] ?? null) && preg_match('/^[a-z]{1,16}$/', $p['typ']) === 1 ? $p['typ'] : '',
                'seite'        => is_numeric($p['seite'] ?? null) ? min(SiteService::MAX_PDF_SEITEN, max(1, (int) $p['seite'])) : 1,
                'originalname' => self::text($p['originalname'] ?? '', 255),
                'sha256'       => strtolower($sha),
            ];
        }
        // null heißt bei MasterAdmin „kein Maßstab“ – hier wird deshalb nichts gelöscht.
        if (is_array($e['referenz'] ?? null)) {
            $r = $e['referenz'];
            $a = self::punkt($r['a'] ?? null, false);
            $b = self::punkt($r['b'] ?? null, false);
            $meter = is_numeric($r['meter'] ?? null) ? (float) $r['meter'] : 0.0;
            $verhaeltnis = is_numeric($r['verhaeltnis'] ?? null) ? (float) $r['verhaeltnis'] : 0.0;
            if ($a === null || $b === null || $meter <= 0 || $verhaeltnis <= 0) {
                self::ab(sprintf('Etage „%s“: der Maßstab ist unvollständig.', $key));
            }
            $etage['referenz'] = ['a' => $a, 'b' => $b, 'meter' => $meter, 'verhaeltnis' => $verhaeltnis,
                'von' => self::text($r['von'] ?? '', 190), 'am' => self::zeit($r['am'] ?? '')];
        }
        return $etage;
    }

    private static function geoAus(mixed $g, string $key): array
    {
        $a = is_array($g) ? self::punkt($g['a'] ?? null, true) : null;
        $b = is_array($g) ? self::punkt($g['b'] ?? null, true) : null;
        $verhaeltnis = is_array($g) && is_numeric($g['verhaeltnis'] ?? null) ? (float) $g['verhaeltnis'] : 0.0;
        if ($a === null || $b === null || $verhaeltnis <= 0) {
            self::ab(sprintf('Georeferenz für „%s“ ist unvollständig.', $key));
        }
        return ['a' => $a, 'b' => $b, 'verhaeltnis' => $verhaeltnis, 'von' => self::text($g['von'] ?? '', 190), 'am' => self::zeit($g['am'] ?? '')];
    }

    /** Ein Passpunkt {x, y} (mit $welt auch lat/lon) – oder null. */
    private static function punkt(mixed $p, bool $welt): ?array
    {
        if (!is_array($p) || !is_numeric($p['x'] ?? null) || !is_numeric($p['y'] ?? null)) {
            return null;
        }
        $punkt = ['x' => (float) $p['x'], 'y' => (float) $p['y']];
        if ($welt) {
            if (!is_numeric($p['lat'] ?? null) || !is_numeric($p['lon'] ?? null) || abs((float) $p['lat']) > 90 || abs((float) $p['lon']) > 180) {
                return null;
            }
            $punkt += ['lat' => (float) $p['lat'], 'lon' => (float) $p['lon']];
        }
        return $punkt;
    }

    /** @return list<array<string,mixed>> */
    private static function steckerAus(mixed $liste): array
    {
        if (!is_array($liste) || !array_is_list($liste) || count($liste) > 200) {
            self::ab('„steckertypen“ ist keine Liste mit höchstens 200 Einträgen.');
        }
        $out = [];
        foreach ($liste as $t) {
            if (!is_array($t) || !is_string($t['schluessel'] ?? null) || $t['schluessel'] === '' || strlen($t['schluessel']) > 64) {
                self::ab('Ein Steckertyp im Vorschlag hat keinen gültigen Schlüssel.');
            }
            $out[] = self::skalare($t, self::STECKER_FELDER, 'Steckertyp „' . mb_substr($t['schluessel'], 0, 64) . '“');
        }
        return $out;
    }

    /**
     * Feststromanschlüsse aus dem Vorschlag: nur feste, jeder auf einer Etage
     * des Vorschlags, ohne Messpunkte (die brächten Schaltrechte mit).
     *
     * @param array<string,int> $paare
     * @return list<array<string,mixed>>
     */
    private static function anlagenAus(mixed $liste, array $paare): array
    {
        if (!is_array($liste) || !array_is_list($liste) || count($liste) > self::MAX_ANLAGEN) {
            self::ab(sprintf('„anlagen“ ist keine Liste mit höchstens %d Einträgen.', self::MAX_ANLAGEN));
        }
        $out = [];
        $schon = [];
        foreach ($liste as $a) {
            if (!is_array($a)) {
                self::ab('Eine Anlage im Vorschlag ist kein Objekt.');
            }
            $key = is_string($a['schluessel'] ?? null) ? $a['schluessel'] : '';
            if (!LocationBundle::schluesselFormat($key)) {
                self::ab('Eine Anlage im Vorschlag hat keinen gültigen Schlüssel (a–z, 0–9, Bindestrich, höchstens 190 Zeichen).');
            }
            if (isset($schon[$key])) {
                self::ab(sprintf('Die Anlage „%s“ steht zweimal im Vorschlag.', $key));
            }
            $schon[$key] = true;
            if (!is_string($a['art'] ?? null) || !isset(AnlagenService::ARTEN[$a['art']])) {
                self::ab(sprintf('Anlage „%s“: unbekannte Art.', $key));
            }
            if (($a['fest'] ?? null) !== true) {
                self::ab(sprintf('Anlage „%s“ ist kein Feststromanschluss („fest“: true) – mobile Anlagen nimmt die Schnittstelle nicht an.', $key));
            }
            $etage = $a['etage'] ?? null;
            if (!is_string($etage)) {
                self::ab(sprintf('Anlage „%s“ steht auf keiner Etage – das nimmt diese Instanz nicht an.', $key));
            }
            if (!isset($paare[$etage])) {
                self::ab(sprintf('Anlage „%s“ steht auf der Etage „%s“, die nicht im Vorschlag steht.', $key, mb_substr($etage, 0, 64)));
            }
            $was = 'Anlage „' . $key . '“';
            // Ein fehlender Name hieße hier „wie der Schlüssel“ – lieber ablehnen.
            if (!is_string($a['name'] ?? null) || trim($a['name']) === '') {
                self::ab(sprintf('%s: „name“ fehlt.', $was));
            }
            $anlage = self::skalare($a, self::ANLAGE_FELDER, $was);
            if (($a['eigenschaften'] ?? null) !== null) {
                $anlage['eigenschaften'] = self::skalarMap($a['eigenschaften'], 100, $was . ': „eigenschaften“');
            }
            // Anschlüsse und Schutzorgane werden ersetzt – ein fehlender
            // Schlüssel hieße sonst „keine“ und löschte sie hier samt den
            // Verweisen von Kabeln, Messpunkten und Einsätzen.
            foreach (['schutzorgane' => 100, 'anschluesse' => 400] as $feld => $max) {
                if (!array_key_exists($feld, $a) || $a[$feld] === null) {
                    self::ab(sprintf('%s: „%s“ fehlt – eine leere Liste heißt „keine“.', $was, $feld));
                }
                if (!is_array($a[$feld]) || !array_is_list($a[$feld]) || count($a[$feld]) > $max) {
                    self::ab(sprintf('%s: „%s“ ist keine Liste mit höchstens %d Einträgen.', $was, $feld, $max));
                }
                $anlage[$feld] = array_map(static fn (mixed $z): array => self::skalarMap($z, 50, $was . ': „' . $feld . '“'), $a[$feld]);
            }
            $out[] = $anlage;
        }
        return $out;
    }

    /**
     * Fest verlegte Kabel und Kabelwege – keine Kabelbrücken, nichts Mobiles.
     *
     * @param array<string,int> $paare
     * @return list<array<string,mixed>>
     */
    private static function leitungenAus(mixed $liste, array $paare): array
    {
        if (!is_array($liste) || !array_is_list($liste) || count($liste) > self::MAX_LEITUNGEN) {
            self::ab(sprintf('„leitungen“ ist keine Liste mit höchstens %d Einträgen.', self::MAX_LEITUNGEN));
        }
        $out = [];
        $schon = [];
        foreach ($liste as $l) {
            if (!is_array($l)) {
                self::ab('Eine Leitung im Vorschlag ist kein Objekt.');
            }
            $key = is_string($l['schluessel'] ?? null) ? $l['schluessel'] : '';
            if (!LocationBundle::schluesselFormat($key)) {
                self::ab('Eine Leitung im Vorschlag hat keinen gültigen Schlüssel (a–z, 0–9, Bindestrich, höchstens 190 Zeichen).');
            }
            if (isset($schon[$key])) {
                self::ab(sprintf('Die Leitung „%s“ steht zweimal im Vorschlag.', $key));
            }
            $schon[$key] = true;
            if (!in_array($l['art'] ?? null, ['kabel', 'kabelweg'], true)) {
                self::ab(sprintf('Leitung „%s“: nur Kabel und Kabelwege – keine Kabelbrücken, keine Kraftstoffschläuche.', $key));
            }
            if (($l['fest'] ?? null) !== true) {
                self::ab(sprintf('Leitung „%s“ ist nicht fest verlegt („fest“: true) – mobile Kabel nimmt die Schnittstelle nicht an.', $key));
            }
            $etage = $l['etage'] ?? null;
            if (!is_string($etage) || !isset($paare[$etage])) {
                self::ab(sprintf('Leitung „%s“ liegt auf keiner Etage des Vorschlags.', $key));
            }
            $leitung = self::skalare($l, self::LEITUNG_FELDER, 'Leitung „' . $key . '“');
            // Wie bei Anlagen: fehlt der Verlauf, hieße das „keiner“.
            if (!array_key_exists('punkte', $l) || $l['punkte'] === null) {
                self::ab(sprintf('Leitung „%s“: „punkte“ fehlt – eine leere Liste heißt „keine“.', $key));
            }
            $punkte = $l['punkte'];
            if (!is_array($punkte) || !array_is_list($punkte) || count($punkte) > self::MAX_PUNKTE) {
                self::ab(sprintf('Leitung „%s“: „punkte“ ist keine Liste mit höchstens %d Punkten.', $key, self::MAX_PUNKTE));
            }
            $leitung['punkte'] = [];
            foreach ($punkte as $p) {
                if (!is_array($p) || !array_is_list($p) || count($p) !== 2 || !is_numeric($p[0]) || !is_numeric($p[1])) {
                    self::ab(sprintf('Leitung „%s“: ein Punkt ist kein Paar [x, y].', $key));
                }
                $leitung['punkte'][] = [(float) $p[0], (float) $p[1]];
            }
            $out[] = $leitung;
        }
        return $out;
    }

    /**
     * Nur diese Felder, nur einfache Werte (Text, Zahl, ja/nein, null).
     *
     * @param list<string> $felder
     * @return array<string,mixed>
     */
    private static function skalare(array $eintrag, array $felder, string $was): array
    {
        $out = [];
        foreach ($felder as $feld) {
            if (!array_key_exists($feld, $eintrag)) {
                continue;
            }
            $wert = $eintrag[$feld];
            if ($wert !== null && !is_scalar($wert)) {
                self::ab(sprintf('%s: „%s“ ist kein einfacher Wert.', $was, $feld));
            }
            $out[$feld] = is_string($wert) ? mb_substr($wert, 0, 5000) : $wert;
        }
        return $out;
    }

    /** Eine Zuordnung mit einfachen Werten (Schutzorgan, Anschluss, Eigenschaften). */
    private static function skalarMap(mixed $wert, int $max, string $was): array
    {
        if (!is_array($wert) || count($wert) > $max) {
            self::ab(sprintf('%s: kein Objekt mit höchstens %d Werten.', $was, $max));
        }
        $out = [];
        foreach ($wert as $k => $w) {
            if ($w !== null && !is_scalar($w)) {
                self::ab(sprintf('%s: „%s“ ist kein einfacher Wert.', $was, mb_substr((string) $k, 0, 40)));
            }
            $out[mb_substr((string) $k, 0, 64)] = is_string($w) ? mb_substr($w, 0, 5000) : $w;
        }
        return $out;
    }

    // ---------------------------------------------------------------- direkt schreiben

    /**
     * „plaene.schreiben“ mit Stand: gleich anwenden – aber nur auf dem Stand,
     * den die Gegenseite kennt, oder ausdrücklich darüber hinweg.
     *
     * Erst die Schreibsperre, dann der Vergleich mit „basis“: sonst schriebe
     * ein zweiter Schreiber im Fenster zwischen Prüfen und Anwenden – und der
     * erste überschriebe dessen Änderungen ohne 409. Prüfen, Anwenden, die
     * Zeile in api_vorschlaege und der neue Stand für die Antwort laufen in
     * einer Transaktion; Kacheln entstehen erst danach.
     *
     * Die Rechte kommen aus den Befugnissen und der Standort-Freigabe des
     * Tokens (geprüft in einreichen()), nicht aus einem Benutzer hier. Neue
     * Steckertypen legt ein Token nie an.
     */
    private static function direkt(array $token, int $siteId, string $schluessel, array $v): array
    {
        $teile = $v['feststrom'] ? ['grundriss', 'feststrom'] : ['grundriss'];
        $ordner = self::wurzel() . '/direkt-' . bin2hex(random_bytes(6)) . '.neu';
        $pdo = Database::pdo();
        $ersetzt = [];
        try {
            // Die Dateien vor der Sperre – sie liegen in einem eigenen Ordner.
            $abgelegt = self::dateienSchreiben($ordner, $v['dateien']);
            $aufloeser = self::aufloeser($siteId, $v['location'], $ordner, $abgelegt, array_keys($v['paare']));
            $pdo->beginTransaction();
            try {
                self::sperren($siteId);
                $aktuell = (string) (ApiBestand::location($token, $schluessel)['stand'] ?? '');
                $basisPasst = hash_equals($aktuell, (string) $v['basis']);
                if (!$v['erzwingen'] && !$basisPasst) {
                    $pdo->rollBack();
                    return self::nein(409, 'Hier hat sich seit dem Abruf etwas geändert – erst übernehmen oder ausdrücklich überschreiben.', ['stand' => $aktuell]);
                }
                if ($v['feststrom']) {
                    $fehlen = LocationUebertrag::fehlendeSteckertypen((array) $v['strom']);
                    if ($fehlen !== []) {
                        $pdo->rollBack();
                        return self::nein(422, 'Diese Steckertypen gibt es hier nicht: ' . implode(', ', $fehlen)
                            . ' – ein Token legt keine an. Erst hier im Katalog anlegen, dann erneut schicken. Nichts übernommen.', ['stecker' => $fehlen]);
                    }
                }
                $r = LocationUebertrag::anwenden($siteId, $v['location'], $v['strom'], $v['paare'], $aufloeser, [
                    'teile'           => $teile,
                    'zuordnung'       => 'standort',
                    'plan_praefix'    => 'vorschlag',
                    'fremdbestand'    => false,
                    'neue_etagen'     => false,
                    'stecker_anlegen' => false,
                    'kacheln'         => false,
                    'token_id'        => (int) $token['id'],
                ]);
                if (!$r['ok']) {
                    $pdo->rollBack();
                    return self::nein(422, $r['message']);
                }
                $name = 'api:' . mb_substr((string) $token['name'], 0, 60);
                $etagen = array_keys($v['paare']);
                // Angewandt ist angewandt – die Zeile behält nur ihre Eckdaten,
                // keinen zweiten Bestand (und keine Dateien).
                $kurz = self::kurzfassung($schluessel, $etagen, ['anlagen' => (int) $r['anlagen'], 'leitungen' => (int) $r['leitungen']]);
                $id = Database::insert('api_vorschlaege', array_merge(self::zeile($token, $siteId, $schluessel, $v, [], $kurz), [
                    'bytes'           => array_sum(array_map(static fn (array $d): int => (int) $d['bytes'], $v['dateien'])),
                    'status'          => self::ANGENOMMEN,
                    'entschieden_von' => mb_substr($name, 0, 64),
                    'entschieden_at'  => gmdate('c'),
                    'entscheidung'    => self::json(['direkt' => true, 'etagen' => $etagen, 'teile' => $teile]),
                ]));
                $ersetzt = self::aeltereErsetzen($token, $siteId, $id);
                // Der neue Stand genau zu dem, was gerade geschrieben wurde.
                $neuerStand = (string) (ApiBestand::location($token, $schluessel)['stand'] ?? '');
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            self::wegraeumen($ordner);
        }
        foreach ($ersetzt as $alt) {
            self::wegraeumen(self::ordner($alt));
        }
        LocationBundle::kachelnBauen($r['kacheln']);
        Audit::log('api.schreiben', 'site', (string) $siteId, [
            'location'  => $schluessel,
            'etagen'    => $etagen,
            'teile'     => $teile,
            'anlagen'   => (int) $r['anlagen'],
            'leitungen' => (int) $r['leitungen'],
            'token'     => (int) $token['id'],
            'vorschlag' => $id,
        ] + ($ersetzt !== [] ? ['ersetzt' => $ersetzt] : []) + ($basisPasst ? [] : ['erzwungen' => true]));
        $objekt = static fn (array $m): array|\stdClass => $m === [] ? new \stdClass() : $m;
        return [
            'ok'          => true,
            'status'      => 201,
            'id'          => $id,
            'uebernommen' => true,
            'stand'       => $neuerStand,
            // false: mit „erzwingen“ über einen anderen Stand geschrieben – hier
            // gibt es dann mehr, als die Gegenseite kennt.
            'basis_passte' => $basisPasst,
            // Unter welchem Schlüssel hier steht, was die Gegenseite geschickt
            // hat – ein neuer Eintrag bekommt hier oft einen anderen.
            'schluessel'  => ['anlagen' => $objekt($r['schluessel']['anlagen']), 'leitungen' => $objekt($r['schluessel']['leitungen'])],
            'message'     => 'Übernommen.',
        ];
    }

    /**
     * Die Schreibsperre für einen Standort holen – als erste Anweisung der
     * Transaktion. Unter SQLite sperrt jedes Schreiben die ganze Datenbank,
     * unter MySQL die Zeile des Standorts; ein zweiter Schreiber wartet, bis
     * der erste fertig ist, und vergleicht erst dann.
     */
    private static function sperren(int $siteId): void
    {
        Database::execute('UPDATE sites SET id = id WHERE id = ?', [$siteId]);
    }

    /** Was von einer entschiedenen Zeile bleibt: Schlüssel, Etagen, Zahlen – kein Bestand. */
    private static function kurzfassung(string $schluessel, array $etagen = [], array $zahlen = []): string
    {
        return self::json(['location' => ['schluessel' => $schluessel, 'etagen' => array_map(static fn ($k): array => ['schluessel' => (string) $k], array_values($etagen))],
            'gekuerzt' => true] + $zahlen);
    }

    // ---------------------------------------------------------------- als Vorschlag ablegen

    private static function ablegen(array $token, int $siteId, string $schluessel, array $v): array
    {
        // Je Standort gilt der neueste Vorschlag eines Tokens – gezählt wird ohne den, den dieser ersetzt.
        $offen = (int) Database::scalar(
            "SELECT COUNT(*) FROM api_vorschlaege WHERE token_id = ? AND status = 'offen' AND (site_id IS NULL OR site_id <> ?)",
            [(int) $token['id'], $siteId]
        );
        if ($offen >= self::MAX_OFFEN) {
            return self::nein(429, sprintf('Von diesem Token liegen hier schon %d offene Vorschläge – erst wenn welche entschieden sind, nimmt diese Instanz weitere an.', $offen));
        }
        $meta = [];
        foreach ($v['dateien'] as $pfad => $d) {
            $meta[$pfad] = ['datei' => bin2hex(random_bytes(12)) . '.' . $d['endung'], 'sha256' => $d['sha256'], 'bytes' => $d['bytes'], 'mime' => $d['mime']];
        }
        $id = Database::insert('api_vorschlaege', self::zeile($token, $siteId, $schluessel, $v, $meta) + ['status' => self::OFFEN]);
        if ($meta !== []) {
            $neu = self::ordner($id) . '.neu';
            try {
                self::dateienSchreiben($neu, $v['dateien'], $meta);
                if (!@rename($neu, self::ordner($id))) {
                    throw new \RuntimeException('Ordner ' . basename($neu) . ' ließ sich nicht umbenennen.');
                }
            } catch (\Throwable $e) {
                self::wegraeumen($neu);
                self::wegraeumen(self::ordner($id));
                Database::execute('DELETE FROM api_vorschlaege WHERE id = ?', [$id]);
                Logger::error('Vorschlag ablegen: ' . $e->getMessage());
                return self::nein(500, 'Die Dateien des Vorschlags ließen sich hier nicht ablegen – nichts gespeichert. Details stehen im Log.');
            }
        }
        $ersetzt = self::aeltereErsetzen($token, $siteId, $id);
        foreach ($ersetzt as $alt) {
            self::wegraeumen(self::ordner($alt));
        }
        Audit::log('api.vorschlag.eingang', 'api_vorschlag', (string) $id, [
            'location'  => $schluessel,
            'etagen'    => count($v['paare']),
            'anlagen'   => $v['anlagen'],
            'leitungen' => $v['leitungen'],
            'dateien'   => count($meta),
            'token'     => (int) $token['id'],
        ] + ($ersetzt !== [] ? ['ersetzt' => $ersetzt] : []));
        return [
            'ok'          => true,
            'status'      => 201,
            'id'          => $id,
            'uebernommen' => false,
            'message'     => 'Vorschlag eingegangen – übernommen wird er erst nach Bestätigung hier.',
        ];
    }

    /**
     * Die Spalten einer neuen Zeile (ohne Zustand und Entscheidung).
     *
     * @param string|null $daten schon fertig (Kurzfassung) – sonst der ganze Vorschlag
     */
    private static function zeile(array $token, int $siteId, string $schluessel, array $v, array $meta, ?string $daten = null): array
    {
        $daten ??= self::json(['location' => $v['location']] + ($v['strom'] !== null ? ['strom' => $v['strom']] : []));
        return [
            'token_id'    => (int) $token['id'],
            'token_name'  => mb_substr((string) $token['name'], 0, 190),
            'absender'    => $v['absender'],
            'site_id'     => $siteId,
            'site_key'    => $schluessel,
            'basis_stand' => (string) ($v['basis'] ?? ''),
            'daten'       => $daten,
            'dateien'     => self::json($meta === [] ? new \stdClass() : $meta),
            'bytes'       => array_sum(array_map(static fn (array $m): int => (int) $m['bytes'], $meta)),
            'notiz'       => $v['notiz'],
            'created_at'  => gmdate('c'),
        ];
    }

    /**
     * Ältere offene Vorschläge desselben Tokens für denselben Standort sind
     * überholt – der neue bringt den ganzen Stand. Sie behalten nur ihre
     * Eckdaten: wer in Folge schickt, füllt sonst mit jeder Sendung die
     * Platte, bis die Wartung nach AUFBEWAHREN_TAGE aufräumt.
     *
     * Nur, was in diesem Moment noch offen ist (gleichzeitig angenommen
     * oder abgelehnt bleibt es). Die Ordner räumt der Aufrufer danach.
     *
     * @return list<int>
     */
    private static function aeltereErsetzen(array $token, int $siteId, int $neuId): array
    {
        $ids = [];
        foreach (Database::select("SELECT id, site_key FROM api_vorschlaege WHERE token_id = ? AND site_id = ? AND status = 'offen' AND id <> ?",
            [(int) $token['id'], $siteId, $neuId]) as $z) {
            $id = (int) $z['id'];
            $geaendert = Database::execute("UPDATE api_vorschlaege SET status = ?, entschieden_von = ?, entschieden_at = ?, entscheidung = ?,
                daten = ?, dateien = ? WHERE id = ? AND status = 'offen'", [
                self::ERSETZT,
                mb_substr('api:' . mb_substr((string) $token['name'], 0, 60), 0, 64),
                gmdate('c'),
                self::json(['ersetzt_durch' => $neuId]),
                self::kurzfassung((string) $z['site_key']),
                '{}',
                $id,
            ]);
            if ($geaendert === 1) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    // ---------------------------------------------------------------- lesen

    /**
     * Die Vorschläge, die der Benutzer sehen darf – offene zuerst. Sie zeigen
     * ganze Etagen, deshalb nur mit dem ganzen Standort (siehtGanzenStandort);
     * ist der Standort gelöscht, nur mit „alles“.
     *
     * @return list<array<string,mixed>>
     */
    public static function alle(int $hoechstens = 300): array
    {
        $out = [];
        foreach (Database::select(
            'SELECT v.id, v.token_id, v.token_name, v.absender, v.site_id, v.site_key, v.notiz, v.status, v.created_at, v.bytes,
                    v.entschieden_von, v.entschieden_at, v.entscheidung, s.name AS site_name
             FROM api_vorschlaege v LEFT JOIN sites s ON s.id = v.site_id
             ORDER BY CASE WHEN v.status = \'offen\' THEN 0 ELSE 1 END, v.created_at DESC, v.id DESC LIMIT ' . max(1, $hoechstens)
        ) as $v) {
            if (!self::sichtbar($v)) {
                continue;
            }
            $v['entscheidung'] = self::jsonArray($v['entscheidung'] ?? null);
            $out[] = $v;
        }
        return $out;
    }

    /** @return array<string,mixed>|null mit daten, dateien, entscheidung als Arrays */
    public static function find(int $id): ?array
    {
        $v = Database::first('SELECT v.*, s.name AS site_name FROM api_vorschlaege v LEFT JOIN sites s ON s.id = v.site_id WHERE v.id = ?', [$id]);
        if ($v === null) {
            return null;
        }
        foreach (['daten', 'dateien', 'entscheidung'] as $feld) {
            $v[$feld] = self::jsonArray($v[$feld] ?? null);
        }
        return $v;
    }

    /** Darf der Benutzer diesen Vorschlag sehen? */
    public static function sichtbar(array $v): bool
    {
        if ($v['site_id'] === null || ($v['site_name'] ?? null) === null) {
            return Zugriff::alles();
        }
        return Zugriff::siehtGanzenStandort((int) $v['site_id']);
    }

    /**
     * Eine mitgeschickte Datei – nur über ihren Namen im Vorschlag (Liste der
     * erlaubten Pfade), nie über einen Pfad auf der Platte.
     */
    public static function datei(array $vorschlag, string $pfad): ?string
    {
        $meta = $vorschlag['dateien'][$pfad] ?? null;
        if (!is_array($meta) || !is_string($meta['datei'] ?? null) || preg_match(self::ABLAGE, $meta['datei']) !== 1) {
            return null;
        }
        $ordner = realpath(self::ordner((int) $vorschlag['id']));
        $echt = $ordner !== false ? realpath($ordner . '/' . $meta['datei']) : false;
        return $echt !== false && str_starts_with($echt, $ordner . '/') && is_file($echt) ? $echt : null;
    }

    public static function ordner(int $id): string
    {
        return self::wurzel() . '/' . $id;
    }

    private static function wurzel(): string
    {
        $wurzel = dirname(__DIR__, 2) . '/storage/vorschlaege';
        if (!is_dir($wurzel) && !@mkdir($wurzel, 0700, true) && !is_dir($wurzel)) {
            throw new \RuntimeException('storage/vorschlaege ließ sich nicht anlegen.');
        }
        return $wurzel;
    }

    // ---------------------------------------------------------------- Unterschiede

    /**
     * Was der Vorschlag je Etage ändern würde – verglichen mit dem Stand hier.
     *
     * Verglichen wird nur, was er mitbringt: fehlt ihm ein Maßstab, eine
     * Georeferenz oder Feststrom, ist das kein Unterschied (es bleibt hier,
     * wie es ist). Namen, Ebene und Reihenfolge sind nur zur Information –
     * sie bleiben hier.
     *
     * @return array{etagen:list<array<string,mixed>>,stecker_neu:list<string>,hinweise:list<string>,feststrom:bool,standort:bool}
     */
    public static function unterschiede(array $vorschlag): array
    {
        $siteId = $vorschlag['site_id'] !== null ? (int) $vorschlag['site_id'] : 0;
        $site = $siteId > 0 ? SiteService::find($siteId) : null;
        if ($site === null) {
            return ['etagen' => [], 'stecker_neu' => [], 'feststrom' => false, 'standort' => false,
                'hinweise' => ['Den Standort gibt es hier nicht mehr – der Vorschlag lässt sich nur noch ablehnen.']];
        }
        $daten = (array) ($vorschlag['daten'] ?? []);
        $loc = (array) ($daten['location'] ?? []);
        $strom = is_array($daten['strom'] ?? null) ? $daten['strom'] : null;
        $mitFeststrom = $strom !== null && (array_key_exists('anlagen', $strom) || array_key_exists('leitungen', $strom));

        $hinweise = self::hinweise($vorschlag);
        $hierAnlagen = $hierLeitungen = $dortAnlagen = $dortLeitungen = [];
        if ($mitFeststrom) {
            $hierStrom = LocationBundle::stromLesen($siteId);
            foreach ((array) $hierStrom['anlagen'] as $a) {
                $hierAnlagen[(string) $a['schluessel']] = $a;
            }
            foreach ((array) $hierStrom['leitungen'] as $l) {
                $hierLeitungen[(string) $l['schluessel']] = $l;
            }
            // Was dieses Token schon einmal neu angelegt hat, steht hier unter
            // einem eigenen Schlüssel – verglichen wird mit dem (wie beim Annehmen).
            $hierKey = self::zuordnungHier((int) $vorschlag['token_id'], $siteId);
            foreach ((array) ($strom['anlagen'] ?? []) as $a) {
                $dortAnlagen[$hierKey['objekte'][(string) $a['schluessel']] ?? (string) $a['schluessel']] = $a;
            }
            foreach ((array) ($strom['leitungen'] ?? []) as $l) {
                $dortLeitungen[$hierKey['leitungen'][(string) $l['schluessel']] ?? (string) $l['schluessel']] = $l;
            }
        }

        $etagen = [];
        foreach ((array) ($loc['etagen'] ?? []) as $e) {
            $key = (string) ($e['schluessel'] ?? '');
            $f = Database::first('SELECT * FROM floors WHERE site_id = ? AND sync_key = ? ORDER BY id LIMIT 1', [$siteId, $key]);
            $z = [
                'schluessel'    => $key,
                'gibt_es'       => $f !== null,
                'floor_id'      => (int) ($f['id'] ?? 0),
                'name'          => $f !== null ? (string) $f['name'] : $key,
                'info'          => [],
                'plan'          => 'ohne',
                'plan_datei'    => null,
                'massstab'      => false,
                'massstab_text' => false,
                'notizen'       => false,
                'georeferenz'   => 'ohne',
                'anlagen'       => null,
                'leitungen'     => null,
                'geaendert'     => false,
            ];
            if ($f === null) {
                $z['info'][] = 'Diese Etage gibt es hier nicht mehr.';
                $etagen[] = $z;
                continue;
            }
            $nameDort = (string) ($e['name'] ?? '');
            if ($nameDort !== '' && $nameDort !== (string) $f['name']) {
                $z['info'][] = sprintf('heißt dort „%s“ – der Name hier bleibt', $nameDort);
            }
            if ((int) ($e['ebene'] ?? 0) !== (int) $f['level_no'] || (int) ($e['reihenfolge'] ?? 0) !== (int) $f['sort_order']) {
                $z['info'][] = sprintf('dort Ebene %d, Platz %d – hier bleibt Ebene %d, Platz %d',
                    (int) ($e['ebene'] ?? 0), (int) ($e['reihenfolge'] ?? 0), (int) $f['level_no'], (int) $f['sort_order']);
            }
            if (is_array($e['plan'] ?? null)) {
                $summe = SiteService::planPruefsumme($f);
                $sha = (string) ($e['plan']['sha256'] ?? '');
                $datei = (string) ($e['plan']['datei'] ?? '');
                if ($summe !== null && $sha !== '' && hash_equals($summe['sha256'], $sha)) {
                    $z['plan'] = max(1, (int) ($f['plan_page'] ?? 1)) !== (int) ($e['plan']['seite'] ?? 1) ? 'seite' : 'gleich';
                } elseif (self::datei($vorschlag, $datei) !== null) {
                    $z['plan'] = 'neu';
                    $z['plan_datei'] = $datei;
                } elseif ($sha !== '') {
                    $z['plan'] = 'fehlt';
                }
            }
            if (is_array($e['referenz'] ?? null)) {
                $hier = PlanMassstabService::lesen($f);
                $z['massstab'] = $hier === null || self::ohneWerWann($hier) !== self::ohneWerWann($e['referenz']);
            }
            $z['massstab_text'] = array_key_exists('massstab', $e) && (string) $e['massstab'] !== (string) ($f['scale_note'] ?? '');
            $z['notizen'] = array_key_exists('notizen', $e) && (string) $e['notizen'] !== (string) ($f['notes'] ?? '');
            $geo = $strom['georeferenz'][$key] ?? null;
            if (is_array($geo)) {
                $hierGeo = PlanGeoService::alsDaten(PlanGeoService::lesen($f));
                $z['georeferenz'] = $hierGeo === null || self::ohneWerWann($hierGeo) !== self::ohneWerWann($geo) ? 'geaendert' : 'gleich';
            }
            if ($mitFeststrom) {
                $z['anlagen'] = self::listenVergleich($dortAnlagen, $hierAnlagen, $key, [self::class, 'anlageVergleich']);
                $z['leitungen'] = self::listenVergleich($dortLeitungen, $hierLeitungen, $key, [self::class, 'leitungVergleich']);
            }
            $feststromNeu = $mitFeststrom && ($z['anlagen']['zahl'] + $z['leitungen']['zahl']) > 0;
            $z['geaendert'] = in_array($z['plan'], ['neu', 'seite'], true) || $z['massstab'] || $z['massstab_text'] || $z['notizen']
                || $z['georeferenz'] === 'geaendert' || $feststromNeu;
            $etagen[] = $z;
        }
        return [
            'etagen'      => $etagen,
            'stecker_neu' => $mitFeststrom ? LocationUebertrag::fehlendeSteckertypen($strom) : [],
            'hinweise'    => $hinweise,
            'feststrom'   => $mitFeststrom,
            'standort'    => true,
        ];
    }

    /**
     * Schlüssel, unter denen ein Token Einträge geschickt hat, die hier neu
     * angelegt wurden → ihr Schlüssel hier (nur auf diesem Standort).
     *
     * @return array{objekte:array<string,string>,leitungen:array<string,string>}
     */
    private static function zuordnungHier(int $tokenId, int $siteId): array
    {
        $out = ['objekte' => [], 'leitungen' => []];
        foreach (Database::select("SELECT z.fremd_schluessel, o.sync_key FROM api_zuordnungen z JOIN objekte o ON o.id = z.ziel_id
            WHERE z.token_id = ? AND z.tabelle = 'objekte' AND o.site_id = ?", [$tokenId, $siteId]) as $z) {
            $out['objekte'][(string) $z['fremd_schluessel']] = (string) $z['sync_key'];
        }
        foreach (Database::select("SELECT z.fremd_schluessel, l.sync_key FROM api_zuordnungen z JOIN leitungen l ON l.id = z.ziel_id
            JOIN floors f ON f.id = l.floor_id WHERE z.token_id = ? AND z.tabelle = 'leitungen' AND f.site_id = ?", [$tokenId, $siteId]) as $z) {
            $out['leitungen'][(string) $z['fremd_schluessel']] = (string) $z['sync_key'];
        }
        return $out;
    }

    /** Warnungen zum ganzen Vorschlag: Stand veraltet, Zugang widerrufen oder abgelaufen. */
    private static function hinweise(array $v): array
    {
        $hinweise = [];
        $zeile = Database::first('SELECT * FROM api_tokens WHERE id = ?', [(int) $v['token_id']]);
        if ($zeile === null) {
            return ['Den Zugang, über den der Vorschlag kam, gibt es hier nicht mehr – annehmen geht trotzdem, hier entscheidet ein Mensch.'];
        }
        if ((string) ($zeile['revoked_at'] ?? '') !== '') {
            $hinweise[] = sprintf('Der Zugang „%s“ ist inzwischen widerrufen – annehmen geht trotzdem, hier entscheidet ein Mensch.', (string) $zeile['name']);
        } elseif (($bis = Zeit::ts($zeile['expires_at'] ?? null)) !== null && $bis < time()) {
            $hinweise[] = sprintf('Der Zugang „%s“ ist inzwischen abgelaufen – annehmen geht trotzdem, hier entscheidet ein Mensch.', (string) $zeile['name']);
        }
        if ((string) $v['basis_stand'] === '') {
            $hinweise[] = 'Der Absender hat keinen Stand mitgeschickt – ob sich hier seit seinem Abruf etwas geändert hat, ist nicht bekannt.';
        } else {
            unset($zeile['token_hash'], $zeile['token_enc']);
            $zeile['befugnisse'] = ApiZugang::befugnisseAus($zeile);
            $jetzt = ApiBestand::location($zeile, (string) $v['site_key']);
            if ($jetzt === null || !hash_equals((string) $jetzt['stand'], (string) $v['basis_stand'])) {
                $hinweise[] = 'Hier hat sich seit dem Abruf etwas geändert – der Vorschlag überschreibt das.';
            }
        }
        return $hinweise;
    }

    /**
     * Feststrom einer Etage: neu (+), geändert (~), fehlt im Vorschlag (−, bleibt hier).
     *
     * @param array<string,array<string,mixed>> $dort
     * @param array<string,array<string,mixed>> $hier ganzer Standort, nach Schlüssel
     * @return array{neu:list<string>,geaendert:list<string>,fehlt:list<string>,zahl:int}
     */
    private static function listenVergleich(array $dort, array $hier, string $etage, callable $form): array
    {
        $out = ['neu' => [], 'geaendert' => [], 'fehlt' => [], 'zahl' => 0];
        $name = static fn (array $x, string $k): string => trim((string) ($x['name'] ?? '')) !== '' ? (string) $x['name'] : $k;
        foreach ($dort as $k => $d) {
            if ((string) ($d['etage'] ?? '') !== $etage) {
                continue;
            }
            $h = $hier[$k] ?? null;
            if ($h === null) {
                $out['neu'][] = $name($d, (string) $k);
            } elseif ($form($d, $h) !== $form($h, $h)) {
                $out['geaendert'][] = $name($d, (string) $k);
            }
        }
        foreach ($hier as $k => $h) {
            if ((string) ($h['etage'] ?? '') === $etage && !isset($dort[$k])) {
                $out['fehlt'][] = $name($h, (string) $k);
            }
        }
        $out['zahl'] = count($out['neu']) + count($out['geaendert']);
        return $out;
    }

    /**
     * Eine Anlage so, wie sie nach dem Annehmen hier stünde – zum Vergleichen:
     * ohne Messpunkte, die Schaltsperre bleibt (dort ∨ hier), Lage im Plan,
     * Anschlüsse nach Richtung und Nummer. An einem Tank zählt der Inhalt je
     * mm nicht: den behält ein vorhandener hier (TankService::KENNLINIE_HIER).
     */
    public static function anlageVergleich(array $a, array $hier): string
    {
        $felder = array_merge(self::ANLAGE_FELDER, ['eigenschaften', 'schutzorgane', 'anschluesse']);
        $n = [];
        foreach ($felder as $f) {
            $n[$f] = $a[$f] ?? null;
        }
        $n['fest'] = true;
        $n['steuerung_gesperrt'] = !empty($a['steuerung_gesperrt']) || !empty($hier['steuerung_gesperrt']);
        foreach (['x', 'y'] as $f) {
            $n[$f] = is_numeric($n[$f]) ? max(0.0, min(100.0, (float) $n[$f])) : null;
        }
        $n['eigenschaften'] = (array) ($n['eigenschaften'] ?? []);
        $n = TankService::ohneKennlinieHier($n);
        $anschluesse = array_values((array) ($n['anschluesse'] ?? []));
        usort($anschluesse, static fn ($x, $y): int => [(string) ($x['richtung'] ?? ''), (int) ($x['nr'] ?? 0)] <=> [(string) ($y['richtung'] ?? ''), (int) ($y['nr'] ?? 0)]);
        $n['anschluesse'] = $anschluesse;
        $n['schutzorgane'] = array_values((array) ($n['schutzorgane'] ?? []));
        return Fingerabdruck::von($n);
    }

    public static function leitungVergleich(array $l, array $hier): string
    {
        $n = [];
        foreach (array_merge(self::LEITUNG_FELDER, ['punkte']) as $f) {
            $n[$f] = $l[$f] ?? null;
        }
        $n['fest'] = true;
        $n['punkte'] = array_map(static fn ($p): array => [(float) ($p[0] ?? 0), (float) ($p[1] ?? 0)], array_values((array) ($n['punkte'] ?? [])));
        return Fingerabdruck::von($n);
    }

    private static function ohneWerWann(array $wert): string
    {
        unset($wert['von'], $wert['am'], $wert['meter_geo'], $wert['quelle']);
        return Fingerabdruck::von($wert);
    }

    // ---------------------------------------------------------------- entscheiden

    /**
     * Einen Vorschlag für die gewählten Etagen annehmen.
     *
     * Der Vorschlag wird als Erstes beansprucht (offen → angenommen, nur wenn
     * er noch offen ist) – in derselben Transaktion wie das Anwenden. Nehmen
     * zwei gleichzeitig an, oder lehnt einer ab, während der andere annimmt,
     * gewinnt genau einer; der andere hört „schon entschieden“, und nichts
     * wird doppelt angewandt. Scheitert das Anwenden, bleibt er offen.
     *
     * @param list<string> $etagen Schlüssel der Etagen
     * @param list<string> $teile ⊆ TEILE
     * @return array{ok:bool,message:string}
     */
    public static function annehmen(int $id, array $etagen, array $teile): array
    {
        $nein = static fn (string $m): array => ['ok' => false, 'message' => $m];
        $v = self::find($id);
        if ($v === null || !self::sichtbar($v)) {
            return $nein('Diesen Vorschlag gibt es nicht.');
        }
        if ($v['status'] !== self::OFFEN) {
            return $nein('Dieser Vorschlag ist schon entschieden.');
        }
        if (!Auth::can('api.vorschlaege')) {
            return $nein('Vorschläge annehmen braucht das Recht „Änderungsvorschläge anderer Instanzen“.');
        }
        $siteId = $v['site_id'] !== null ? (int) $v['site_id'] : 0;
        $site = $siteId > 0 ? SiteService::find($siteId) : null;
        if ($site === null) {
            return $nein('Den Standort gibt es hier nicht mehr – der Vorschlag lässt sich nur noch ablehnen.');
        }
        $daten = (array) $v['daten'];
        $location = (array) ($daten['location'] ?? []);
        $strom = is_array($daten['strom'] ?? null) ? $daten['strom'] : null;
        $mitFeststrom = $strom !== null && (array_key_exists('anlagen', $strom) || array_key_exists('leitungen', $strom));

        $teile = array_values(array_intersect(array_keys(self::TEILE), array_map('strval', $teile)));
        if (!$mitFeststrom) {
            $teile = array_values(array_diff($teile, ['feststrom']));
        }
        if ($teile === []) {
            return $nein('Nichts zum Übernehmen gewählt – Grundriss oder Feststrom ankreuzen.');
        }
        $imVorschlag = [];
        foreach ((array) ($location['etagen'] ?? []) as $e) {
            $imVorschlag[(string) ($e['schluessel'] ?? '')] = true;
        }
        $etagen = array_values(array_unique(array_filter(array_map('strval', $etagen), static fn (string $k): bool => isset($imVorschlag[$k]))));
        if ($etagen === []) {
            return $nein('Keine Etage gewählt – zum Verwerfen „Ablehnen“ nehmen.');
        }

        if (!Auth::can('plans.manage') || !Zugriff::darfStandortAendern($siteId)) {
            return $nein(sprintf('An „%s“ darfst du nichts ändern – annehmen braucht „Standorte, Etagen und Grundrisse pflegen“ und eine Freigabe mit „bearbeiten“.',
                (string) $site['name']));
        }
        if (in_array('feststrom', $teile, true)) {
            if (!Auth::can('anlagen.manage') || !Auth::can('leitungen.manage') || !Zugriff::darfAufStandortAnlegen($siteId)) {
                return $nein('Feststrom annehmen braucht die Rechte „Anlagen ändern“ und „Kabel einzeichnen“ an diesem Standort – nichts übernommen.');
            }
            $fehlen = LocationUebertrag::fehlendeSteckertypen($strom);
            if ($fehlen !== [] && !Auth::can('stecker.manage')) {
                return $nein('Diese Steckertypen gibt es hier noch nicht: ' . implode(', ', $fehlen)
                    . ' – anlegen darf sie nur, wer die Steckverbinder pflegt. Nichts übernommen.');
            }
        }

        $paare = [];
        foreach ($etagen as $key) {
            $floorId = (int) Database::scalar('SELECT id FROM floors WHERE site_id = ? AND sync_key = ? ORDER BY id LIMIT 1', [$siteId, $key]);
            if ($floorId <= 0) {
                return $nein(sprintf('Die Etage „%s“ gibt es hier nicht mehr – nichts übernommen.', $key));
            }
            $paare[$key] = $floorId;
        }
        $aufloeser = self::aufloeser($siteId, $location, self::ordner($id), (array) $v['dateien'], $etagen);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Beanspruchen – als erste Anweisung, damit sie die Schreibsperre holt.
            $beansprucht = Database::execute("UPDATE api_vorschlaege SET status = ?, entschieden_von = ?, entschieden_at = ?, entscheidung = ?
                WHERE id = ? AND status = 'offen'", [
                self::ANGENOMMEN, mb_substr(Auth::name(), 0, 64), gmdate('c'), self::json(['etagen' => $etagen, 'teile' => $teile]), $id,
            ]);
            if ($beansprucht !== 1) {
                $pdo->rollBack();
                return $nein('Dieser Vorschlag ist schon entschieden.');
            }
            self::sperren($siteId);
            $r = LocationUebertrag::anwenden($siteId, $location, $strom, $paare, $aufloeser, [
                'teile'           => $teile,
                'zuordnung'       => 'standort',
                'plan_praefix'    => 'vorschlag',
                'fremdbestand'    => false,
                'neue_etagen'     => false,
                // Fehlende, benutzte Typen nur mit dem Recht dazu (sonst schon oben abgelehnt).
                'stecker_anlegen' => Auth::can('stecker.manage'),
                'kacheln'         => false,
                'token_id'        => (int) $v['token_id'],
            ]);
            if (!$r['ok']) {
                $pdo->rollBack();
                return $nein($r['message']);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Vorschlag annehmen: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $nein('Annehmen ist fehlgeschlagen – es wurde nichts geändert, der Vorschlag bleibt offen. Details stehen im Log.');
        }
        self::wegraeumen(self::ordner($id));
        LocationBundle::kachelnBauen($r['kacheln']);
        Audit::log('api.vorschlag.angenommen', 'api_vorschlag', (string) $id, [
            'location' => (string) $v['site_key'], 'etagen' => $etagen, 'teile' => $teile, 'token' => (int) $v['token_id'],
        ]);
        return ['ok' => true, 'message' => sprintf('Vorschlag Nr. %d angenommen. ', $id) . $r['message']];
    }

    /**
     * Ablehnen – nur, solange er noch offen ist (sonst „schon entschieden“,
     * auch wenn ihn gerade jemand anderes angenommen hat).
     *
     * @return array{ok:bool,message:string}
     */
    public static function ablehnen(int $id, string $grund): array
    {
        $v = self::find($id);
        if ($v === null || !self::sichtbar($v)) {
            return ['ok' => false, 'message' => 'Diesen Vorschlag gibt es nicht.'];
        }
        if ($v['status'] !== self::OFFEN) {
            return ['ok' => false, 'message' => 'Dieser Vorschlag ist schon entschieden.'];
        }
        $siteId = $v['site_id'] !== null ? (int) $v['site_id'] : 0;
        $site = $siteId > 0 ? SiteService::find($siteId) : null;
        $darf = Auth::can('api.vorschlaege') && ($site !== null ? Zugriff::darfStandortAendern($siteId) : Zugriff::alles());
        if (!$darf) {
            return ['ok' => false, 'message' => 'Ablehnen braucht eine Freigabe mit „bearbeiten“ an diesem Standort.'];
        }
        $grund = mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $grund)), 0, 500);
        $geaendert = Database::execute("UPDATE api_vorschlaege SET status = ?, entschieden_von = ?, entschieden_at = ?, entscheidung = ?
            WHERE id = ? AND status = 'offen'", [
            self::ABGELEHNT, mb_substr(Auth::name(), 0, 64), gmdate('c'), self::json(['grund' => $grund]), $id,
        ]);
        if ($geaendert !== 1) {
            return ['ok' => false, 'message' => 'Dieser Vorschlag ist schon entschieden.'];
        }
        self::wegraeumen(self::ordner($id));
        Audit::log('api.vorschlag.abgelehnt', 'api_vorschlag', (string) $id, [
            'location' => (string) $v['site_key'], 'grund' => mb_substr($grund, 0, 200), 'token' => (int) $v['token_id'],
        ]);
        return ['ok' => true, 'message' => sprintf('Vorschlag Nr. %d abgelehnt – hier hat sich nichts geändert.', $id)];
    }

    /**
     * Wohin plan.datei beim Anwenden zeigt: die mitgeschickte Datei – oder,
     * wenn keine kam, derselbe Plan wie hier nur mit anderer Seite, die
     * Datei hier. Sonst null: der Plan hier bleibt. Nur unter dem Ordner des
     * Vorschlags oder storage/plans (realpath).
     *
     * @param array<string,array<string,mixed>> $abgelegt plan.datei → {datei, …}
     * @param list<string> $gewaehlt Etagen-Schlüssel
     * @return callable(string):?string
     */
    private static function aufloeser(int $siteId, array $location, string $ordner, array $abgelegt, array $gewaehlt): callable
    {
        $karte = [];
        foreach ($abgelegt as $pfad => $meta) {
            if (is_array($meta) && is_string($meta['datei'] ?? null) && preg_match(self::ABLAGE, $meta['datei']) === 1) {
                $karte[(string) $pfad] = $ordner . '/' . $meta['datei'];
            }
        }
        foreach ((array) ($location['etagen'] ?? []) as $e) {
            $plan = is_array($e['plan'] ?? null) ? $e['plan'] : null;
            if ($plan === null || !in_array((string) $e['schluessel'], $gewaehlt, true) || isset($karte[(string) $plan['datei']])) {
                continue;
            }
            $f = Database::first('SELECT * FROM floors WHERE site_id = ? AND sync_key = ? ORDER BY id LIMIT 1', [$siteId, (string) $e['schluessel']]);
            $summe = $f !== null ? SiteService::planPruefsumme($f) : null;
            if ($summe !== null && (string) $plan['sha256'] !== '' && hash_equals($summe['sha256'], (string) $plan['sha256'])
                && max(1, (int) ($f['plan_page'] ?? 1)) !== (int) $plan['seite']) {
                $karte[(string) $plan['datei']] = (string) SiteService::planPath($f);
            }
        }
        $wurzeln = array_values(array_filter([realpath($ordner), realpath(SiteService::planDir())]));
        return static function (string $pfad) use ($karte, $wurzeln): ?string {
            $echt = isset($karte[$pfad]) ? realpath($karte[$pfad]) : false;
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
    }

    // ---------------------------------------------------------------- Dateien und Aufräumen

    /**
     * Die geprüften Dateien in einen neuen Ordner schreiben (0700, Dateien 0600).
     *
     * @param array<string,array<string,mixed>> $dateien plan.datei → {roh, endung, …}
     * @param array<string,array<string,mixed>>|null $meta Namen schon gewählt
     * @return array<string,array<string,mixed>> plan.datei → {datei, sha256, bytes, mime}
     */
    private static function dateienSchreiben(string $ordner, array $dateien, ?array $meta = null): array
    {
        if ($dateien === []) {
            return [];
        }
        if (!is_dir($ordner) && !@mkdir($ordner, 0700, true) && !is_dir($ordner)) {
            throw new \RuntimeException('Ordner ' . basename($ordner) . ' ließ sich nicht anlegen.');
        }
        $out = [];
        foreach ($dateien as $pfad => $d) {
            $name = (string) ($meta[$pfad]['datei'] ?? bin2hex(random_bytes(12)) . '.' . $d['endung']);
            $ziel = $ordner . '/' . $name;
            if (file_put_contents($ziel, $d['roh']) !== strlen($d['roh'])) {
                throw new \RuntimeException('Datei ' . $name . ' ließ sich nicht schreiben.');
            }
            @chmod($ziel, 0600);
            $out[$pfad] = ['datei' => $name, 'sha256' => $d['sha256'], 'bytes' => $d['bytes'], 'mime' => $d['mime']];
        }
        return $out;
    }

    /** Einen Ordner unter storage/vorschlaege mit allem darin löschen – nichts außerhalb. */
    private static function wegraeumen(string $ordner): void
    {
        $wurzel = realpath(self::wurzel());
        $echt = realpath($ordner);
        if ($wurzel === false || $echt === false || !is_dir($echt) || !str_starts_with($echt, $wurzel . '/')) {
            return;
        }
        $alles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($echt, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($alles as $f) {
            ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($echt);
    }

    /**
     * Für bin/power wartung: entschiedene Vorschläge nach AUFBEWAHREN_TAGE
     * löschen, verwaiste Ordner (kein offener Vorschlag dazu) und
     * liegengebliebene *.neu (älter als ein Tag) entfernen.
     *
     * @return list<string>
     */
    public static function wartung(?int $jetzt = null): array
    {
        $jetzt ??= time();
        $grenze = gmdate('c', $jetzt - self::AUFBEWAHREN_TAGE * 86400);
        $alt = array_map('intval', array_column(Database::select(
            "SELECT id FROM api_vorschlaege WHERE status <> 'offen' AND COALESCE(entschieden_at, created_at) < ?", [$grenze]), 'id'));
        foreach ($alt as $id) {
            self::wegraeumen(self::ordner($id));
            Database::execute('DELETE FROM api_vorschlaege WHERE id = ?', [$id]);
        }
        $offen = array_flip(array_map('intval', array_column(Database::select("SELECT id FROM api_vorschlaege WHERE status = 'offen'"), 'id')));
        $wurzel = self::wurzel();
        $weg = 0;
        foreach (scandir($wurzel) ?: [] as $name) {
            $pfad = $wurzel . '/' . $name;
            if ($name === '.' || $name === '..' || is_link($pfad) || !is_dir($pfad)) {
                continue;
            }
            $verwaist = str_ends_with($name, '.neu') ? (int) @filemtime($pfad) < $jetzt - 86400 : (ctype_digit($name) && !isset($offen[(int) $name]));
            if ($verwaist) {
                self::wegraeumen($pfad);
                $weg++;
            }
        }
        return [sprintf('Vorschläge: %d entschiedene (älter als %d Tage) gelöscht, %d verwaiste Ordner entfernt.', count($alt), self::AUFBEWAHREN_TAGE, $weg)];
    }

    // ---------------------------------------------------------------- Hilfen

    private static function nein(int $status, string $meldung, array $mehr = []): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $meldung] + $mehr;
    }

    /** @throws \UnexpectedValueException */
    private static function ab(string $meldung, int $status = 422): never
    {
        throw new \UnexpectedValueException($meldung, $status);
    }

    /** Ein Text von außen: nur Zeichenketten und Zahlen, ohne Steuerzeichen (mit $mehrzeilig bleiben Zeilenumbrüche), gekürzt. */
    private static function text(mixed $wert, int $laenge, bool $mehrzeilig = false): string
    {
        if (!is_string($wert) && !is_int($wert) && !is_float($wert)) {
            return '';
        }
        $muster = $mehrzeilig ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u' : '/[\x00-\x1F\x7F]+/u';
        return mb_substr(trim((string) preg_replace($muster, ' ', (string) $wert)), 0, $laenge);
    }

    /** Ein Zeitpunkt von außen (ISO 8601) – sonst leer. */
    private static function zeit(mixed $wert): string
    {
        return is_string($wert) && strlen($wert) <= 40 && preg_match('/^\d{4}-\d{2}-\d{2}([T ][0-9:.]+)?(Z|[+-]\d{2}:?\d{2})?$/', $wert) === 1 ? $wert : '';
    }

    private static function json(mixed $wert): string
    {
        return (string) json_encode($wert, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function jsonArray(mixed $wert): array
    {
        $d = is_string($wert) ? json_decode($wert, true) : null;
        return is_array($d) ? $d : [];
    }
}
