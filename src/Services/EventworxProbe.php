<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Config;

/**
 * Lese-Probe: Was gibt die Eventworx-Schnittstelle zu Geräten, ihren
 * Prüfungen (DGUV V3, Werkstatt → Prüfungen) und den Dateien an einem
 * Vorgang her? Ob und wie es diese Daten über die API gibt, ist nicht belegt
 * (Karte DGUV §2–4) – die Probe sieht nach, bevor ein Folge-PR darauf baut.
 * Ein Administrator startet sie einmal von Hand
 * (`bin/power eventworx:geraete-pruefen`) und hält das Ergebnis in
 * docs/eventworx.md fest; es entscheidet DATEI_FILTER_MIT_PFAD, die
 * Verengung von `job/<id>` auf ObjectIds und den Folge-PR „Geräte-Prüfdaten“.
 *
 * Sicher, weil Eventworx per GET löscht und Filter still übergeht:
 *
 * - **Eigene, enge Liste** (PFADE), nur GET; die Liste des Betriebs
 *   (Eventworx::PFADE) bleibt, wie sie ist. Kein `file/<id>` (kein
 *   Download), nie `*\/update|create|delete*`, keine geratenen Unterpfade
 *   (`stock/inspection…`, `workshop/*`) – ein geratener Pfad kann schreiben.
 * - **Kennungen nur aus Antworten desselben Laufs** oder aus --stock/--job,
 *   immer als ObjectId; Links in Antworten wird nie gefolgt. Listen mit
 *   festen Parametern (`start=0&limit=3&page=1`, Dateilisten bis 20),
 *   Suchtext nur --serie nach [A-Za-z0-9._/-]{1,64}.
 * - **Budget:** höchstens 25 Anfragen und 60 s, Takt wie immer (0,5 s,
 *   gemeinsame Sperrdatei), kein Neuversuch. Abbruch bei 401, 429, einer
 *   Umleitung oder einer Antwort ohne JSON (HTML-Anmeldeseite);
 *   ACCESS_DENIED (oder 403) schaltet nur den Schritt ab.
 * - **Ausgabe ohne Inhalte:** je Schlüssel nur die Art des Werts (null,
 *   bool, int, ms-Zeit, ISO-Zeit, ObjectId, Text(n), Liste(n), Objekt);
 *   Klartext nur für feste Kennwerte (status, type, metaDocType,
 *   contentType, docType …) nach [A-Za-z0-9_.-]{1,32}; Pfade mit maskierten
 *   Nummern. Nie Namen, Seriennummern, Dateinamen, Beschreibungen,
 *   Benutzer. Schlüssel, die nach Prüfung klingen, sind hervorgehoben.
 */
final class EventworxProbe
{
    /** Die einzigen Pfade der Probe – und nur mit GET. */
    public const PFADE = '#^(check/ping|check/version|job|job/[0-9a-f]{24}|stock|stock/[0-9a-f]{24}|article/[0-9a-f]{24}|file/list)$#';
    public const BUDGET = 25;
    public const SEKUNDEN = 60;

    private const OID = '/^[0-9a-f]{24}$/';
    private const SERIE = '#^[A-Za-z0-9._/-]{1,64}$#';
    private const KENNWERT = '/^[A-Za-z0-9_.-]{1,32}$/';
    /** Ein Inhaltstyp (application/pdf) – mit Schrägstrich und Plus. */
    private const INHALTSTYP = '#^[A-Za-z0-9_.+-]{1,32}/[A-Za-z0-9_.+-]{1,48}$#';
    /** Schlüssel, deren Werte als Kennwerte (nicht als Inhalt) im Klartext stehen dürfen. */
    private const KENNWERT_SCHLUESSEL = ['status', 'type', 'activation', 'metaDocType', 'contentType', 'docType', 'docSubType', 'subType', 'version'];
    /** Schlüssel, die nach Prüfung, Wartung oder Frist klingen. */
    private const PRUEF = '/pr(ü|ue)f|inspect|check|test|dguv|vde|wartung|maint|service|repair|interval|next|last|due|valid/iu';
    /** Schlüssel in Positionen, die auf ausgegebene Geräte zeigen könnten. */
    private const GERAET = '/deliver|item|serial|inventory|stock|device|case/i';
    /** Ein Schlüssel, der als Name taugt – sonst nur gezählt (ein Schlüssel kann selbst ein Inhalt sein). */
    private const SCHLUESSEL = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';
    /** Vorgänge, an denen Geräte und Dateien hängen: Aufträge und Lieferscheine. */
    private const ARTEN = ['order', 'deliverynote'];

