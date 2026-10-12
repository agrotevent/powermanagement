<?php
declare(strict_types=1);

namespace App\Messung;

use App\Auth\Auth;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Database;
use App\Support\Logger;

/**
 * Eigene MQTT-Zugänge je Shelly – neben dem gemeinsamen Zugang „shelly“.
 *
 * Der gemeinsame Zugang bleibt, bis jedes Gerät umgestellt ist. Ein Gerät
 * nach dem anderen bekommt einen eigenen Broker-Benutzer, der nur seinen
 * eigenen Themenbaum <präfix>/# darf:
 *
 *   Gen2  Benutzer = Präfix (shellypro4pm-a0b1c2d3e4f5, shelly/halle-3).
 *         „Einrichten“ legt den Zugang am Broker an, prüft ihn und überträgt
 *         ihn mit MQTT.SetConfig – wirksam ab dem nächsten Neustart des
 *         Geräts. Die App startet nie selbst neu: ein Neustart kann je nach
 *         Modell die Ausgänge schalten.
 *   Gen1  Benutzer = Geräte-ID (Präfix shellies/<ID>). Gen1 nimmt keine
 *         Einstellungen über MQTT an; das Kennwort wird genau einmal
 *         angezeigt und am Gerät eingetragen.
 *
 * „Umstellung prüfen“ sperrt den gemeinsamen Zugang für kurze Zeit auf den
 * Baum des Geräts. Antwortet das Gerät trotzdem, hängt es am eigenen Zugang:
 * dann bleibt die Sperre dauerhaft (Zustand „eigen“) – erst damit kann ein
 * Gerät mit dem gemeinsamen Kennwort dieses Gerät weder schalten noch
 * mitlesen. Antwortet es nicht, fällt die Sperre sofort wieder weg.
 *
 * Die App schreibt nur ihren Sollzustand nach storage/mqtt/soll; der
 * root-Helfer deploy/mqtt-zugaenge.sh prüft ihn, schreibt die Kennwortdatei
 * und die wirksame ACL des Brokers und meldet in storage/mqtt/ist zurück.
 * Kennwörter werden nie gespeichert, nur der Hash, wie ihn Mosquitto braucht.
 */
final class MqttZugaenge
{
    public const GEMEINSAM = 'shelly';

    public const ZUSTAENDE = [
        'gemeinsam'   => 'gemeinsamer Zugang',
        'angelegt'    => 'angelegt',
        'uebertragen' => 'wartet auf Neustart',
        'pruefung'    => 'wird geprüft',
        'eigen'       => 'eigener Zugang',
    ];

    /** Namen, die nie ein Gerätezugang sein dürfen (ohne Groß/Klein). */
    public const RESERVIERT = ['shelly', 'shellies', 'nodered', 'powermanagement', 'pm', 'command', 'announce'];

    /** So lange darf die Prüf-Sperre des gemeinsamen Zugangs höchstens dauern. */
    public const PRUEFUNG_SEKUNDEN = [1 => 120, 2 => 90];

    public const FEHLERTEXTE = [
        'generation' => 'Unbekannte Generation.',
        'zeichen'    => 'Dieses Präfix eignet sich nicht als Zugangsname. Erlaubt sind Gen2 „<id>“ oder „shelly/<name>“ (klein: a–z, 0–9, _ . -, bis 64 Zeichen) und Gen1 „shellies/<ID>“.',
        'reserviert' => 'Dieser Name ist am Broker reserviert.',
        'doppelt'    => 'Für dieses Gerät gibt es schon einen eigenen Zugang.',
        'geraet'     => 'Ein anderes Gerät mit demselben Namen hat schon einen eigenen Zugang.',
        'format'     => 'Die Soll-Datei ist beschädigt.',
        'kopf'       => 'Die Soll-Datei hat einen falschen Kopf.',
        'lauf'       => 'Die Soll-Datei hat keine gültige Laufnummer.',
        'groesse'    => 'Die Soll-Datei ist zu groß.',
        'anzahl'     => 'Zu viele Zugänge.',
        'hash'       => 'Ein Kennwort-Hash ist ungültig.',
        'sperre'     => 'Eine Sperre ist ungültig.',
        'viele'      => 'Es würden auf einmal mehr als 5 Zugänge entfernt – abgelehnt (bin/power mqtt:zugaenge-schreiben --viele-entfernen).',
        'marker'     => 'Die Kennwortdatei des Brokers hat einen beschädigten Block der Gerätezugänge.',
        'basis'      => 'Die ACL-Grundlage des Brokers enthält unbekannte Zeilen.',
    ];

    private const KOPF = '# powermanagement mqtt-zugaenge 1';
    private const ERGEBNIS_KOPF = '# powermanagement mqtt-zugaenge-ergebnis 1';
    private const HASH_MUSTER = '#^\$7\$101\$[A-Za-z0-9+/]{16}\$[A-Za-z0-9+/]{86}==$#D';
    private const SPALTEN = 'id, benutzer, praefix, geraet, generation, zustand, sperre_bis, neustart_noetig, kennwort_am, uebertragen_am, bestaetigt_am, meldung, created_by, created_at, updated_at';

    // ------------------------------------------------------------ lesen

    /** Alle Zugänge – nie mit Hash. @return list<array<string,mixed>> */
    public static function alle(): array
    {
        return Database::select('SELECT ' . self::SPALTEN . ' FROM mqtt_zugaenge ORDER BY praefix');
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT ' . self::SPALTEN . ' FROM mqtt_zugaenge WHERE id = ?', [$id]);
    }

    /** Exakt verglichen – MySQL vergleicht sonst ohne Groß/Klein. */
    public static function nachPraefix(string $praefix): ?array
    {
        $praefix = trim($praefix, '/');
        foreach (Database::select('SELECT ' . self::SPALTEN . ' FROM mqtt_zugaenge WHERE praefix = ?', [$praefix]) as $z) {
            if ($z['praefix'] === $praefix) {
                return $z;
            }
        }
        return null;
    }

    // ------------------------------------------------------------ Regeln

    /** Broker-Benutzer zu einem Präfix: Gen2 = Präfix, Gen1 = ID nach „shellies/“. */
    public static function benutzerAusPraefix(string $praefix, int $generation): ?string
    {
        $praefix = trim($praefix, '/');
        if ($generation === 1) {
            return preg_match('#^shellies/([^/]+)$#D', $praefix, $m) === 1 ? $m[1] : null;
        }
        return $praefix;
    }

    public static function praefixAusBenutzer(string $benutzer, int $generation): string
    {
        return $generation === 1 ? 'shellies/' . $benutzer : $benutzer;
    }