    private int $anfragen = 0;
    private float $beginn;
    private ?string $abbruch = null;
    /** @var list<array<string,mixed>> */
    private array $schritte = [];

    private function __construct()
    {
        $this->beginn = Eventworx::jetzt();
    }

    /**
     * Die Probe laufen lassen.
     *
     * @return array{ok:bool,meldung:string,anfragen:int,schritte:list<array{schritt:string,pfad:string,status:int,code:string,total:?int,schluessel:array<string,string>,kennwerte:array<string,string>,pruef:list<string>,hinweise:list<string>}>}
     */
    public static function lauf(?string $stock = null, ?string $job = null, ?string $serie = null): array
    {
        foreach (['--stock' => $stock, '--job' => $job] as $name => $wert) {
            if ($wert !== null && preg_match(self::OID, $wert) !== 1) {
                return ['ok' => false, 'meldung' => $name . ' braucht eine ObjectId (24 Hexzeichen, klein).', 'anfragen' => 0, 'schritte' => []];
            }
        }
        if ($serie !== null && preg_match(self::SERIE, $serie) !== 1) {
            return ['ok' => false, 'meldung' => '--serie: nur A–Z, a–z, 0–9, Punkt, Strich, Schrägstrich und Unterstrich, höchstens 64 Zeichen.', 'anfragen' => 0, 'schritte' => []];
        }
        if (Config::isDemo()) {
            return ['ok' => false, 'meldung' => 'Demo-Modus: die Lese-Probe fragt kein Eventworx.', 'anfragen' => 0, 'schritte' => []];
        }
        if (!Eventworx::eingerichtet()) {
            return ['ok' => false, 'meldung' => 'Eventworx ist nicht eingerichtet (Systemeinstellungen).', 'anfragen' => 0, 'schritte' => []];
        }
        $p = new self();
        $p->ablauf($stock, $job, $serie);
        return [
            'ok'       => $p->abbruch === null,
            'meldung'  => $p->abbruch ?? 'Probe beendet.',
            'anfragen' => $p->anfragen,
            'schritte' => $p->schritte,
        ];
    }

    /** Die Art eines Werts – nie der Wert selbst. */
    public static function art(mixed $wert): string
    {
        $ms = static fn (int $z): bool => $z >= 946684800000 && $z < 4102444800000; // 2000 bis 2100
        return match (true) {
            $wert === null                                                      => 'null',
            is_bool($wert)                                                      => 'bool',
            is_int($wert) && $ms($wert)                                         => 'ms-Zeit',
            is_int($wert)                                                       => 'int',
            is_float($wert)                                                     => 'Zahl',
            is_string($wert) && preg_match(self::OID, $wert) === 1              => 'ObjectId',
            is_string($wert) && preg_match('/^\d{13}$/', $wert) === 1 && $ms((int) $wert) => 'ms-Zeit (Text)',
            is_string($wert) && preg_match('/^(20\d\d|2100)-\d{2}-\d{2}([T ]\d{2}:\d{2}.*)?$/', $wert) === 1 => 'ISO-Zeit',
            is_string($wert)                                                    => 'Text(' . mb_strlen($wert) . ')',
            is_array($wert) && array_is_list($wert)                             => 'Liste(' . count($wert) . ')',
            is_array($wert)                                                     => 'Objekt',
            default                                                             => 'anders',
        };
    }

    /** Ein Pfad im Dateispeicher ohne Nummern und Namen: /job/documents/P-2026-017 → /job/documents/<nr>. */
    public static function pfadMuster(string $pfad): string
    {
        $teile = [];
        foreach (explode('/', $pfad) as $teil) {
            $teile[] = match (true) {
                $teil === '', preg_match('/^[a-z]{1,24}$/', $teil) === 1 => $teil,
                preg_match('/\d/', $teil) === 1                          => '<nr>',
                default                                                  => '<text>',
            };
        }
        return mb_substr(implode('/', $teile), 0, 120);
    }

    // ------------------------------------------------------------ Ablauf

    private function ablauf(?string $stock, ?string $job, ?string $serie): void
    {
        // P1: erreichbar, Token, Version, ein Vorgang (Auftrag oder Lieferschein – für P5 und P6)
        $this->frage('P1', 'check/ping');
        $version = $this->frage('P1', 'check/version');
        if ($version !== null) {
            $this->kennwerteAus($version);
        }
        $jobs = $this->frage('P1', 'job', ['start' => 0, 'limit' => 1, 'page' => 1,
            'filter' => $this->json([['property' => 'subType', 'operator' => 'in', 'value' => self::ARTEN]])]);
        $job ??= $this->ersteId($jobs);

        // P2: Geräteliste (Leserecht, total, Schlüssel der kurzen Liste)
        $liste = $this->frage('P2', 'stock', ['start' => 0, 'limit' => 3, 'page' => 1]);
        $geraete = $stock !== null ? [$stock] : [];
        foreach ($this->eintraege($liste) as $eintrag) {
            $id = is_string($eintrag['id'] ?? null) ? $eintrag['id'] : '';
            if (preg_match(self::OID, $id) === 1 && !in_array($id, $geraete, true)) {
                $geraete[] = $id;
            }
        }
        $geraete = array_slice($geraete, 0, 3);

        // P3: einzelne Geräte (volle Schlüssel, Prüf-Schlüssel, Verweis auf den Artikel)
        $artikel = null;
        foreach ($geraete as $g) {
            $daten = $this->frage('P3', 'stock/' . $g);
            $artikel ??= $this->artikelId($daten);
        }

        // P4: der Artikel des Geräts (Intervall, Einzelerfassung)
        if ($artikel !== null) {
            $this->frage('P4', 'article/' . $artikel);
        }

        // P5: Dateien am Vorgang – mit path (wie die Buchhaltung) und ohne; dann am Gerät
        if ($job !== null) {
            $this->dateien('P5a', $job, ['path' => '/job/documents', 'ownerDocumentId' => $job]);
            $this->dateien('P5b', $job, ['ownerDocumentId' => $job]);
        } else {
            $this->hinweis('P5a', 'file/list', 'Kein Vorgang mit ObjectId – P5a/P5b übersprungen (--job angeben).');
        }
        if ($geraete !== []) {
            $this->dateien('P5c', $geraete[0], ['ownerDocumentId' => $geraete[0]]);
        }

        // P6: ein Vorgang mit seinen Positionen – zeigen sie auf ausgegebene Geräte?
        if ($job !== null) {
            $daten = $this->frage('P6', 'job/' . $job, [], false);
            if ($daten !== null) {
                $this->positionen($daten, $geraete);
            }
        }

        // P7: Suche nach einer Seriennummer – nur ja/nein
        if ($serie !== null) {
            $treffer = $this->frage('P7', 'stock', ['start' => 0, 'limit' => 5, 'page' => 1, 'query' => $serie], false);
            if ($treffer !== null) {
                $gleich = false;
                foreach ($this->eintraege($treffer) as $eintrag) {
                    $gleich = $gleich || $this->enthaelt($eintrag, $serie, 0);
                }
                $this->letzter()['hinweise'][] = 'Seriennummer unter den Treffern: ' . ($gleich ? 'ja' : 'nein');
            }
        }
    }