    /** Name in der InfluxDB: letztes Segment, klein (wie geraeteName() in Node-RED). */
    public static function geraetName(string $praefix): string
    {
        $teile = explode('/', trim($praefix, '/'));
        return preg_replace('/[^a-z0-9_.-]/', '-', strtolower((string) end($teile))) ?? '';
    }

    /**
     * Die feste Namensregel – gleich im Helfer (--nur-namen).
     * null = in Ordnung, sonst Code aus FEHLERTEXTE.
     */
    public static function namensFehler(string $benutzer, int $generation): ?string
    {
        if ($generation !== 1 && $generation !== 2) {
            return 'generation';
        }
        $muster = $generation === 2 ? '#^(shelly/)?[a-z0-9][a-z0-9_.-]{0,63}$#D' : '#^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$#D';
        if (preg_match($muster, $benutzer) !== 1) {
            return 'zeichen';
        }
        $klein = strtolower($benutzer);
        if (in_array($klein, self::RESERVIERT, true) || preg_match('/^pm[-_.]/', $klein) === 1) {
            return 'reserviert';
        }
        return null;
    }

    /** Namensregel und Eindeutigkeit; null = in Ordnung, sonst Meldung. */
    public static function benutzerFehler(string $benutzer, int $generation, ?int $ohneId = null): ?string
    {
        $code = self::namensFehler($benutzer, $generation);
        if ($code !== null) {
            return self::FEHLERTEXTE[$code];
        }
        $praefix = self::praefixAusBenutzer($benutzer, $generation);
        // Unter dem Präfix des Sammlers (pm/…) stehen die normalisierten Messwerte.
        $sammler = trim((string) Config::get('mqtt.prefix', 'pm'), '/');
        if ($sammler !== '' && explode('/', $praefix)[0] === $sammler) {
            return self::FEHLERTEXTE['reserviert'];
        }
        $geraet = self::geraetName($praefix);
        foreach (Database::select('SELECT id, benutzer, geraet FROM mqtt_zugaenge') as $z) {
            if ($ohneId !== null && (int) $z['id'] === $ohneId) {
                continue;
            }
            if ($z['benutzer'] === $benutzer) {
                return self::FEHLERTEXTE['doppelt'];
            }
            if ($z['geraet'] === $geraet) {
                return self::FEHLERTEXTE['geraet'];
            }
        }
        return null;
    }

    /** Hash wie mosquitto_passwd: PBKDF2-SHA512, 101 Runden, 12 Byte Salz. */
    public static function hash(string $kennwort): string
    {
        $salz = random_bytes(12);
        return '$7$101$' . base64_encode($salz) . '$' . base64_encode(hash_pbkdf2('sha512', $kennwort, $salz, 101, 64, true));
    }

    /** 24 Zeichen ohne Verwechsler (0/O, 1/l/I) – rund 140 Bit. */
    public static function kennwortErzeugen(): string
    {
        $zeichen = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $kennwort = '';
        for ($i = 0; $i < 24; $i++) {
            $kennwort .= $zeichen[random_int(0, strlen($zeichen) - 1)];
        }
        return $kennwort;
    }

    // ------------------------------------------------------------ Broker

    public static function verzeichnis(): string
    {
        return rtrim((string) Config::get('mqtt.zugaenge_verzeichnis', APP_ROOT . '/storage/mqtt'), '/');
    }

    /**
     * Was der Helfer zuletzt gemeldet hat.
     *
     * @return array{lauf:?int,zeit:int,status:string,acl_wirksam:bool,basis_muster:bool,sperre_moeglich:bool,konten:list<string>,gesperrt:list<string>,fehler:list<array{zeile:int,code:string}>}|null
     */
    public static function ergebnis(): ?array
    {
        $datei = self::verzeichnis() . '/ist/ergebnis.txt';
        clearstatcache(true, $datei);
        $text = is_file($datei) ? @file_get_contents($datei, false, null, 0, 1 << 20) : false;
        if (!is_string($text) || !str_starts_with($text, self::ERGEBNIS_KOPF . "\n")) {
            return null;
        }
        $e = ['lauf' => null, 'zeit' => 0, 'status' => '', 'acl_wirksam' => false, 'basis_muster' => false,
              'sperre_moeglich' => false, 'konten' => [], 'gesperrt' => [], 'fehler' => []];
        foreach (explode("\n", $text) as $zeile) {
            $t = explode(' ', $zeile);
            $wert = $t[1] ?? '';
            switch ($t[0]) {
                case 'lauf':
                    $e['lauf'] = ctype_digit($wert) ? (int) $wert : null;
                    break;
                case 'zeit':
                    $e['zeit'] = ctype_digit($wert) ? (int) $wert : 0;
                    break;
                case 'status':
                    $e['status'] = $wert;
                    break;
                case 'acl_wirksam':
                case 'basis_muster':
                case 'sperre_moeglich':
                    $e[$t[0]] = $wert === 'ja';
                    break;
                case 'konto':
                    $e['konten'][] = $wert;
                    break;
                case 'gesperrt':
                    $e['gesperrt'][] = $wert;
                    break;
                case 'fehler':
                    $e['fehler'][] = ['zeile' => (int) $wert, 'code' => (string) ($t[2] ?? '')];
                    break;
            }
        }
        return $e;
    }

    /** null = Zugänge lassen sich hier einrichten, sonst der Grund. */
    public static function verfuegbar(): ?string
    {
        if (Config::isDemo()) {
            return 'Demo-Modus: am Broker wird nichts verändert.';
        }
        $e = self::ergebnis();
        if ($e === null) {
            return 'Der Broker-Abgleich ist hier nicht eingerichtet. Eigene Zugänge gibt es mit dem mitinstallierten Mosquitto (ab install-debian.sh bzw. update-debian.sh dieser Fassung).';
        }
        if (!$e['basis_muster']) {
            return 'In der ACL des Brokers fehlen die Muster für eigene Zugänge („pattern readwrite %u/#“). /etc/mosquitto/powermanagement.acl wurde von Hand geändert – die neue Fassung liegt als .powermanagement-neu daneben.';
        }
        if (!is_dir(self::verzeichnis() . '/soll') || !is_writable(self::verzeichnis() . '/soll')) {
            return 'storage/mqtt/soll ist nicht beschreibbar.';
        }
        return null;
    }

    /** Warum der gemeinsame Zugang nicht gesperrt werden kann – null = er kann. */
    public static function sperreHindernis(?array $e = null): ?string
    {
        $e ??= self::ergebnis();
        if ($e === null) {
            return 'Der Broker-Abgleich meldet sich nicht.';
        }
        if (!$e['acl_wirksam']) {
            return 'Mosquitto liest nicht die erzeugte ACL (/etc/mosquitto/powermanagement-wirksam.acl) – /etc/mosquitto/conf.d/powermanagement.conf wurde von Hand geändert.';
        }
        if (!$e['sperre_moeglich']) {
            return 'In der ACL-Grundlage fehlt der Block „user shelly“ – der gemeinsame Zugang lässt sich nicht gezielt sperren.';
        }
        return null;
    }

    /**
     * Den Sollzustand für den Helfer schreiben.
     *
     * @return int die Laufnummer (Demo: 0)
     */
    public static function sollSchreiben(bool $entfernenErlaubt = false): int
    {
        if (Config::isDemo()) {
            return 0;
        }
        $dir = self::verzeichnis();
        // Root darf hier nicht schreiben: Datei gehörte dann root, und der
        // Helfer liest sie als App-Benutzer.
        if (function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid()) {
            throw new \RuntimeException('storage/mqtt gehört einem anderen Benutzer – bitte als dessen Besitzer ausführen (z. B. sudo -u www-data php bin/power …).');
        }
        $schloss = @fopen($dir . '/.lock', 'c');
        if ($schloss === false || !flock($schloss, LOCK_EX)) {
            throw new \RuntimeException('storage/mqtt/.lock lässt sich nicht sperren.');
        }
        try {
            $lauf = max(self::sollLauf(), (int) (self::ergebnis()['lauf'] ?? 0)) + 1;
            $jetzt = time();
            $zeilen = [self::KOPF, 'lauf ' . $lauf, 'entfernen-erlaubt ' . ($entfernenErlaubt ? 'ja' : 'nein')];
            foreach (Database::select('SELECT benutzer, generation, kennwort_hash, zustand, sperre_bis FROM mqtt_zugaenge ORDER BY benutzer') as $z) {
                $generation = (int) $z['generation'];
                if (self::namensFehler((string) $z['benutzer'], $generation) !== null || preg_match(self::HASH_MUSTER, (string) $z['kennwort_hash']) !== 1) {
                    throw new \RuntimeException('Ungültiger Zugang in der Datenbank: ' . $z['benutzer']);
                }
                $sperre = '-';
                if ($z['zustand'] === 'eigen') {
                    $sperre = 'dauerhaft';
                } elseif ($z['zustand'] === 'pruefung' && $z['sperre_bis'] !== null) {
                    $bis = strtotime((string) $z['sperre_bis']);
                    if ($bis !== false && $bis > $jetzt) {
                        $sperre = 'bis:' . min($bis, $jetzt + 600);
                    }
                }
                $zeilen[] = 'konto ' . $z['benutzer'] . ' ' . $generation . ' ' . $z['kennwort_hash'] . ' ' . $sperre;
            }
            $tmp = @tempnam($dir, '.soll-');
            if ($tmp === false || @file_put_contents($tmp, implode("\n", $zeilen) . "\n") === false) {
                throw new \RuntimeException('storage/mqtt ist nicht beschreibbar.');
            }
            chmod($tmp, 0640);
            if (!@rename($tmp, $dir . '/soll/zugaenge.txt')) {
                @unlink($tmp);
                throw new \RuntimeException('storage/mqtt/soll ist nicht beschreibbar.');
            }
            return $lauf;
        } finally {
            flock($schloss, LOCK_UN);
            fclose($schloss);
        }
    }