    /**
     * Eine Anfrage – im Budget, ausgewertet ohne Inhalte. Rückgabe: die
     * Antwort (JSON) oder null (Schritt aus, Fehler, Abbruch).
     */
    private function frage(string $schritt, string $pfad, array $parameter = [], bool $schluessel = true): ?array
    {
        $muster = (string) preg_replace('#^([a-z]+)/[0-9a-f]{24}$#', '$1/<id>', $pfad);
        if ($this->abbruch !== null) {
            return null;
        }
        if ($this->anfragen >= self::BUDGET) {
            $this->abbruch = 'Budget erreicht (' . self::BUDGET . ' Anfragen) – Probe abgebrochen.';
            return null;
        }
        if (Eventworx::jetzt() - $this->beginn > self::SEKUNDEN) {
            $this->abbruch = 'Zeitbudget erreicht (' . self::SEKUNDEN . ' s) – Probe abgebrochen.';
            return null;
        }
        $this->anfragen++;
        $eintrag = ['schritt' => $schritt, 'pfad' => $muster, 'status' => 0, 'code' => '', 'total' => null,
            'schluessel' => [], 'kennwerte' => [], 'pruef' => [], 'hinweise' => []];
        try {
            $r = Eventworx::probeLesen($pfad, $parameter);
        } catch (EventworxFehler $e) {
            $eintrag['code'] = $e->fehlercode;
            $eintrag['hinweise'][] = $e->getMessage();
            $this->schritte[] = $eintrag;
            $this->abbruch = 'Abgebrochen: ' . $e->getMessage();
            return null;
        }
        $eintrag['status'] = $r['status'];
        $eintrag['code'] = $r['code'];
        $status = $r['status'];
        $grund = match (true) {
            !$r['ok']                       => 'Eventworx nicht erreichbar',
            $status >= 300 && $status < 400 => 'Eventworx leitet um (HTTP ' . $status . ')',
            $status === 401                 => 'Token abgelehnt (HTTP 401)',
            $status === 429                 => 'zu viele Anfragen (HTTP 429)',
            !$r['json']                     => 'keine JSON-Antwort (HTML-Anmeldeseite?, HTTP ' . $status . ')',
            default                         => null,
        };
        if ($grund !== null) {
            $eintrag['hinweise'][] = $grund;
            $this->schritte[] = $eintrag;
            $this->abbruch = 'Abgebrochen: ' . $grund . '.';
            return null;
        }
        $daten = (array) $r['daten'];
        if ($status === 403 || $r['code'] === 'ACCESS_DENIED') {
            $eintrag['hinweise'][] = 'kein Leserecht (ACCESS_DENIED) – Schritt übersprungen';
            $this->schritte[] = $eintrag;
            return null;
        }
        if ($status !== 200 || ($daten['success'] ?? null) === false) {
            $eintrag['hinweise'][] = 'Fehlerantwort (HTTP ' . $status . ($r['code'] !== '' ? ', ' . $r['code'] : '') . ')';
            $this->schritte[] = $eintrag;
            return null;
        }
        $total = $daten['total'] ?? null;
        $eintrag['total'] = is_int($total) || (is_string($total) && ctype_digit($total)) ? (int) $total : null;
        if ($schluessel) {
            $inhalt = $daten['data'] ?? null;
            $vorlage = is_array($inhalt) && array_is_list($inhalt) ? ($inhalt[0] ?? null) : $inhalt;
            if (is_array($vorlage)) {
                [$eintrag['schluessel'], $eintrag['kennwerte'], $eintrag['pruef'], $andere] = $this->schluessel($vorlage);
                if ($andere > 0) {
                    $eintrag['hinweise'][] = $andere . ' Schlüssel ohne Namensform (nicht gezeigt)';
                }
            } elseif ($inhalt !== null) {
                $eintrag['schluessel'] = ['data' => self::art($inhalt)];
            }
        }
        $this->schritte[] = $eintrag;
        return $daten;
    }

    /** P5: eine Dateiliste – Zahlen, Muster und Kennwerte, nie Namen. */
    private function dateien(string $schritt, string $owner, array $filter): void
    {
        $daten = $this->frage($schritt, 'file/list', ['page' => 1, 'start' => 0, 'limit' => 20,
            'filter' => $this->json([['property' => 'meta', 'operator' => '=', 'value' => $filter]])], false);
        if ($daten === null) {
            return;
        }
        $liste = $this->eintraege($daten);
        $fremd = 0;
        $ohneArt = 0;
        $arten = [];
        $typen = [];
        $pfade = [];
        $ids = ['ObjectId' => 0, 'andere' => 0];
        $schluessel = [];
        foreach ($liste as $f) {
            $fremd += ($f['ownerDocumentId'] ?? null) !== $owner ? 1 : 0;
            $art = $f['metaDocType'] ?? null;
            if ($art === null || $art === '') {
                $ohneArt++;
            } else {
                $arten[$this->kennwert($art)] = ($arten[$this->kennwert($art)] ?? 0) + 1;
            }
            if (isset($f['contentType'])) {
                $typ = $this->kennwert($f['contentType'], true);
                $typen[$typ] = ($typen[$typ] ?? 0) + 1;
            }
            if (is_string($f['path'] ?? null)) {
                $pfade[self::pfadMuster($f['path'])] = true;
            }
            $ids[is_string($f['id'] ?? null) && preg_match(self::OID, $f['id']) === 1 ? 'ObjectId' : 'andere']++;
            $schluessel += $this->schluessel($f)[0];
        }
        $e = &$this->letzter();
        ksort($schluessel);
        $e['schluessel'] = $schluessel;
        $e['hinweise'][] = count($liste) . ' Dateien in der Antwort, davon ' . $fremd . ' mit anderem oder fehlendem ownerDocumentId'
            . ($fremd > 0 ? ' – der Filter wirkt so nicht (dokumente() verwürfe die Liste)' : '');
        $e['hinweise'][] = $ohneArt . ' ohne metaDocType (Uploads von Benutzern?)';
        foreach ($arten as $art => $n) {
            $e['hinweise'][] = 'metaDocType ' . $art . ': ' . $n;
        }
        foreach ($typen as $typ => $n) {
            $e['hinweise'][] = 'contentType ' . $typ . ': ' . $n;
        }
        if ($pfade !== []) {
            $e['hinweise'][] = 'path-Muster: ' . implode(', ', array_keys($pfade));
        }
        $e['hinweise'][] = 'Datei-IDs: ' . $ids['ObjectId'] . ' ObjectId, ' . $ids['andere'] . ' andere';
    }