    private static function sollLauf(): int
    {
        $text = @file_get_contents(self::verzeichnis() . '/soll/zugaenge.txt', false, null, 0, 200);
        return is_string($text) && preg_match('/^lauf (\d{1,12})$/m', $text, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * Warten, bis der Helfer die Laufnummer verarbeitet hat und die Bedingung gilt.
     *
     * @param callable(array):bool|null $bedingung
     * @return string|null null = übernommen, sonst Meldung
     */
    private static function aufBrokerWarten(int $lauf, ?callable $bedingung = null, float $zeit = 20.0): ?string
    {
        $ende = microtime(true) + $zeit;
        do {
            $e = self::ergebnis();
            if ($e !== null && $e['lauf'] !== null && $e['lauf'] >= $lauf) {
                if ($e['status'] === 'abgelehnt' || $e['status'] === 'fehler') {
                    return 'Der Broker-Abgleich hat abgelehnt: ' . self::fehlerText($e['fehler']);
                }
                if ($bedingung === null || $bedingung($e)) {
                    return null;
                }
            }
            usleep(200_000);
        } while (microtime(true) < $ende);
        return 'Der Broker hat die Änderung nicht rechtzeitig übernommen (Dienst prüfen: journalctl -u powermanagement-mqtt-zugaenge).';
    }

    /** @param list<array{zeile:int,code:string}> $fehler */
    public static function fehlerText(array $fehler): string
    {
        if ($fehler === []) {
            return 'unbekannter Fehler.';
        }
        $teile = [];
        foreach ($fehler as $f) {
            $teile[] = (self::FEHLERTEXTE[$f['code']] ?? $f['code']) . ($f['zeile'] > 0 ? ' (Zeile ' . $f['zeile'] . ')' : '');
        }
        return implode(' ', $teile);
    }

    /** Soll schreiben und warten; null = übernommen. */
    private static function anwenden(?callable $bedingung = null, bool $entfernenErlaubt = false): ?string
    {
        try {
            $lauf = self::sollSchreiben($entfernenErlaubt);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return self::aufBrokerWarten($lauf, $bedingung);
    }

    /**
     * Mit dem neuen Zugang anmelden und nachsehen, dass er genau seinen
     * eigenen Baum darf – nicht weniger (ACL-Muster fehlen) und nicht mehr.
     */
    private static function kontoprobe(string $benutzer, string $kennwort, string $praefix): ?string
    {
        $n = bin2hex(random_bytes(6));
        $geraet = null;
        for ($versuch = 0; $versuch < 5 && $geraet === null; $versuch++) {
            try {
                $geraet = new Mqtt();
                $geraet->verbinden((string) Config::get('mqtt.host', '127.0.0.1'), (int) Config::get('mqtt.port', 1883), $benutzer, $kennwort, 'pm-probe-' . $n);
            } catch (\RuntimeException) {
                $geraet = null;
                usleep(600_000);
            }
        }
        if ($geraet === null) {
            return 'Der Broker nimmt den neuen Zugang nicht an.';
        }
        $beobachter = null;
        try {
            $beobachter = Mqtt::ausKonfiguration('pm-probe-b' . $n);
            $eigenA = $praefix . '/pm-probe/' . $n . '/a';
            $eigenB = $praefix . '/pm-probe/' . $n . '/b';
            $fremd = 'pm/probe/' . $n;
            $beobachter->abonnieren([$eigenA, $fremd]);
            $geraet->abonnieren([$eigenB]);
            $geraet->veroeffentlichen($eigenA, 'probe', 1);
            $geraet->veroeffentlichen($fremd, 'probe', 1);
            $beobachter->veroeffentlichen($eigenB, 'probe', 1);
            $gesehen = array_column($beobachter->lauschen(1.5), 'thema');
            $zurueck = array_column($geraet->lauschen(0.5, static fn (string $t): bool => $t === $eigenB), 'thema');
            if (!in_array($eigenA, $gesehen, true) || !in_array($eigenB, $zurueck, true)) {
                return 'Am Broker fehlen die ACL-Muster für eigene Zugänge – der neue Zugang darf nicht einmal seinen eigenen Bereich.';
            }
            if (in_array($fremd, $gesehen, true)) {
                return 'Der neue Zugang darf mehr als seinen eigenen Bereich – ACL des Brokers prüfen.';
            }
            return null;
        } catch (\RuntimeException $e) {
            return 'Prüfung des neuen Zugangs fehlgeschlagen: ' . $e->getMessage();
        } finally {
            $geraet->trennen();
            $beobachter?->trennen();
        }
    }

    /** Hash eintragen, an den Broker geben, mit dem Kennwort anmelden. */
    private static function kontoAnwenden(array $zugang, string $kennwort): ?string
    {
        $benutzer = (string) $zugang['benutzer'];
        $fehler = self::anwenden(static fn (array $e): bool => in_array($benutzer, $e['konten'], true));
        return $fehler ?? self::kontoprobe($benutzer, $kennwort, (string) $zugang['praefix']);
    }

    // ------------------------------------------------------------ Gerät

    private static function verbindung(): Mqtt
    {
        return Mqtt::ausKonfiguration('pm-konto-' . bin2hex(random_bytes(4)));
    }

    private static function rpc(Mqtt $mqtt, string $praefix, string $methode, array $params = [], int $qos = 1, float $zeit = 5.0): array
    {
        return ShellyRpc::aufrufen($mqtt, $praefix, ShellyRpc::quelle($praefix, 'konto', true), $methode, $params, $zeit, $qos);
    }

    private static function antwortFehlt(string $praefix, array $antwort): string
    {
        return $antwort['message'] === 'keine Antwort'
            ? $praefix . ' antwortet nicht. Ist der Shelly online und das Präfix richtig?'
            : $praefix . ': ' . $antwort['message'];
    }

    /**
     * Gen2 vor dem Umstellen prüfen – nur lesend.
     *
     * Antwortet das Gerät an einen Absender in seinem eigenen Baum? Stimmt
     * das Präfix? Wartet es schon auf einen Neustart (dann würde der nächste
     * Neustart mehr ändern als den Zugang)?
     *
     * @return array{ok:bool,message:string,info?:array<string,string>}
     */
    public static function vorpruefen(string $praefix): array
    {
        $praefix = trim($praefix, '/');
        if (Config::isDemo()) {
            return ['ok' => false, 'message' => 'Demo-Modus: es wird kein Gerät gefragt.'];
        }
        try {
            $mqtt = self::verbindung();
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        try {
            return self::vorpruefenMit($mqtt, $praefix);
        } finally {
            $mqtt->trennen();
        }
    }

    private static function vorpruefenMit(Mqtt $mqtt, string $praefix): array
    {
        $info = self::rpc($mqtt, $praefix, 'Shelly.GetDeviceInfo');
        if (!$info['ok']) {
            return ['ok' => false, 'message' => self::antwortFehlt($praefix, $info)];
        }
        if ((int) ($info['result']['gen'] ?? 0) < 2) {
            return ['ok' => false, 'message' => $praefix . ' meldet sich nicht als Shelly der Generation 2 oder neuer.'];
        }
        $konfig = self::rpc($mqtt, $praefix, 'MQTT.GetConfig');
        if (!$konfig['ok']) {
            return ['ok' => false, 'message' => self::antwortFehlt($praefix, $konfig)];
        }
        $k = $konfig['result'];
        $themenPraefix = (string) ($k['topic_prefix'] ?? '');
        if ($themenPraefix === '') {
            $themenPraefix = (string) ($info['result']['id'] ?? '');
        }
        if ($themenPraefix !== $praefix) {
            return ['ok' => false, 'message' => $praefix . ': das Gerät nennt ein anderes MQTT-Präfix.'];
        }
        if (preg_match('/[+#]/', (string) ($k['client_id'] ?? '')) === 1) {
            return ['ok' => false, 'message' => $praefix . ': die Client-ID enthält + oder # – Mosquitto wendet dann keine Muster an.'];
        }
        $status = self::rpc($mqtt, $praefix, 'Sys.GetStatus');
        if (!$status['ok']) {
            return ['ok' => false, 'message' => self::antwortFehlt($praefix, $status)];
        }
        if (!empty($status['result']['restart_required'])) {
            return ['ok' => false, 'message' => $praefix . ' wartet schon auf einen Neustart (geänderte Einstellungen). Erst neu starten, dann umstellen.'];
        }
        return ['ok' => true, 'message' => $praefix . ' antwortet und lässt sich umstellen.', 'info' => [
            'modell'    => mb_substr((string) ($info['result']['model'] ?? ''), 0, 64),
            'firmware'  => mb_substr((string) ($info['result']['ver'] ?? ''), 0, 64),
            'benutzer'  => mb_substr((string) ($k['user'] ?? ''), 0, 80),
            'client_id' => mb_substr((string) ($k['client_id'] ?? ''), 0, 80),
        ]];
    }

    /** Hängt das Gerät am eigenen Zugang? Nur aussagekräftig, solange der gemeinsame gesperrt ist. */
    private static function nachweis(Mqtt $mqtt, array $zugang): bool
    {
        $praefix = (string) $zugang['praefix'];
        if ((int) $zugang['generation'] === 2) {
            for ($versuch = 0; $versuch < 2; $versuch++) {
                if (self::rpc($mqtt, $praefix, 'Sys.GetStatus', [], 1, 4.0)['ok']) {
                    return true;
                }
            }
            return false;
        }
        // Gen1: auf „update“ schickt der Shelly seinen Zustand. Kommt etwas
        // an, das nicht gespeichert (retained) ist, sendet er gerade.
        $mqtt->abonnieren([$praefix . '/#']);
        $treffer = false;
        $pruefer = static function (string $t, string $_n, bool $retain) use (&$treffer, $praefix): bool {
            return $treffer = self::gen1Lebenszeichen($t, $retain, $praefix);
        };
        // „update“ nach 0, 5 und 12 Sekunden, insgesamt 20 Sekunden warten.
        foreach ([5.0, 7.0, 8.0] as $warten) {
            $mqtt->veroeffentlichen($praefix . '/command', 'update', 1);
            $mqtt->lauschen($warten, $pruefer);
            if ($treffer) {
                return true;
            }
        }
        return false;
    }

    private static function gen1Lebenszeichen(string $thema, bool $retain, string $praefix): bool
    {
        if ($retain || !str_starts_with($thema, $praefix . '/') || str_ends_with($thema, '/command')) {
            return false;
        }
        $rest = substr($thema, strlen($praefix) + 1);
        return !str_starts_with($rest, 'pm-') && !str_contains($rest, '/command/');
    }

    // ------------------------------------------------------------ Abläufe

    /**
     * Eigenen Zugang für ein Gerät einrichten.
     *
     * @return array{ok:bool,message:string,id?:int,benutzer?:string,kennwort?:string}
     */
    public static function einrichten(string $praefix, int $generation): array
    {
        $praefix = trim($praefix, '/');
        if (($grund = self::verfuegbar()) !== null) {
            return ['ok' => false, 'message' => $grund];
        }
        $benutzer = self::benutzerAusPraefix($praefix, $generation);
        if ($benutzer === null) {
            return ['ok' => false, 'message' => 'Gen1-Präfixe haben die Form shellies/<ID>.'];
        }
        if (($fehler = self::benutzerFehler($benutzer, $generation)) !== null) {
            return ['ok' => false, 'message' => $praefix . ': ' . $fehler];
        }
        $mqtt = null;
        try {
            if ($generation === 2) {
                $mqtt = self::verbindung();
                $vorab = self::vorpruefenMit($mqtt, $praefix);
                if (!$vorab['ok']) {
                    return ['ok' => false, 'message' => $vorab['message']];
                }
            }
            $kennwort = self::kennwortErzeugen();
            $jetzt = gmdate('c');
            try {
                $id = Database::insert('mqtt_zugaenge', [
                    'benutzer'      => $benutzer,
                    'praefix'       => $praefix,
                    'geraet'        => self::geraetName($praefix),
                    'generation'    => $generation,
                    'kennwort_hash' => self::hash($kennwort),
                    'zustand'       => 'angelegt',
                    'kennwort_am'   => $jetzt,
                    'meldung'       => '',
                    'created_by'    => Auth::id(),
                    'created_at'    => $jetzt,
                    'updated_at'    => $jetzt,
                ]);
            } catch (\PDOException) {
                return ['ok' => false, 'message' => $praefix . ': ' . self::FEHLERTEXTE['doppelt']];
            }
            $zugang = (array) self::find($id);
            $fehler = self::kontoAnwenden($zugang, $kennwort);
            if ($fehler !== null) {
                Database::delete('mqtt_zugaenge', $id);
                self::anwenden();
                return ['ok' => false, 'message' => $praefix . ': ' . $fehler . ' Es wurde nichts eingerichtet.'];
            }
            Audit::log('mqtt_zugang.einrichten', 'mqtt_zugang', (string) $id, ['benutzer' => $benutzer, 'generation' => $generation]);
            if ($generation === 1) {
                Database::update('mqtt_zugaenge', $id, ['meldung' => 'Benutzer und Kennwort am Gerät eintragen, dann „Umstellung prüfen“.', 'updated_at' => gmdate('c')]);
                return ['ok' => true, 'id' => $id, 'benutzer' => $benutzer, 'kennwort' => $kennwort,
                    'message' => 'Eigener Zugang für ' . $praefix . ' angelegt.'];
            }
            return self::uebertragenMit($mqtt, $zugang, $kennwort);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $praefix . ': ' . $e->getMessage()];
        } finally {
            $mqtt?->trennen();
        }
    }

    /** Gen2: Zugang ans Gerät geben (MQTT.SetConfig, ohne Neustart). */
    private static function uebertragenMit(Mqtt $mqtt, array $zugang, string $kennwort): array
    {
        $id = (int) $zugang['id'];
        $praefix = (string) $zugang['praefix'];
        $benutzer = (string) $zugang['benutzer'];
        // QoS 0: ist das Gerät gerade weg, soll das Kennwort nicht beim
        // Broker liegen bleiben.
        $setzen = self::rpc($mqtt, $praefix, 'MQTT.SetConfig', ['config' => ['user' => $benutzer, 'pass' => $kennwort]], 0);
        $gelesen = $setzen['ok'] ? self::rpc($mqtt, $praefix, 'MQTT.GetConfig') : $setzen;
        if (!$setzen['ok'] || !$gelesen['ok'] || ($gelesen['result']['user'] ?? null) !== $benutzer) {
            $grund = !$setzen['ok'] ? self::antwortFehlt($praefix, $setzen) : 'das Gerät hat den Benutzer nicht übernommen.';
            Database::update('mqtt_zugaenge', $id, ['zustand' => 'angelegt', 'meldung' => mb_substr('Übertragen fehlgeschlagen – erneut übertragen.', 0, 255), 'updated_at' => gmdate('c')]);
            return ['ok' => false, 'id' => $id, 'message' => 'Zugang am Broker angelegt, aber nicht übertragen: ' . $grund . ' „Übertragen“ versucht es erneut.'];
        }
        $neustart = ($setzen['result']['restart_required'] ?? true) !== false;
        Database::update('mqtt_zugaenge', $id, [
            'zustand'         => 'uebertragen',
            'uebertragen_am'  => gmdate('c'),
            'neustart_noetig' => $neustart ? 1 : 0,
            'meldung'         => $neustart ? 'Wirksam ab dem nächsten Neustart des Geräts.' : '',
            'updated_at'      => gmdate('c'),
        ]);
        Audit::log('mqtt_zugang.uebertragen', 'mqtt_zugang', (string) $id, ['benutzer' => $benutzer, 'generation' => 2]);
        return ['ok' => true, 'id' => $id, 'message' => $neustart
            ? $praefix . ': Zugang übertragen. Der Shelly nutzt ihn ab seinem nächsten Neustart (Weboberfläche oder Strom aus/ein) – bis dahin bleibt er beim gemeinsamen Zugang, beide gelten. Danach „Umstellung prüfen“.'
            : $praefix . ': Zugang übertragen und sofort wirksam. Jetzt „Umstellung prüfen“.'];
    }

    /** Gen2: Zugang (erneut) übertragen – mit neuem Kennwort. */
    public static function uebertragen(int $id): array
    {
        $zugang = self::find($id);
        if ($zugang === null) {
            return ['ok' => false, 'message' => 'Diesen Zugang gibt es nicht.'];
        }
        if ((int) $zugang['generation'] !== 2 || !in_array($zugang['zustand'], ['angelegt', 'uebertragen'], true)) {
            return ['ok' => false, 'message' => 'Übertragen geht nur bei Gen2-Zugängen, die noch nicht bestätigt sind.'];
        }
        if (($grund = self::verfuegbar()) !== null) {
            return ['ok' => false, 'message' => $grund];
        }
        $praefix = (string) $zugang['praefix'];
        try {
            $mqtt = self::verbindung();
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        try {
            $status = self::rpc($mqtt, $praefix, 'Sys.GetStatus');
            if (!$status['ok']) {
                return ['ok' => false, 'message' => self::antwortFehlt($praefix, $status)];
            }
            // Schon mit dem übertragenen Zugang neu gestartet? Dann würde ein
            // neues Kennwort das Gerät abhängen.
            if ($zugang['zustand'] === 'uebertragen' && self::seitUebertragungNeuGestartet($zugang, $status['result'])) {
                return ['ok' => false, 'message' => $praefix . ' ist seit der Übertragung neu gestartet – jetzt „Umstellung prüfen“.'];
            }
            $kennwort = self::kennwortErzeugen();
            Database::update('mqtt_zugaenge', $id, ['kennwort_hash' => self::hash($kennwort), 'kennwort_am' => gmdate('c'), 'updated_at' => gmdate('c')]);
            $fehler = self::kontoAnwenden($zugang, $kennwort);
            if ($fehler !== null) {
                return ['ok' => false, 'message' => $praefix . ': ' . $fehler];
            }
            return self::uebertragenMit($mqtt, $zugang, $kennwort);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $praefix . ': ' . $e->getMessage()];
        } finally {
            $mqtt->trennen();
        }
    }

    private static function seitUebertragungNeuGestartet(array $zugang, array $status): bool
    {
        $seit = strtotime((string) ($zugang['uebertragen_am'] ?? ''));
        $laufzeit = $status['uptime'] ?? null;
        return $seit !== false && is_numeric($laufzeit) && empty($status['restart_required'])
            && (float) $laufzeit < time() - $seit;
    }

    /**
     * Umstellung prüfen und bestätigen.
     *
     * Eigen: nur nachsehen, ob das Gerät über den eigenen Zugang antwortet.
     * Sonst: gemeinsamen Zugang kurz auf den Baum des Geräts sperren und
     * nachweisen, dass es trotzdem erreichbar ist – dann bleibt die Sperre.
     *
     * @return array{ok:bool,message:string,neustart?:bool}
     */
    public static function pruefen(int $id, bool $erzwingen = false): array
    {
        $zugang = self::find($id);
        if ($zugang === null) {
            return ['ok' => false, 'message' => 'Diesen Zugang gibt es nicht.'];
        }
        if (($grund = self::verfuegbar()) !== null) {
            return ['ok' => false, 'message' => $grund];
        }
        $praefix = (string) $zugang['praefix'];
        $generation = (int) $zugang['generation'];
        if ($zugang['zustand'] === 'pruefung') {
            return ['ok' => false, 'message' => $praefix . ' wird gerade geprüft.'];
        }
        if ($zugang['zustand'] === 'angelegt' && $generation === 2) {
            return ['ok' => false, 'message' => $praefix . ': erst übertragen.'];
        }
        try {
            $mqtt = self::verbindung();
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        try {
            if ($zugang['zustand'] === 'eigen') {
                return self::eigenPruefen($mqtt, $zugang);
            }
            if (($hindernis = self::sperreHindernis()) !== null) {
                return ['ok' => false, 'message' => $hindernis];
            }
            if ($generation === 2) {
                $konfig = self::rpc($mqtt, $praefix, 'MQTT.GetConfig');
                if (!$konfig['ok']) {
                    return ['ok' => false, 'message' => self::antwortFehlt($praefix, $konfig)];
                }
                if (($konfig['result']['user'] ?? null) !== $zugang['benutzer']) {
                    return ['ok' => false, 'message' => $praefix . ': am Gerät ist ein anderer Benutzer eingetragen – „Übertragen“.'];
                }
                $status = self::rpc($mqtt, $praefix, 'Sys.GetStatus');
                if (!$status['ok']) {
                    return ['ok' => false, 'message' => self::antwortFehlt($praefix, $status)];
                }
                // Ohne Neustart gilt der übertragene Zugang nur, wenn das Gerät
                // ihn sofort übernommen hat (restart_required false).
                if (!$erzwingen && (int) $zugang['neustart_noetig'] === 1 && !self::seitUebertragungNeuGestartet($zugang, $status['result'])) {
                    return ['ok' => false, 'neustart' => true, 'message' => $praefix . ' ist seit der Übertragung nicht neu gestartet und nutzt noch den gemeinsamen Zugang. Erst neu starten (Weboberfläche oder Strom aus/ein), dann prüfen.'];
                }
            }
            return self::sperrPruefung($mqtt, $zugang);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $praefix . ': ' . $e->getMessage()];
        } finally {
            $mqtt->trennen();
        }
    }

    private static function eigenPruefen(Mqtt $mqtt, array $zugang): array
    {
        $praefix = (string) $zugang['praefix'];
        $felder = ['updated_at' => gmdate('c')];
        $ok = self::nachweis($mqtt, $zugang);
        if ($ok && (int) $zugang['generation'] === 2 && (int) $zugang['neustart_noetig'] === 1) {
            $status = self::rpc($mqtt, $praefix, 'Sys.GetStatus');
            $seit = strtotime((string) $zugang['kennwort_am']);
            if ($status['ok'] && $seit !== false && is_numeric($status['result']['uptime'] ?? null) && (float) $status['result']['uptime'] < time() - $seit) {
                $felder['neustart_noetig'] = 0;
            }
        }
        $felder['meldung'] = $ok ? '' : 'Antwortet nicht über den eigenen Zugang.';
        Database::update('mqtt_zugaenge', (int) $zugang['id'], $felder);
        return $ok
            ? ['ok' => true, 'message' => $praefix . ' nutzt den eigenen Zugang.']
            : ['ok' => false, 'message' => $praefix . ' antwortet nicht – offline, oder am Gerät steht ein anderes Kennwort.'];
    }

    private static function sperrPruefung(Mqtt $mqtt, array $zugang): array
    {
        $id = (int) $zugang['id'];
        $praefix = (string) $zugang['praefix'];
        $generation = (int) $zugang['generation'];
        $zurueck = $generation === 2 ? 'uebertragen' : 'angelegt';
        Database::update('mqtt_zugaenge', $id, ['zustand' => 'pruefung', 'sperre_bis' => gmdate('c', time() + self::PRUEFUNG_SEKUNDEN[$generation]), 'updated_at' => gmdate('c')]);
        $gesperrt = static fn (array $e): bool => in_array($praefix, $e['gesperrt'], true);
        $fehler = self::anwenden($gesperrt);
        $ok = false;
        if ($fehler === null) {
            usleep(800_000);
            $ok = self::nachweis($mqtt, $zugang);
        }
        if (!$ok) {
            Database::update('mqtt_zugaenge', $id, ['zustand' => $zurueck, 'sperre_bis' => null,
                'meldung' => $fehler === null ? 'Prüfung fehlgeschlagen: antwortet nur über den gemeinsamen Zugang.' : 'Prüfung abgebrochen.', 'updated_at' => gmdate('c')]);
            self::anwenden(static fn (array $e): bool => !in_array($praefix, $e['gesperrt'], true));
            Audit::log('mqtt_zugang.pruefung_fehlgeschlagen', 'mqtt_zugang', (string) $id, ['benutzer' => $zugang['benutzer'], 'generation' => $generation]);
            return ['ok' => false, 'message' => $fehler !== null ? $praefix . ': ' . $fehler : ($generation === 2
                ? $praefix . ' antwortet nur über den gemeinsamen Zugang – noch nicht umgestellt. Nichts geändert.'
                : $praefix . ' sendet über den eigenen Zugang nichts – Benutzer und Kennwort am Gerät prüfen. Nichts geändert.')];
        }
        Database::update('mqtt_zugaenge', $id, ['zustand' => 'eigen', 'sperre_bis' => null, 'bestaetigt_am' => gmdate('c'),
            'neustart_noetig' => 0, 'meldung' => '', 'updated_at' => gmdate('c')]);
        $fehler = self::anwenden($gesperrt);
        Audit::log('mqtt_zugang.bestaetigt', 'mqtt_zugang', (string) $id, ['benutzer' => $zugang['benutzer'], 'generation' => $generation]);
        return ['ok' => true, 'message' => $praefix . ' nutzt jetzt den eigenen Zugang. Der gemeinsame Zugang hat auf dieses Gerät keinen Zugriff mehr.'
            . ($fehler !== null ? ' Hinweis: ' . $fehler : '')];
    }

    /**
     * Neues Kennwort.
     *
     * Gen1: wird einmal angezeigt; bis es am Gerät steht, ist das Gerät
     * getrennt. Gen2 „eigen“: geht über den eigenen, privaten Kanal ans
     * Gerät und gilt ab dessen nächstem Neustart – Mosquitto trennt das
     * Gerät aber sofort, es ist bis dahin offline.
     *
     * @return array{ok:bool,message:string,benutzer?:string,kennwort?:string}
     */
    public static function kennwortErneuern(int $id): array
    {
        $zugang = self::find($id);
        if ($zugang === null) {
            return ['ok' => false, 'message' => 'Diesen Zugang gibt es nicht.'];
        }
        if (($grund = self::verfuegbar()) !== null) {
            return ['ok' => false, 'message' => $grund];
        }
        $praefix = (string) $zugang['praefix'];
        if ($zugang['zustand'] === 'pruefung') {
            return ['ok' => false, 'message' => $praefix . ' wird gerade geprüft.'];
        }
        if ((int) $zugang['generation'] === 2 && $zugang['zustand'] !== 'eigen') {
            return self::uebertragen($id);
        }
        $kennwort = self::kennwortErzeugen();
        $mqtt = null;
        try {
            if ((int) $zugang['generation'] === 2) {
                $mqtt = self::verbindung();
                $setzen = self::rpc($mqtt, $praefix, 'MQTT.SetConfig', ['config' => ['user' => (string) $zugang['benutzer'], 'pass' => $kennwort]], 0);
                $gelesen = $setzen['ok'] ? self::rpc($mqtt, $praefix, 'MQTT.GetConfig') : $setzen;
                if (!$setzen['ok'] || !$gelesen['ok'] || ($gelesen['result']['user'] ?? null) !== $zugang['benutzer']) {
                    return ['ok' => false, 'message' => $praefix . ': neues Kennwort nicht übertragen – ' . (!$setzen['ok'] ? self::antwortFehlt($praefix, $setzen) : 'Gerät hat es nicht übernommen.') . ' Nichts geändert.'];
                }
            }
            Database::update('mqtt_zugaenge', $id, ['kennwort_hash' => self::hash($kennwort), 'kennwort_am' => gmdate('c'),
                'neustart_noetig' => (int) $zugang['generation'] === 2 ? 1 : 0,
                'meldung' => (int) $zugang['generation'] === 2 ? 'Neues Kennwort gilt ab dem nächsten Neustart – bis dahin getrennt.' : 'Neues Kennwort am Gerät eintragen.',
                'updated_at' => gmdate('c')]);
            $fehler = self::kontoAnwenden($zugang, $kennwort);
            Audit::log('mqtt_zugang.kennwort', 'mqtt_zugang', (string) $id, ['benutzer' => $zugang['benutzer'], 'generation' => (int) $zugang['generation']]);
            if ($fehler !== null) {
                return ['ok' => false, 'message' => $praefix . ': ' . $fehler];
            }
            if ((int) $zugang['generation'] === 1) {
                return ['ok' => true, 'benutzer' => (string) $zugang['benutzer'], 'kennwort' => $kennwort, 'message' => 'Neues Kennwort für ' . $praefix . '.'];
            }
            return ['ok' => true, 'message' => $praefix . ': neues Kennwort übertragen. Der Shelly ist bis zu seinem nächsten Neustart getrennt – jetzt neu starten.'];
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $praefix . ': ' . $e->getMessage()];
        } finally {
            $mqtt?->trennen();
        }
    }

    /** Zugang löschen – samt Sperre des gemeinsamen Zugangs. */
    public static function entfernen(int $id): array
    {
        $zugang = self::find($id);
        if ($zugang === null) {
            return ['ok' => false, 'message' => 'Diesen Zugang gibt es nicht.'];
        }
        if (($grund = self::verfuegbar()) !== null) {
            return ['ok' => false, 'message' => $grund];
        }
        $benutzer = (string) $zugang['benutzer'];
        Database::delete('mqtt_zugaenge', $id);
        $fehler = self::anwenden(static fn (array $e): bool => !in_array($benutzer, $e['konten'], true));
        Audit::log('mqtt_zugang.entfernt', 'mqtt_zugang', (string) $id, ['benutzer' => $benutzer, 'generation' => (int) $zugang['generation']]);
        return ['ok' => $fehler === null, 'message' => $fehler !== null
            ? $zugang['praefix'] . ': ' . $fehler
            : $zugang['praefix'] . ': eigener Zugang entfernt. Ein Gerät, das ihn noch nutzt, ist jetzt getrennt – dort wieder den gemeinsamen Zugang eintragen.'];
    }

    /**
     * Nächtliche Wartung: abgebrochene Prüfungen zurücksetzen, Broker und
     * Datenbank vergleichen.
     *
     * @return list<string>
     */
    public static function wartung(): array
    {
        if (Config::isDemo()) {
            return [];
        }
        $meldungen = [];
        $jetzt = time();
        $geaendert = false;
        foreach (Database::select("SELECT id, praefix, generation, sperre_bis FROM mqtt_zugaenge WHERE zustand = 'pruefung'") as $z) {
            $bis = strtotime((string) $z['sperre_bis']);
            if ($bis === false || $bis <= $jetzt) {
                Database::update('mqtt_zugaenge', (int) $z['id'], ['zustand' => (int) $z['generation'] === 2 ? 'uebertragen' : 'angelegt',
                    'sperre_bis' => null, 'meldung' => 'Prüfung abgebrochen.', 'updated_at' => gmdate('c')]);
                $meldungen[] = $z['praefix'] . ': abgebrochene Prüfung zurückgesetzt.';
                $geaendert = true;
            }
        }
        $e = self::ergebnis();
        if ($e === null) {
            if (self::alle() !== []) {
                $meldungen[] = 'MQTT-Zugänge: der Broker-Abgleich meldet sich nicht (storage/mqtt/ist/ergebnis.txt fehlt).';
            }
            return $meldungen;
        }
        if ($geaendert) {
            try {
                self::sollSchreiben();
            } catch (\RuntimeException $ex) {
                $meldungen[] = 'MQTT-Zugänge: ' . $ex->getMessage();
            }
        }
        if ($e['zeit'] < $jetzt - 900) {
            $meldungen[] = 'MQTT-Zugänge: der Broker-Abgleich hat sich seit über 15 Minuten nicht gemeldet (Zeitgeber powermanagement-mqtt-zugaenge.timer prüfen).';
        }
        $hier = array_column(self::alle(), 'benutzer');
        sort($hier);
        $dort = $e['konten'];
        sort($dort);
        if ($hier !== $dort && !$geaendert) {
            $meldungen[] = 'MQTT-Zugänge: Broker und Datenbank weichen ab (' . count($dort) . ' am Broker, ' . count($hier) . ' hier) – bin/power mqtt:zugaenge-schreiben.';
        }
        foreach ($meldungen as $m) {
            Logger::info($m);
        }
        return $meldungen;
    }

    /**
     * Alle bekannten Shellys mit ihrem Zugang: aus den Messdaten (Präfix
     * meldet Node-RED), den Datenquellen und den Zugängen.
     *
     * @return list<array{praefix:string,generation:int,geraet:string,name:string,zuletzt:?int,benutzer:?string,zugang:?array,zustand:string,umstellbar:bool,grund:string}>
     */
    public static function uebersicht(?string &$fehler = null): array
    {
        $zeilen = [];
        $merken = static function (string $praefix, int $generation, string $name = '', ?int $zuletzt = null) use (&$zeilen): void {
            $praefix = trim($praefix, '/');
            if ($praefix === '' || ($generation !== 1 && $generation !== 2)) {
                return;
            }
            $z = $zeilen[$praefix] ?? ['praefix' => $praefix, 'generation' => $generation, 'name' => '', 'zuletzt' => null];
            if ($z['name'] === '' && $name !== '') {
                $z['name'] = $name;
            }
            if ($zuletzt !== null && ($z['zuletzt'] === null || $zuletzt > $z['zuletzt'])) {
                $z['zuletzt'] = $zuletzt;
            }
            $zeilen[$praefix] = $z;
        };
        if (Config::isDemo()) {
            foreach (Demo::geraete() as $g) {
                if ($g['typ'] === 'shelly') {
                    $merken((string) $g['geraet'], 2, (string) $g['name'], $g['zuletzt'] !== null ? (int) $g['zuletzt'] : null);
                }
            }
        } else {
            foreach (Messdaten::geraete($fehler) as $g) {
                $merken((string) ($g['praefix'] ?? ''), (int) ($g['generation'] ?? 0), (string) ($g['name'] ?? ''), $g['zuletzt'] !== null ? (int) $g['zuletzt'] : null);
            }
        }
        foreach (Datenquellen::alle('shelly') as $q) {
            $merken((string) $q['extern_id'], (string) $q['profil'] === 'gen1' ? 1 : 2, (string) $q['name']);
        }
        $zugaenge = [];
        foreach (self::alle() as $z) {
            $zugaenge[$z['praefix']] = $z;
            $merken((string) $z['praefix'], (int) $z['generation']);
        }
        $out = [];
        foreach ($zeilen as $praefix => $z) {
            $praefix = (string) $praefix;
            $zugang = $zugaenge[$praefix] ?? null;
            $generation = $zugang !== null ? (int) $zugang['generation'] : $z['generation'];
            $benutzer = $zugang !== null ? (string) $zugang['benutzer'] : self::benutzerAusPraefix($praefix, $generation);
            $grund = '';
            if ($zugang === null) {
                $code = $benutzer === null ? 'zeichen' : self::namensFehler($benutzer, $generation);
                $grund = $code !== null ? self::FEHLERTEXTE[$code] : '';
            }
            $out[] = [
                'praefix'    => $praefix,
                'generation' => $generation,
                'geraet'     => self::geraetName($praefix),
                'name'       => $z['name'],
                'zuletzt'    => $z['zuletzt'],
                'benutzer'   => $benutzer,
                'zugang'     => $zugang,
                'zustand'    => $zugang !== null ? (string) $zugang['zustand'] : 'gemeinsam',
                'umstellbar' => $zugang === null && $grund === '',
                'grund'      => $grund,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['praefix'], $b['praefix']));
        return $out;
    }
}