    /** P6: Schlüssel in den Positionen (rekursiv, höchstens 20 Ebenen), die auf Geräte zeigen könnten. */
    private function positionen(array $daten, array $geraete): void
    {
        $job = is_array($daten['data'] ?? null) ? $daten['data'] : [];
        $e = &$this->letzter();
        [$e['schluessel'], $e['kennwerte'], $e['pruef']] = $this->schluessel($job);
        $baum = $job['positionTree'] ?? null;
        if (!is_array($baum)) {
            $e['hinweise'][] = 'kein positionTree im Vorgang';
            return;
        }
        $funde = [];
        $oids = [];
        $suche = function (mixed $knoten, string $weg, int $tiefe) use (&$suche, &$funde, &$oids): void {
            if (!is_array($knoten) || $tiefe > 20) {
                return;
            }
            foreach ($knoten as $k => $v) {
                $name = is_int($k) ? '[]' : (preg_match(self::SCHLUESSEL, (string) $k) === 1 && preg_match('/\d{4}/', (string) $k) !== 1 ? (string) $k : '<schlüssel>');
                $pfad = is_int($k) ? $weg . '[]' : ($weg === '' ? $name : $weg . '.' . $name);
                if (!is_int($k) && preg_match(self::GERAET, (string) $k) === 1) {
                    $funde[$pfad] = self::art($v);
                    foreach ((array) $v as $w) {
                        if (is_string($w) && preg_match(self::OID, $w) === 1) {
                            $oids[$w] = true;
                        }
                    }
                    if (is_string($v) && preg_match(self::OID, $v) === 1) {
                        $oids[$v] = true;
                    }
                }
                $suche($v, $pfad, $tiefe + 1);
            }
        };
        $suche($baum, 'positionTree', 0);
        ksort($funde);
        $e['hinweise'][] = count($funde) . ' Geräte-Schlüssel in den Positionen' . ($funde === [] ? '' : ': '
            . implode(', ', array_map(static fn (string $k, string $a): string => $k . ' (' . $a . ')', array_keys($funde), $funde)));
        $e['hinweise'][] = count($oids) . ' ObjectId-Werte unter diesen Schlüsseln';
        // Eine davon gegenprüfen: ist es ein Gerät (stock/<id>)?
        $probe = null;
        foreach (array_keys($oids) as $oid) {
            $probe ??= in_array($oid, $geraete, true) ? null : (string) $oid;
        }
        if ($probe !== null) {
            unset($e);
            $antwort = $this->frage('P6', 'stock/' . $probe);
            $this->letzter()['hinweise'][] = 'Gegenprobe ObjectId aus den Positionen als Gerät: ' . ($antwort !== null ? 'ja' : 'nein');
        }
    }

    // ------------------------------------------------------------ Hilfen

    /**
     * Schlüssel eines Objekts (zwei Ebenen) mit der Art ihrer Werte, die
     * Kennwerte und die Prüf-Schlüssel; dazu die Zahl der Schlüssel, die
     * selbst wie ein Inhalt aussehen.
     *
     * @return array{0:array<string,string>,1:array<string,string>,2:list<string>,3:int}
     */
    private function schluessel(array $objekt, string $vor = '', int $tiefe = 0): array
    {
        $arten = [];
        $kennwerte = [];
        $pruef = [];
        $andere = 0;
        foreach ($objekt as $k => $v) {
            if (is_int($k)) {
                continue;
            }
            if (preg_match(self::SCHLUESSEL, $k) !== 1 || preg_match('/\d{4}/', $k) === 1) {
                $andere++;
                continue;
            }
            $name = $vor . $k;
            $arten[$name] = self::art($v);
            if (in_array($k, self::KENNWERT_SCHLUESSEL, true) && (is_string($v) || is_int($v))) {
                $kennwerte[$name] = $this->kennwert($v, $k === 'contentType');
            }
            if (preg_match(self::PRUEF, $k) === 1) {
                $pruef[] = $name;
            }
            if (is_array($v) && !array_is_list($v) && $tiefe < 1) {
                [$a, $kw, $p, $n] = $this->schluessel($v, $name . '.', $tiefe + 1);
                $arten += $a;
                $kennwerte += $kw;
                $pruef = array_merge($pruef, $p);
                $andere += $n;
            }
        }
        ksort($arten);
        return [$arten, $kennwerte, $pruef, $andere];
    }

    /** Ein fester Kennwert im Klartext – alles andere nur als „(kein Kennwert)“. */
    private function kennwert(mixed $wert, bool $inhaltstyp = false): string
    {
        $text = is_string($wert) || is_int($wert) ? trim((string) $wert) : '';
        if ($text === '') {
            return '(leer)';
        }
        return preg_match(self::KENNWERT, $text) === 1 || ($inhaltstyp && preg_match(self::INHALTSTYP, $text) === 1) ? $text : '(kein Kennwert)';
    }

    /** check/version: die Versionsnummer, wenn sie wie eine aussieht. */
    private function kennwerteAus(array $daten): void
    {
        foreach ([$daten['data'] ?? null, $daten['version'] ?? null, is_array($daten['data'] ?? null) ? ($daten['data']['version'] ?? null) : null] as $wert) {
            if (is_string($wert) || is_int($wert)) {
                $this->letzter()['kennwerte']['version'] = $this->kennwert($wert);
                return;
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function eintraege(?array $daten): array
    {
        $liste = $daten['data'] ?? null;
        return is_array($liste) && array_is_list($liste) ? array_values(array_filter($liste, 'is_array')) : [];
    }

    private function ersteId(?array $daten): ?string
    {
        foreach ($this->eintraege($daten) as $e) {
            if (is_string($e['id'] ?? null) && preg_match(self::OID, $e['id']) === 1) {
                return $e['id'];
            }
        }
        return null;
    }

    /** Die ObjectId eines Artikels in einem Gerät: ein Schlüssel mit „article“ und ObjectId (oder Objekt mit id). */
    private function artikelId(?array $daten): ?string
    {
        $geraet = is_array($daten['data'] ?? null) ? $daten['data'] : [];
        foreach ($geraet as $k => $v) {
            if (!is_string($k) || stripos($k, 'article') === false) {
                continue;
            }
            $id = is_array($v) ? ($v['id'] ?? null) : $v;
            if (is_string($id) && preg_match(self::OID, $id) === 1) {
                return $id;
            }
        }
        return null;
    }

    /** Steht $suche als ganzer Wert irgendwo in $wert (Groß/Klein egal, höchstens 5 Ebenen)? */
    private function enthaelt(mixed $wert, string $suche, int $tiefe): bool
    {
        if (is_string($wert)) {
            return strcasecmp(trim($wert), $suche) === 0;
        }
        if (!is_array($wert) || $tiefe > 5) {
            return false;
        }
        foreach ($wert as $v) {
            if ($this->enthaelt($v, $suche, $tiefe + 1)) {
                return true;
            }
        }
        return false;
    }

    private function hinweis(string $schritt, string $pfad, string $text): void
    {
        $this->schritte[] = ['schritt' => $schritt, 'pfad' => $pfad, 'status' => 0, 'code' => '', 'total' => null,
            'schluessel' => [], 'kennwerte' => [], 'pruef' => [], 'hinweise' => [$text]];
    }

    /** @return array<string,mixed> der zuletzt angefügte Schritt (als Referenz) */
    private function &letzter(): array
    {
        if ($this->schritte === []) {
            $this->hinweis('-', '-', '');
        }
        return $this->schritte[array_key_last($this->schritte)];
    }

    private function json(array $wert): string
    {
        return (string) json_encode($wert, JSON_UNESCAPED_SLASHES);
    }
}
