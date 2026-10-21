<?php
declare(strict_types=1);

/**
 * Eventworx ohne Netz: Http::$attrappe spielt den Mandanten, eine falsche
 * Uhr (Eventworx::$uhr/$schlafen) die Zeit.
 *
 * Geprüft werden die Adresse (nur https://<mandant>.eventworx.biz, eine
 * eigene Basis nur exakt aus der Konfiguration), das Token (verschlüsselt,
 * an den Host gebunden, beim Hostwechsel entfernt), die feste Pfadliste
 * (nichts außerhalb geht ins Netz), Form der Anfragen, Aufbereitung von
 * Treffern und Vorgang (dekodiert, Zeiten in UTC, keine Beträge oder
 * Notizen), jede Fehlerantwort mit genau einer Anfrage, das Nachlesen bei
 * 200 + data:null, die Drossel, der Demo-Modus – und dass Token und
 * Suchtext weder in Meldungen noch im Log oder Audit landen.
 *
 * Für den Kundenbereich: Dateien am Vorgang (nur hochgeladene PDFs, eine
 * fremde Datei verwirft die ganze Liste, Download nur aus einer Liste
 * desselben Prozesses, jede Fehlerantwort), Vorgänge verknüpfen (Kennung nur
 * aus der Suche, Kunden-Kennung aus Eventworx mit Bestätigung, abweichende
 * abgelehnt, ein Vorgang und ein Eventworx-Kunde nur einmal, nur Aufträge
 * und Lieferscheine, nur mit „berichte.eventworx“, Lösen) und die Lese-Probe
 * (eigene Pfadliste, Ausgabe ohne Inhalte, ACCESS_DENIED nur für den
 * Schritt, Abbruch bei HTML und 401, Budget).
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Kunden\KundenService;
use App\Messung\Http;
use App\Services\BenutzerService;
use App\Services\Eventworx;
use App\Services\EventworxFehler;
use App\Services\EventworxProbe;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Settings;
use App\Support\Zeit;

$kennung = 'pm-ewx-' . bin2hex(random_bytes(3));
$token = 'tok-' . $kennung . '-' . bin2hex(random_bytes(8));
$host = $kennung . '.eventworx.biz';
$suchtext = 'Suche-' . $kennung;
$schluessel = [Eventworx::EINSTELLUNG_HOST, Eventworx::EINSTELLUNG_TOKEN_HOST, Settings::GEHEIM_EVENTWORX_TOKEN];
$vorher = [];
foreach ($schluessel as $s) {
    $vorher[$s] = Settings::get($s);
}
$konfigVorher = ['app.demo' => Config::get('app.demo'), 'eventworx.basis' => Config::get('eventworx.basis'), 'app.timezone' => Config::get('app.timezone')];
$logDatei = APP_ROOT . '/storage/logs/app.log';
clearstatcache();
$logAb = is_file($logDatei) ? (int) filesize($logDatei) : 0;
$benutzer = null;
$ohneEwx = null;
$rolleOhne = null;
$testKunden = [];

// Falsche Uhr: 1000 s in der Vergangenheit, damit die Sperrdatei echten
// Anfragen danach nicht im Weg steht.
$t = microtime(true) - 1000.0;
$geschlafen = [];
$anfragen = [];
$antworten = [];
$meldungen = [];
$methoden = [];

Http::$attrappe = static function (string $methode, string $url, array $optionen) use (&$anfragen, &$antworten, &$t, &$methoden): array {
    $anfragen[] = ['methode' => $methode, 'url' => $url, 'optionen' => $optionen, 'zeit' => $t];
    $methoden[$methode] = true;
    $antwort = array_shift($antworten);
    return $antwort ?? ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => 'keine Antwort vorbereitet'];
};
$json = static fn (int $status, mixed $daten): array => ['ok' => true, 'status' => $status, 'rumpf' => (string) json_encode($daten), 'fehler' => ''];
$roh = static fn (int $status, string $rumpf): array => ['ok' => true, 'status' => $status, 'rumpf' => $rumpf, 'fehler' => ''];
/** Den Fehler einer Abfrage holen (und für die Geheimnis-Prüfung merken). */
$fehler = static function (callable $abfrage) use (&$meldungen): ?EventworxFehler {
    try {
        $abfrage();
    } catch (EventworxFehler $e) {
        $meldungen[] = $e->getMessage();
        return $e;
    }
    return null;
};

try {
    Config::set('app.demo', false);
    Config::set('eventworx.basis', '');
    Config::set('app.timezone', 'Europe/Berlin');
    Eventworx::$uhr = static function () use (&$t): float {
        return $t;
    };
    Eventworx::$schlafen = static function (float $s) use (&$t, &$geschlafen): void {
        $geschlafen[] = $s;
        $t += $s;
    };
    $benutzer = BenutzerService::anlegen($kennung . '-admin', 'Pruefung-' . bin2hex(random_bytes(6)), 'admin', false);
    alsBenutzer($benutzer);
    foreach ($schluessel as $s) {
        Settings::forget($s);
    }

    // ------------------------------------------------ Adresse
    foreach (['firma', 'Firma', 'firma.eventworx.biz', ' FIRMA.eventworx.biz ', 'https://firma.eventworx.biz', 'https://firma.eventworx.biz/',
        'https://firma.eventworx.biz:443/x', 'https://firma.eventworx.biz/eventworx/#job/edit/order/abc', 'firma.eventworx.biz/eventworx/#job/edit/order/abc', 'firma/irgendwas'] as $eingabe) {
        gleich($fehler(static fn () => Eventworx::host($eingabe)) === null ? Eventworx::host($eingabe) : null, 'firma.eventworx.biz', 'Adresse „' . $eingabe . '“');
    }
    foreach (['', 'http://firma.eventworx.biz', 'https://firma.eventworx.biz:8443', 'firma.eventworx.biz:8443', 'https://user@firma.eventworx.biz', 'user@firma',
        'https://u:p@firma.eventworx.biz', 'firma.eventworx.biz.evil.com', 'https://firma.eventworx.biz.evil.com/', 'a..eventworx.biz', '-a.eventworx.biz',
        'a-.eventworx.biz', 'evil.com', 'https://evil.com/#firma.eventworx.biz', 'https://firma.eventworx.biz\\@evil.com', 'eventworx.biz', 'a.b.eventworx.biz',
        'firma .eventworx.biz', "firma\n.eventworx.biz", 'ftp://firma.eventworx.biz', 'https://' . str_repeat('a', 64) . '.eventworx.biz'] as $eingabe) {
        pruefe($fehler(static fn () => Eventworx::host($eingabe)) !== null, 'abgelehnt: „' . $eingabe . '“');
    }
    // Eigene Basis nur exakt so wie in der Konfiguration
    Config::set('eventworx.basis', 'http://127.0.0.1:9');
    gleich(Eventworx::host('http://127.0.0.1:9'), '127.0.0.1:9', 'eventworx.basis genau wie konfiguriert');
    gleich(Eventworx::host('http://127.0.0.1:9/'), '127.0.0.1:9', 'eventworx.basis mit Schrägstrich am Ende');
    foreach (['http://127.0.0.1:9/backend', 'http://127.0.0.1', '127.0.0.1:9', 'https://127.0.0.1:9', 'http://127.0.0.1:90', 'HTTP://127.0.0.1:9'] as $eingabe) {
        pruefe($fehler(static fn () => Eventworx::host($eingabe)) !== null, 'nicht ganz die Basis: „' . $eingabe . '“');
    }
    Settings::put(Eventworx::EINSTELLUNG_HOST, '127.0.0.1:9');
    gleich([Eventworx::basis(), Eventworx::zustand()['adresse']], ['http://127.0.0.1:9', 'http://127.0.0.1:9'], 'Basis und Formularwert der eigenen Installation');
    Config::set('eventworx.basis', '');
    pruefe($fehler(static fn () => Eventworx::host('http://127.0.0.1:9')) !== null && Eventworx::basis() === '', 'ohne eventworx.basis gilt die Adresse nicht mehr');
    Settings::forget(Eventworx::EINSTELLUNG_HOST);

    // ------------------------------------------------ Token
    pruefe(!Eventworx::eingerichtet() && !Eventworx::verbindungTesten()['ok'], 'ohne Einrichtung kein Verbindungstest');
    $erg = Eventworx::einstellungenSpeichern('https://' . $host . '/eventworx/#job/edit/order/abc', $token);
    pruefe($erg['ok'] && str_contains($erg['message'], $host), 'Adresse und Token gespeichert: ' . $erg['message']);
    $abgelegt = (string) Settings::get(Settings::GEHEIM_EVENTWORX_TOKEN, '');
    pruefe($abgelegt !== '' && !str_contains($abgelegt, $token), 'Token verschlüsselt abgelegt');
    gleich(Crypto::decrypt($abgelegt), $token, 'Token entschlüsselbar');
    gleich([Settings::get(Eventworx::EINSTELLUNG_HOST), Settings::get(Eventworx::EINSTELLUNG_TOKEN_HOST)], [$host, $host], 'Host und token_host');
    pruefe(Eventworx::eingerichtet() && Eventworx::zustand()['eingerichtet'], 'eingerichtet');
    pruefe(!str_contains((string) json_encode(Eventworx::zustand()), $token), 'Zustand für die Seite ohne Token');
    pruefe(Eventworx::einstellungenSpeichern($host, '')['ok'] && Crypto::decrypt((string) Settings::get(Settings::GEHEIM_EVENTWORX_TOKEN)) === $token, 'leeres Token behält das alte');
    foreach (['kurz', 'mit leerzeichen drin', "zeile\r\nX-Eingeschleust: 1", str_repeat('a', 513)] as $falsch) {
        pruefe(!Eventworx::einstellungenSpeichern($host, $falsch)['ok'], 'Token abgelehnt: ' . json_encode($falsch));
    }
    pruefe(!Eventworx::einstellungenSpeichern('evil.com', $token . 'x')['ok'] && Settings::get(Eventworx::EINSTELLUNG_HOST) === $host
        && Crypto::decrypt((string) Settings::get(Settings::GEHEIM_EVENTWORX_TOKEN)) === $token, 'falsche Adresse ändert nichts');
    $erg = Eventworx::einstellungenSpeichern($kennung . '-neu', '');
    pruefe($erg['ok'] && !empty($erg['warnung']) && str_contains($erg['message'], 'API-Token neu eingeben'), 'Hostwechsel ohne Token: ' . $erg['message']);
    pruefe(!Settings::geheimnisHinterlegt(Settings::GEHEIM_EVENTWORX_TOKEN) && Settings::get(Eventworx::EINSTELLUNG_TOKEN_HOST) === null
        && Settings::get(Eventworx::EINSTELLUNG_HOST) === $kennung . '-neu.eventworx.biz' && !Eventworx::eingerichtet(), 'Hostwechsel ohne Token entfernt es');
    Eventworx::einstellungenSpeichern($host, $token);
    pruefe(Eventworx::einstellungenSpeichern($host, '-')['ok'] && !Settings::geheimnisHinterlegt(Settings::GEHEIM_EVENTWORX_TOKEN)
        && Settings::get(Eventworx::EINSTELLUNG_TOKEN_HOST) === null, '„-“ entfernt Token und token_host');
    Eventworx::einstellungenSpeichern($host, $token);
    pruefe(Eventworx::einstellungenSpeichern('', '')['ok'] && Settings::get(Eventworx::EINSTELLUNG_HOST) === null
        && !Settings::geheimnisHinterlegt(Settings::GEHEIM_EVENTWORX_TOKEN), 'leere Adresse entfernt die Anbindung');
    $audit = array_column(Database::select("SELECT details FROM audit_log WHERE action = 'einstellungen.eventworx' AND username = ?", [$kennung . '-admin']), 'details');
    pruefe(count($audit) >= 7 && in_array(json_encode(['host' => $host, 'token' => 'gesetzt']), $audit, true)
        && in_array(json_encode(['host' => $kennung . '-neu.eventworx.biz', 'token' => 'entfernt']), $audit, true), 'Audit mit Host und Token-Stand');

    Eventworx::einstellungenSpeichern($host, $token);
    // token_host passt nicht (oder die Adresse wurde an der Oberfläche vorbei geändert) → nichts geht hinaus
    Settings::put(Eventworx::EINSTELLUNG_TOKEN_HOST, 'anderer.eventworx.biz');
    $anfragen = [];
    $e = $fehler(static fn () => Eventworx::suchen('AU-1042'));
    pruefe($e !== null && str_contains($e->getMessage(), 'nicht eingerichtet') && $anfragen === [], 'token_host ≠ Host → keine Anfrage');
    pruefe(!Eventworx::verbindungTesten()['ok'] && $anfragen === [], 'token_host ≠ Host → kein Verbindungstest');
    Settings::put(Eventworx::EINSTELLUNG_HOST, 'evil.com');
    Settings::put(Eventworx::EINSTELLUNG_TOKEN_HOST, 'evil.com');
    pruefe($fehler(static fn () => Eventworx::suchen('AU-1042')) !== null && $anfragen === [], 'ein Host außerhalb von eventworx.biz bekommt nie eine Anfrage');
    Settings::put(Eventworx::EINSTELLUNG_HOST, $host);
    Settings::put(Eventworx::EINSTELLUNG_TOKEN_HOST, $host);
    pruefe(Eventworx::eingerichtet(), 'wieder eingerichtet');

    // ------------------------------------------------ Pfadliste
    $holen = new ReflectionMethod(Eventworx::class, 'holen');
    foreach (['file/delete/abcdefgh', 'contact/0123456789abcdef01234567', 'job/../x', 'job/kurz', 'job/abcdefgh/x', 'job/abcdefgh?x=1', 'billing',
        'check/version', '../job', 'JOB', 'job/' . str_repeat('a', 41), '/job'] as $pfad) {
        $anfragen = [];
        $geworfen = false;
        try {
            $holen->invoke(null, $pfad);
        } catch (LogicException) {
            $geworfen = true;
        }
        pruefe($geworfen && $anfragen === [], 'Pfad abgelehnt, bevor etwas hinausgeht: ' . $pfad);
    }
    foreach (['kurz', '../../file/delete/abcdefgh', 'abcdefgh/../x', 'abc def ghij', str_repeat('a', 41), 'abcdefgh?x=1'] as $id) {
        $anfragen = [];
        pruefe($fehler(static fn () => Eventworx::vorgang($id)) !== null && $anfragen === [], 'Vorgangskennung abgelehnt: ' . $id);
    }

    // ------------------------------------------------ Anfrage
    $anfragen = [];
    $antworten = [$json(200, ['success' => true, 'total' => 0, 'data' => []])];
    gleich(Eventworx::suchen("  AU-1042\x07\x1b  Sommer\tfest \u{202E} " . str_repeat('x', 100)), [], 'leere Trefferliste');
    $a = $anfragen[0] ?? ['methode' => '', 'url' => '', 'optionen' => []];
    gleich($a['methode'], 'GET', 'nur GET');
    gleich([$a['optionen']['kopf']['S-API-TOKEN'] ?? null, $a['optionen']['kopf']['Accept'] ?? null], [$token, 'application/json'], 'Kopf S-API-TOKEN und Accept');
    $teile = parse_url($a['url']);
    gleich(($teile['scheme'] ?? '') . '://' . ($teile['host'] ?? '') . ($teile['path'] ?? ''), 'https://' . $host . '/backend/job', 'Adresse unter /backend/');
    parse_str((string) ($teile['query'] ?? ''), $q);
    gleich([$q['start'] ?? null, $q['limit'] ?? null, $q['page'] ?? null], ['0', '20', '1'], 'start, limit, page');
    gleich($q['sort'] ?? null, '[{"property":"lastModification","direction":"DESC"}]', 'Sortierung');
    gleich($q['filter'] ?? null, '[{"property":"subType","operator":"in","value":["order","offer"]},{"property":"deleted","operator":"=","value":"false"},'
        . '{"property":"archived","operator":"=","value":"false"}]', 'Filter');
    gleich(mb_strlen((string) ($q['query'] ?? '')), 64, 'Suchtext auf 64 Zeichen gekürzt');
    pruefe(str_starts_with((string) ($q['query'] ?? ''), 'AU-1042 Sommer fest xxx') && preg_match('/[\x00-\x1f\x7f]|\x{202E}/u', (string) ($q['query'] ?? '')) === 0,
        'Steuerzeichen entfernt, Leerraum zusammengezogen: ' . (string) ($q['query'] ?? ''));
    $anfragen = [];
    pruefe($fehler(static fn () => Eventworx::suchen(" a \x07 ")) !== null && $anfragen === [], 'Suchtext unter zwei Zeichen geht nicht hinaus');

    // ------------------------------------------------ Suche
    $mitternacht = 1783720800000; // 11.07.2026 00:00 in Berlin = 10.07. 22:00 UTC
    $antworten = [$json(200, ['success' => true, 'total' => 8, 'data' => [
        ['id' => 'aaaaaaaa00000001', 'docType' => 'order', 'jobNumber' => 'AU-1000', 'projectNumber' => 'P-1', 'title' => 'Muster &amp; Söhne &amp Co',
            'startDate' => $mitternacht, 'endDate' => '1783893600000'],
        ['id' => 'aaaaaaaa00000002', 'docType' => 'order', 'jobNumber' => 'TMP_123', 'title' => 'ungespeichert'],
        ['id' => 'aaaaaaaa00000003', 'docType' => 'offer', 'jobNumber' => 'AN-1001', 'variantNumber' => 'AN-1001-01', 'title' => 'Angebot',
            'rentStartDate' => '2026-07-10T22:00:00.000Z', 'rentEndDate' => '2026-07-13T00:00:00+02:00'],
        ['id' => 'aaaaaaaa00000004', 'docType' => 'order', 'jobNumber' => 'au-1042', 'title' => 'Genau'],
        ['id' => 'aaaaaaaa00000001', 'docType' => 'order', 'jobNumber' => 'AU-1000', 'title' => 'doppelt'],
        ['id' => 'kurz', 'docType' => 'order', 'jobNumber' => 'AU-9'],
        ['id' => 'aaaaaaaa00000005', 'docType' => 'invoice', 'jobNumber' => 'RE-1'],
        ['id' => 'aaaaaaaa00000006', 'docType' => 'offer', 'jobNumber' => 'AN-1002', 'variant' => 2],
        'kein Vorgang',
    ]])];
    $treffer = Eventworx::suchen('AU-1042');
    gleich(array_column($treffer, 'nummer'), ['au-1042', 'AU-1000', 'AN-1001-01', 'AN-1002-02'], 'Treffer: genau oben, TMP_ und Doppelte weg, Angebote mit Variante');
    pruefe(($treffer[0]['genau'] ?? false) === true && ($treffer[1]['genau'] ?? true) === false, 'genau nur bei gleicher Nummer');
    gleich($treffer[1]['titel'] ?? null, 'Muster & Söhne &amp Co', 'Titel dekodiert, „&amp“ ohne Semikolon bleibt');
    gleich([$treffer[1]['von'] ?? null, $treffer[1]['bis'] ?? null], ['2026-07-10T22:00:00+00:00', '2026-07-12T22:00:00+00:00'], 'Millisekunden als Zahl und als Ziffernfolge → UTC');
    gleich([$treffer[2]['von'] ?? null, $treffer[2]['bis'] ?? null], ['2026-07-10T22:00:00+00:00', '2026-07-12T22:00:00+00:00'], 'ISO → UTC, sonst Mietzeit');
    gleich(Zeit::anzeige((string) ($treffer[1]['von'] ?? ''), 'd.m.Y H:i'), '11.07.2026 00:00', 'Berliner Mitternacht bleibt der 11.');
    gleich([$treffer[2]['art'] ?? null, $treffer[2]['art_name'] ?? null, $treffer[1]['projekt'] ?? null], ['offer', 'Angebot', 'P-1'], 'Art und Projekt');
    gleich([Eventworx::zeit(0), Eventworx::zeit(''), Eventworx::zeit('gestern'), Eventworx::zeit(true), Eventworx::zeit('2026-07-11')],
        [null, null, null, null, '2026-07-11T00:00:00+00:00'], 'Zeiten: leer, Unsinn, Datum');

    // ------------------------------------------------ Vorgang
    $id = 'b0b0b0b0b0b0b0b0b0b0b0b0';
    $voll = [
        'id' => $id, 'docType' => 'order', 'jobNumber' => 'AU-2001', 'projectNumber' => 'P-77', 'title' => 'Hoffest &amp; Markt', 'customerId' => 'K-OBEN',
        'customer' => ['isCompany' => true, 'companyName' => "Muster   &amp;\n Söhne GmbH", 'customerId' => 'K-1', 'personFirstName' => 'Nicht'],
        'postalAddress' => ['street' => 'Hauptstraße 5', 'addressextension' => 'Hinterhaus', 'zipcode' => '12345', 'city' => 'Musterstadt', 'country' => 'de'],
        'contact' => ['personSalutation' => 'Frau', 'personTitle' => 'Dr.', 'personFirstName' => 'Erika', 'personLastName' => 'Musterfrau', 'personDepartment' => 'Produktion',
            'communications' => [['typeName' => 'fax_business', 'value' => '030 999'], ['typeName' => 'mobile', 'value' => '0170 111'],
                ['typeName' => 'tel_business', 'value' => 'ruf an: 030'], ['typeName' => 'tel_business', 'value' => '+49 (30) 123-45'],
                ['typeName' => 'email_business', 'value' => 'kein@'], ['typeName' => 'email_business', 'value' => 'erika@example.org']]],
        'customerIsLocation' => false,
        'locationContact' => ['isLocation' => true, 'locationName' => 'Gutshof Muster'],
        'locationAddress' => ['street' => 'Feldweg 1', 'zipcode' => '1010', 'city' => 'Wien', 'country' => 'at'],
        'locationContactPerson' => ['personSalutation' => 'Herr', 'personFirstName' => 'Max', 'personLastName' => 'Mustermann'],
        'setupDate' => 1783634400000, 'startDate' => $mitternacht, 'endDate' => 1783893600000, 'tearDownDate' => 1783980000000,
        // was nie hinausgeht
        'notes' => 'NOTIZ-' . $kennung, 'internalNote' => 'INTERN-' . $kennung, 'positionTree' => [['price' => 123456, 'name' => 'POSITION-' . $kennung]],
        'totalNet' => 987654, 'invoiceContact' => ['companyName' => 'RECHNUNG-' . $kennung], 'invoiceAddress' => ['street' => 'RECHNUNGSSTRASSE'],
        'bankAccount' => ['iban' => 'DE00' . $kennung],
    ];
    $anfragen = [];
    $antworten = [$json(200, ['success' => true, 'message' => null, 'data' => $voll])];
    $v = Eventworx::vorgang($id);
    gleich(count($anfragen), 1, 'ein Vorgang, eine Anfrage');
    gleich(parse_url($anfragen[0]['url'] ?? '', PHP_URL_PATH), '/backend/job/' . $id, 'Pfad job/<id>');
    gleich($v['verweis'], ['id' => $id, 'art' => 'order', 'art_name' => 'Auftrag', 'nummer' => 'AU-2001', 'host' => $host,
        'link' => 'https://' . $host . '/eventworx/#job/edit/order/' . $id, 'kunde_ref' => ''], 'Verweis mit Link in die Web-App, ohne customerRefId keine Kunden-Kennung');
    gleich($v['gruppen']['veranstaltung'], ['veranstaltung' => 'Hoffest & Markt', 'auftrag_nr' => 'AU-2001', 'projekt_nr' => 'P-77'], 'Veranstaltung');
    gleich($v['gruppen']['kunde'], ['firma' => 'Muster & Söhne GmbH', 'anschrift' => "Hauptstraße 5\nHinterhaus\n12345 Musterstadt", 'kundennummer' => 'K-1'],
        'Kunde: Firma dekodiert, Leerraum zusammengezogen; Anschrift ohne „de“; Kundennummer aus customer');
    gleich($v['gruppen']['ansprechpartner'], ['ansprechpartner' => 'Frau Dr. Erika Musterfrau, Produktion', 'telefon' => '+49 (30) 123-45',
        'email' => 'erika@example.org', 'projektleiter_kunde' => 'Dr. Erika Musterfrau'], 'Ansprechpartner: Telefon und E-Mail nur gültig, tel_business vor mobile');
    gleich($v['gruppen']['ort'], ['aufstellort' => "Gutshof Muster\nFeldweg 1\n1010 Wien\nAT", 'anlagenverantwortlicher' => 'Max Mustermann'], 'Ort mit Land außerhalb Deutschlands');
    gleich($v['gruppen']['zeitraum'], ['zeitraum' => 'Aufbau 10.07.2026 · Veranstaltung 11.07.2026 – 13.07.2026 · Abbau 14.07.2026',
        'aufbau' => '2026-07-09T22:00:00+00:00', 'von' => '2026-07-10T22:00:00+00:00', 'bis' => '2026-07-12T22:00:00+00:00', 'abbau' => '2026-07-13T22:00:00+00:00'],
        'Zeitraum in Ortszeit, Zeiten in UTC');
    $ausgabe = (string) json_encode($v, JSON_UNESCAPED_UNICODE);
    foreach (['NOTIZ-', 'INTERN-', 'POSITION-', 'RECHNUNG', '987654', '123456', 'DE00', 'K-OBEN', 'Nicht', '030 999', '0170 111', 'kein@'] as $verboten) {
        pruefe(!str_contains($ausgabe, $verboten), 'nicht in der Ausgabe: ' . $verboten);
    }
    $person = Eventworx::abbilden(['id' => $id, 'docType' => 'order', 'jobNumber' => 'AU-3', 'customerIsLocation' => true,
        'customer' => ['isPerson' => true, 'personFirstName' => 'Erika', 'personLastName' => 'Musterfrau', 'companyName' => 'Firma X'],
        'postalAddress' => ['street' => 'Weg 2', 'zipcode' => '12345', 'city' => 'Musterstadt', 'country' => 'DE'],
        'contact' => ['companyName' => 'Nur Firma', 'personSalutation' => 'Frau']]);
    gleich([$person['gruppen']['kunde']['firma'], $person['gruppen']['ort']['aufstellort'], $person['gruppen']['ansprechpartner']['ansprechpartner']],
        ['Erika Musterfrau', "Erika Musterfrau\nWeg 2\n12345 Musterstadt", 'Nur Firma'], 'Person als Kunde, Kunde ist der Ort, Ansprechpartner ohne Person');
    $namen = static fn (array $kunde): string => Eventworx::abbilden(['id' => $id, 'docType' => 'order', 'jobNumber' => 'AU-4', 'customer' => $kunde])['gruppen']['kunde']['firma'];
    gleich([$namen(['isPerson' => 'true', 'personNickName' => 'Eri']), $namen(['isLocation' => true, 'locationName' => 'Stadthalle &#228;']),
        $namen(['isCompany' => true, 'personFirstName' => 'Max', 'personLastName' => 'Muster']), $namen(['companyName' => "A\u{202E}B"])],
        ['Eri', 'Stadthalle ä', 'Max Muster', 'A B'], 'Namen: Spitzname, Ort, Rückfall auf die Person, keine Richtungszeichen');
    gleich(Eventworx::abbilden(['id' => $id, 'docType' => 'offer', 'jobNumber' => 'AN-7', 'variantNumber' => 'AN-7-03'])['verweis']['nummer'], 'AN-7-03', 'Angebot mit variantNumber');
    // Kunden-Kennung (Kundenbereich: Vorschlag, welchem Kunden ein Bericht gehört) – nur als ObjectId.
    gleich(Eventworx::abbilden($voll + ['customerRefId' => '65c3716b43f4c400000a0abc'])['verweis']['kunde_ref'], '65c3716b43f4c400000a0abc',
        'abbilden(): customerRefId als kunde_ref im Verweis');
    gleich(array_map(static fn ($ref): string => Eventworx::kundeRef(['customerRefId' => $ref]),
        ['65c3716b43f4c400000a0abc', 'K-10023', '65C3716B43F4C400000A0ABC', '65c3716b43f4c400000a0ab', '65c3716b43f4c400000a0abcd', ['id' => 'x'], 4711, null]),
        ['65c3716b43f4c400000a0abc', '', '', '', '', '', '', ''], 'kundeRef(): nur eine ObjectId, sonst leer (Kundennummer, Großbuchstaben, Länge, kein Text)');
    pruefe(!str_contains((string) json_encode(Eventworx::abbilden($voll + ['customerRefId' => 'K-' . $kennung]), JSON_UNESCAPED_UNICODE), 'K-' . $kennung),
        'eine customerRefId, die keine ObjectId ist, geht nicht hinaus');

    $antworten = [$json(200, ['success' => true, 'data' => ['id' => 'anderevorgangsid1', 'docType' => 'order', 'jobNumber' => 'AU-1']])];
    gleich($fehler(static fn () => Eventworx::vorgang($id))?->getMessage(), 'Eventworx: unerwartete Antwort.', 'data.id ≠ angefragte ID');
    $antworten = [$json(200, ['success' => true, 'data' => ['id' => $id, 'docType' => 'invoice', 'jobNumber' => 'RE-1']])];
    gleich($fehler(static fn () => Eventworx::vorgang($id))?->getMessage(), 'Eventworx: unerwartete Antwort.', 'Rechnung wird nicht übernommen');

    // ------------------------------------------------ Fehler
    // 200 + data:null: genau einmal nachlesen, mit Abstand
    $anfragen = [];
    $antworten = [$json(200, ['success' => true, 'message' => null, 'data' => null]), $json(200, ['success' => true, 'data' => null]), $json(200, ['success' => true, 'data' => $voll])];
    gleich($fehler(static fn () => Eventworx::vorgang($id))?->getMessage(), 'Vorgang in Eventworx nicht (mehr) vorhanden.', '200 + data:null zweimal → nicht vorhanden');
    gleich(count($anfragen), 2, 'genau zwei Anfragen');
    pruefe(count($anfragen) === 2 && $anfragen[1]['zeit'] - $anfragen[0]['zeit'] >= 0.45, 'zweite Anfrage mit Abstand');
    $anfragen = [];
    $antworten = [$json(200, ['success' => true]), $json(200, ['success' => true, 'data' => $voll])];
    pruefe($fehler(static fn () => Eventworx::vorgang($id)) === null && count($anfragen) === 2, 'beim zweiten Mal mit Daten → normal gelesen');

    $faelle = [
        '200 mit ACCESS_DENIED' => [$json(200, ['success' => false, 'message' => 'Server reports access denied.', 'errorCode' => 'ACCESS_DENIED', 'extraInfo' => null]),
            'Eventworx lehnt den Zugriff ab – API-Token oder REST-Modul prüfen.'],
        '401'          => [$json(401, ['success' => false]), 'Eventworx lehnt den Zugriff ab – API-Token oder REST-Modul prüfen.'],
        '403 als HTML' => [$roh(403, '<html>verboten</html>'), 'Eventworx lehnt den Zugriff ab – API-Token oder REST-Modul prüfen.'],
        'HTML'         => [$roh(200, '<!doctype html><title>Anmeldung</title>'), 'Eventworx antwortet nicht mit JSON – stimmt die Adresse?'],
        '302'          => [$roh(302, 'https://evil.example/?t=' . $token), 'Eventworx leitet um – stimmt die Adresse?'],
        '429'          => [$json(429, ['success' => false]), 'Eventworx: zu viele Anfragen – bitte kurz warten.'],
        '500'          => [$json(500, ['success' => false, 'errorCode' => 'INTERNAL_ERROR']), 'Eventworx meldet einen Serverfehler (HTTP 500).'],
        '503 HTML'     => [$roh(503, 'Wartung'), 'Eventworx meldet einen Serverfehler (HTTP 503).'],
        '{}'           => [$roh(200, '{}'), 'Eventworx: unerwartete Antwort.'],
        'data:{}'      => [$roh(200, '{"success":true,"data":{}}'), 'Eventworx: unerwartete Antwort.'],
        'data:[]'      => [$roh(200, '{"success":true,"data":[1,2]}'), 'Eventworx: unerwartete Antwort.'],
        'success fehlt' => [$json(200, ['data' => $voll]), 'Eventworx: unerwartete Antwort.'],
        '201'          => [$json(201, ['success' => true, 'data' => $voll]), 'Eventworx: unerwartete Antwort.'],
        'ok=false'     => [['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => 'Zeitüberschreitung bei ' . $token], 'Eventworx nicht erreichbar – Kundendaten bitte als Freitext eintragen.'],
        '404 JSON'     => [$json(404, ['success' => false, 'errorCode' => 'NOT_FOUND']), 'Vorgang in Eventworx nicht (mehr) vorhanden.'],
        'anderer Code' => [$json(200, ['success' => false, 'errorCode' => 'VERSION_CONFLICT', 'message' => 'Kunde Erika Musterfrau']), 'Eventworx: VERSION_CONFLICT'],
        'Code mit Sonderzeichen' => [$json(200, ['success' => false, 'errorCode' => '<script>alert(1)</script>']), 'Eventworx: Fehler'],
        'Code als Name' => [$json(200, ['success' => false, 'errorCode' => 'Erika Musterfrau']), 'Eventworx: Fehler'],
        'Code zu lang'  => [$json(200, ['success' => false, 'errorCode' => 'A' . str_repeat('B', 64)]), 'Eventworx: Fehler'],
        'tief verschachtelt' => [$roh(200, str_repeat('[', 100) . str_repeat(']', 100)), 'Eventworx antwortet nicht mit JSON – stimmt die Adresse?'],
    ];
    foreach ($faelle as $name => [$antwort, $erwartet]) {
        $anfragen = [];
        $antworten = [$antwort, $json(200, ['success' => true, 'data' => $voll])];
        $e = $fehler(static fn () => Eventworx::vorgang($id));
        gleich($e?->getMessage(), $erwartet, 'Fehler „' . $name . '“');
        gleich(count($anfragen), 1, 'Fehler „' . $name . '“: genau eine Anfrage, kein Neuversuch');
    }
    $antworten = [$json(200, ['success' => true, 'total' => 1, 'data' => ['id' => 'x']])];
    gleich($fehler(static fn () => Eventworx::suchen('AU-1042'))?->getMessage(), 'Eventworx: unerwartete Antwort.', 'Suche: data ist keine Liste');
    $antworten = [$json(200, ['success' => true, 'total' => 1])];
    gleich($fehler(static fn () => Eventworx::suchen('AU-1042'))?->getMessage(), 'Eventworx: unerwartete Antwort.', 'Suche: data fehlt');

    // ------------------------------------------------ Verbindungstest und Drossel
    $anfragen = [];
    $antworten = [$json(200, ['message' => 'Service is running.', 'success' => true]), $json(200, ['success' => true, 'start' => 0, 'limit' => 1, 'total' => 1234, 'data' => []])];
    gleich(Eventworx::verbindungTesten(), ['ok' => true, 'message' => 'Verbunden mit ' . $host . ' – 1.234 Aufträge/Angebote lesbar.'], 'Verbindungstest');
    gleich(array_map(static fn (array $a): string => (string) parse_url($a['url'], PHP_URL_PATH), $anfragen), ['/backend/check/ping', '/backend/job'], 'erst ping, dann job');
    parse_str((string) parse_url($anfragen[1]['url'] ?? '', PHP_URL_QUERY), $q);
    gleich([$q['limit'] ?? null, $q['filter'] ?? null], ['1', '[{"property":"subType","operator":"in","value":["order","offer"]}]'], 'Verbindungstest zählt mit limit=1');
    pruefe(count($anfragen) === 2 && $anfragen[1]['zeit'] - $anfragen[0]['zeit'] >= 0.5, 'Drossel: zwei Anfragen mindestens 0,5 s auseinander');
    $antworten = [$json(200, ['message' => 'Service is running.', 'success' => true]), $json(401, ['success' => false, 'errorCode' => 'ACCESS_DENIED'])];
    gleich(Eventworx::verbindungTesten(), ['ok' => false, 'message' => 'Eventworx lehnt den Zugriff ab – API-Token oder REST-Modul prüfen.'], 'Verbindungstest mit falschem Token');
    $antworten = [$json(200, ['success' => true]), $json(200, ['success' => true, 'data' => []])];
    gleich(Eventworx::verbindungTesten()['ok'], false, 'Verbindungstest ohne total');

    // Viele gleichzeitig (Schlafen ohne Wirkung): ab mehr als 2 s Wartezeit eine Meldung
    $takt = new ReflectionMethod(Eventworx::class, 'takt');
    $schlafen = Eventworx::$schlafen;
    Eventworx::$schlafen = static function (float $s): void {
    };
    $ergebnisse = [];
    for ($i = 0; $i < 6; $i++) {
        try {
            $takt->invoke(null);
            $ergebnisse[] = 'ok';
        } catch (EventworxFehler $e) {
            $ergebnisse[] = $e->fehlercode;
        }
    }
    Eventworx::$schlafen = $schlafen;
    gleich($ergebnisse, ['ok', 'ok', 'ok', 'ok', 'DROSSEL', 'DROSSEL'], 'Drossel: bis 2 s warten, danach „gleich noch einmal“');
    $t -= 600; // Uhr zurückgestellt: die Sperrdatei steht in der Zukunft
    $anfragen = [];
    $antworten = [$json(200, ['success' => true, 'total' => 0, 'data' => []])];
    pruefe($fehler(static fn () => Eventworx::suchen('AU-1042')) === null && count($anfragen) === 1, 'zurückgestellte Uhr sperrt nicht dauerhaft');
    $t += 1200;

    // ------------------------------------------------ Dateien am Vorgang (Prüfnachweise des Kundenbereichs)
    $jobOid = 'c0ffee00c0ffee00c0ffee00';
    $datei = static fn (string $id, array $mehr = []): array => $mehr + ['id' => $id, 'documentName' => 'Prüfprotokoll ' . $id . '.pdf',
        'contentType' => 'application/pdf', 'timeStamp' => 1783720800000, 'path' => '/job/documents/P-1', 'ownerDocumentId' => $jobOid, 'description' => ''];
    $mitPfad = (new ReflectionClassConstant(Eventworx::class, 'DATEI_FILTER_MIT_PFAD'))->getValue();
    $anfragen = [];
    $antworten = [$json(200, ['success' => true, 'total' => 7, 'data' => [
        $datei('d00000000000000000000001', ['documentName' => 'DGUV V3 Muster &amp; Söhne.pdf', 'description' => 'hochgeladen von Erika']),
        $datei('d00000000000000000000002', ['metaDocType' => 'order', 'documentName' => 'Auftrag AU-2001.pdf', 'path' => '/job/documents']),
        $datei('d00000000000000000000003', ['contentType' => 'application/xml', 'documentName' => 'rechnung.xml']),
        $datei('d00000000000000000000004', ['path' => '/andere/ablage']),
        $datei('kurz00000001'),
        $datei('d00000000000000000000005', ['path' => '/job/documents', 'contentType' => '', 'documentName' => 'ohne-typ.PDF']),
        $datei('d00000000000000000000006', ['contentType' => '', 'documentName' => 'bild.png']),
    ]])];
    $dok = Eventworx::dokumente($jobOid);
    gleich(count($anfragen), 1, 'Dateiliste: eine Anfrage');
    $a = $anfragen[0] ?? ['methode' => '', 'url' => '', 'optionen' => []];
    gleich([$a['methode'], parse_url($a['url'], PHP_URL_PATH), $a['optionen']['kopf']['Accept'] ?? null], ['GET', '/backend/file/list', 'application/json'],
        'Dateiliste: GET /backend/file/list, JSON');
    $query = (string) parse_url($a['url'], PHP_URL_QUERY);
    pruefe(str_starts_with($query, 'page=1&start=0&limit=200&filter='), 'Dateiliste: page=1&start=0&limit=200, dann der Filter: ' . $query);
    parse_str($query, $q);
    gleich(json_decode((string) ($q['filter'] ?? ''), true), [['property' => 'meta', 'operator' => '=', 'value' => $mitPfad
        ? ['path' => '/job/documents', 'ownerDocumentId' => $jobOid] : ['ownerDocumentId' => $jobOid]]], 'Dateiliste: Filter nach DATEI_FILTER_MIT_PFAD');
    gleich(array_column($dok['dateien'], 'id'), ['d00000000000000000000001', 'd00000000000000000000005'],
        'Dateiliste: nur hochgeladene PDFs (nicht der Beleg mit metaDocType, kein XML, kein Bild, nichts außerhalb /job/documents, keine kaputte ID)');
    gleich([$dok['dateien'][0]['name'] ?? '', $dok['dateien'][0]['zeit'] ?? '', $dok['mehr']], ['DGUV V3 Muster & Söhne.pdf', '2026-07-10T22:00:00+00:00', false],
        'Dateiliste: Name dekodiert, Zeit in UTC, nicht mehr als gezeigt');
    $antworten = [$json(200, ['success' => true, 'total' => 250, 'data' => [$datei('d00000000000000000000007')]])];
    gleich(Eventworx::dokumente($jobOid)['mehr'], true, 'Dateiliste: total 250 → es gibt mehr');
    // Eventworx übergeht unbekannte Filter still: eine fremde Datei verwirft die ganze Liste.
    $antworten = [$json(200, ['success' => true, 'total' => 2, 'data' => [$datei('d00000000000000000000008'),
        $datei('d00000000000000000000009', ['ownerDocumentId' => 'ffffffffffffffffffffffff'])]])];
    $e = $fehler(static fn () => Eventworx::dokumente($jobOid));
    gleich([$e?->fehlercode, $e?->getMessage()], ['FILTER', 'Eventworx hat die Dateiliste nicht auf den Vorgang beschränkt – nichts übernommen.'],
        'eine Datei eines anderen Vorgangs → ganze Liste verworfen');
    $antworten = [$json(200, ['success' => true, 'total' => 1, 'data' => [array_diff_key($datei('d00000000000000000000010'), ['ownerDocumentId' => 1])]])];
    gleich($fehler(static fn () => Eventworx::dokumente($jobOid))?->fehlercode, 'FILTER', 'ohne ownerDocumentId → ganze Liste verworfen');
    $antworten = [$json(200, ['success' => true, 'total' => 1, 'data' => ['keine Datei']])];
    gleich($fehler(static fn () => Eventworx::dokumente($jobOid))?->fehlercode, 'UNERWARTET', 'Eintrag ohne Objekt → unerwartet');
    $antworten = [$json(200, ['success' => true, 'data' => ['id' => 'x']])];
    gleich($fehler(static fn () => Eventworx::dokumente($jobOid))?->fehlercode, 'UNERWARTET', 'data ist keine Liste → unerwartet');
    $antworten = [$json(200, ['success' => false, 'errorCode' => 'ACCESS_DENIED'])];
    gleich($fehler(static fn () => Eventworx::dokumente($jobOid))?->getMessage(),
        'Eventworx lehnt den Dateizugriff ab – die Dateischnittstelle muss evtl. für das Token freigeschaltet werden.', 'Dateiliste mit ACCESS_DENIED (200) → Freischaltung');
    $anfragen = [];
    pruefe($fehler(static fn () => Eventworx::dokumente('AU-2001-keine-oid')) !== null && $anfragen === [], 'Dateiliste nur für eine ObjectId, sonst keine Anfrage');

    // Download nur, was dokumente() in diesem Prozess gelesen hat.
    $ohneNetz = static function (callable $f) use (&$anfragen): bool {
        $anfragen = [];
        try {
            $f();
        } catch (LogicException) {
            return $anfragen === [];
        }
        return false;
    };
    pruefe($ohneNetz(static fn () => Eventworx::datei('d00000000000000000000099')), 'Datei, die keine Liste lieferte → LogicException, keine Anfrage');
    pruefe($ohneNetz(static fn () => Eventworx::datei('d00000000000000000000008')), 'Datei aus einer verworfenen Liste → LogicException, keine Anfrage');
    pruefe($ohneNetz(static fn () => Eventworx::datei('d00000000000000000000002')), 'erzeugter Beleg (nicht geliefert) → LogicException, keine Anfrage');
    Eventworx::vergessen();
    pruefe($ohneNetz(static fn () => Eventworx::datei('d00000000000000000000001')), 'neuer Lauf (vergessen) → auch eine eben gelistete Datei nicht');
    $antworten = [$json(200, ['success' => true, 'total' => 1, 'data' => [$datei('d00000000000000000000001', ['documentName' => 'DGUV V3 Muster &amp; Söhne.pdf'])]])];
    Eventworx::dokumente($jobOid);
    $pdfBytes = "%PDF-1.4\n% " . $kennung . "\n%%EOF\n";
    $anfragen = [];
    $antworten = [$roh(200, $pdfBytes)];
    gleich(Eventworx::datei('d00000000000000000000001'), $pdfBytes, 'Datei: die Bytes des PDF');
    $a = $anfragen[0] ?? ['methode' => '', 'url' => '', 'optionen' => []];
    parse_str((string) parse_url($a['url'], PHP_URL_QUERY), $q);
    gleich([$a['methode'], parse_url($a['url'], PHP_URL_PATH), $q['filename'] ?? null, $a['optionen']['kopf']['Accept'] ?? null,
        $a['optionen']['max'] ?? null, $a['optionen']['zeit'] ?? null, $a['optionen']['kopf']['S-API-TOKEN'] ?? null],
        ['GET', '/backend/file/d00000000000000000000001', 'DGUV V3 Muster &amp; Söhne.pdf', '*/*', Eventworx::MAX_DATEI, 30, $token],
        'Datei: GET file/<id>?filename=<Name wie in Eventworx>, Accept */*, höchstens 10 MB, 30 s, Token');
    foreach ([
        'HTML-Anmeldeseite'  => [$roh(200, '<!doctype html><title>Anmeldung</title>'), 'KEIN_PDF', 'Eventworx lieferte keine PDF-Datei.'],
        'zu groß'            => [['ok' => false, 'status' => 200, 'rumpf' => '', 'fehler' => 'abgebrochen', 'nummer' => Http::ZU_GROSS], 'ZU_GROSS',
            'Die Datei ist größer als 10 MB – bitte von Hand hochladen.'],
        'ACCESS_DENIED (200)' => [$json(200, ['success' => false, 'errorCode' => 'ACCESS_DENIED']), 'ACCESS_DENIED',
            'Eventworx lehnt den Dateizugriff ab – die Dateischnittstelle muss evtl. für das Token freigeschaltet werden.'],
        '403'                => [$roh(403, 'verboten'), 'ACCESS_DENIED', 'Eventworx lehnt den Dateizugriff ab – die Dateischnittstelle muss evtl. für das Token freigeschaltet werden.'],
        '404'                => [$json(404, ['success' => false, 'errorCode' => 'NOT_FOUND']), 'NOT_FOUND', 'Die Datei gibt es in Eventworx nicht (mehr).'],
        '302'                => [$roh(302, ''), 'UMLEITUNG', 'Eventworx leitet um – stimmt die Adresse?'],
        'nicht erreichbar'   => [['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => 'weg ' . $token], 'NETZ',
            'Eventworx nicht erreichbar – bitte später noch einmal oder den Nachweis von Hand hochladen.'],
        '500'                => [$roh(500, ''), 'SERVER', 'Eventworx meldet einen Serverfehler (HTTP 500).'],
        'PDF mit 201'        => [$roh(201, $pdfBytes), 'KEIN_PDF', 'Eventworx lieferte keine PDF-Datei.'],
    ] as $name => [$antwort, $code, $text]) {
        $anfragen = [];
        $antworten = [$antwort, $roh(200, $pdfBytes)];
        $e = $fehler(static fn () => Eventworx::datei('d00000000000000000000001'));
        gleich([$e?->fehlercode, $e?->getMessage(), count($anfragen)], [$code, $text, 1], 'Datei „' . $name . '“: Meldung, kein Neuversuch');
    }
    // Pfade, die nie hinausgehen – auch nicht als Datei.
    $holenDatei = new ReflectionMethod(Eventworx::class, 'holenDatei');
    $antworten = [];
    foreach (['file/delete/d00000000000000000000001', 'file/download', 'file/0123456789abcdef', 'file/D00000000000000000000001', 'file/d00000000000000000000001/x',
        'file/list/x', 'contact/d00000000000000000000001', 'stock', 'stock/d00000000000000000000001', 'check/version'] as $pfad) {
        pruefe($ohneNetz(static fn () => $holen->invoke(null, $pfad)) && $ohneNetz(static fn () => $holenDatei->invoke(null, $pfad, [])),
            'Pfad abgelehnt, bevor etwas hinausgeht: ' . $pfad);
    }
    pruefe($ohneNetz(static fn () => $holenDatei->invoke(null, 'file/list', [])), 'die Dateiliste nie als Bytes');
    pruefe($ohneNetz(static fn () => Eventworx::suchen('AU-1', ['invoice'])), 'Suche ohne erlaubte Art → LogicException, keine Anfrage');

    // ------------------------------------------------ Vorgänge verknüpfen (Kundenverwaltung)
    $kundeAnlegen = static fn (string $name): int => Database::insert('kunden', ['name' => $kennung . ' ' . $name, 'kundennummer' => '', 'aktiv' => 1, 'created_at' => gmdate('c')]);
    $k1 = $kundeAnlegen('Muster Events');
    $k2 = $kundeAnlegen('Stadtmarketing');
    $testKunden = [$k1, $k2];
    [$ref1, $ref2] = ['aaaaaaaaaaaaaaaaaaaa0001', 'aaaaaaaaaaaaaaaaaaaa0002'];
    $job = static fn (string $id, string $art, string $nummer, ?string $ref, array $mehr = []): array => $mehr + ['id' => $id, 'docType' => $art,
        'jobNumber' => $nummer, 'title' => 'Fest ' . $kennung, 'customerId' => 'K-77',
        'customer' => ['isCompany' => true, 'companyName' => 'Firma ' . $kennung, 'customerId' => 'K-77'],
        'contact' => ['personFirstName' => 'Erika', 'personLastName' => 'Kontakt-' . $kennung],
        'startDate' => 1783720800000, 'endDate' => 1783893600000] + ($ref !== null ? ['customerRefId' => $ref] : []);
    $j1 = $job('b1b1b1b1b1b1b1b1b1b1b1b1', 'order', 'AU-7001', $ref1);
    $j2 = $job('b2b2b2b2b2b2b2b2b2b2b2b2', 'deliverynote', 'LS-7002', $ref1);
    $j3 = $job('b3b3b3b3b3b3b3b3b3b3b3b3', 'order', 'AU-7003', $ref2);
    $j4 = $job('b4b4b4b4b4b4b4b4b4b4b4b4', 'order', 'AU-7004', null);
    $j5 = $job('b5b5b5b5b5b5b5b5b5b5b5b5', 'offer', 'AN-7005', $ref1);
    $j6 = $job('b6b6b6b6b6b6b6b6b6b6b6b6', 'deliverynote', 'LS-7006', $ref1, ['docSubType' => 'purchase']);
    $j7 = $job('b7b7b7b7b7b7b7b7b7b7b7b7', 'order', 'AU-7007', $ref1);
    $vorgaenge = static fn (int $k): array => array_column(Database::select('SELECT ewx_nummer FROM kunden_vorgaenge WHERE kunde_id = ? ORDER BY id', [$k]), 'ewx_nummer');
    $anfragen = [];
    $antworten = [$json(200, ['success' => true, 'total' => 4, 'data' => [$j1, $j2, $j5, $j6]])];
    $s = KundenService::eventworxSuchen($k1, 'AU-7001');
    parse_str((string) parse_url($anfragen[0]['url'] ?? '', PHP_URL_QUERY), $q);
    gleich(json_decode((string) ($q['filter'] ?? '[]'), true)[0] ?? null, ['property' => 'subType', 'operator' => 'in', 'value' => ['order', 'deliverynote']],
        'Kundenverwaltung sucht Aufträge und Lieferscheine');
    gleich([$s['ok'], array_column($s['treffer'], 'nummer'), array_column($s['treffer'], 'verknuepft')], [true, ['AU-7001', 'LS-7002'], [null, null]],
        'Treffer: kein Angebot, kein Wareneingang; noch nicht verknüpft');
    KundenService::trefferMerken($k1, $s['treffer']);
    $gemerkt = (string) json_encode($_SESSION[KundenService::EWX_TREFFER] ?? null, JSON_UNESCAPED_UNICODE);
    pruefe(str_contains($gemerkt, 'AU-7001') && !str_contains($gemerkt, $kennung) && !str_contains($gemerkt, 'Firma'),
        'Sitzung: nur Kennung, Nummer und Art der Treffer – kein Titel, kein Kunde: ' . $gemerkt);
    $anfragen = [];
    $r = KundenService::vorgangVerknuepfen($k1, $j3['id'], true, false);
    pruefe(!$r['ok'] && $anfragen === [] && $vorgaenge($k1) === [], 'Kennung nicht aus der Suche → abgelehnt, ohne Eventworx zu fragen: ' . $r['message']);
    $antworten = [$json(200, ['success' => true, 'data' => $j1])];
    $r = KundenService::vorgangVerknuepfen($k1, $j1['id'], false, true);
    pruefe(!$r['ok'] && !empty($r['bestaetigen']) && str_contains($r['message'], 'Firma ' . $kennung) && $vorgaenge($k1) === [],
        'Kunde ohne Eventworx-Kennung, Vorgang mit: erst nach Bestätigung (nennt den Namen aus Eventworx)');
    $antworten = [$json(200, ['success' => true, 'data' => $j1])];
    $r = KundenService::vorgangVerknuepfen($k1, $j1['id'], true, true);
    pruefe($r['ok'], 'bestätigt → verknüpft: ' . $r['message']);
    $zeile = Database::first('SELECT * FROM kunden_vorgaenge WHERE kunde_id = ?', [$k1]) ?? [];
    gleich([$zeile['ewx_host'] ?? '', $zeile['ewx_id'] ?? '', $zeile['ewx_art'] ?? '', $zeile['ewx_nummer'] ?? '', $zeile['ewx_kunde_ref'] ?? '',
        $zeile['titel'] ?? '', $zeile['von'] ?? '', $zeile['bis'] ?? '', (int) ($zeile['verknuepft_von'] ?? 0)],
        [$host, $j1['id'], 'order', 'AU-7001', $ref1, 'Fest ' . $kennung, '2026-07-11', '2026-07-13', $benutzer], 'Vorgang mit Art, Nummer, Kunden-Kennung, Titel, Mietzeit als Ortsdatum, wer');
    KundenService::vergessen();
    $kz = KundenService::find($k1) ?? [];
    gleich([$kz['ewx_host'] ?? '', $kz['ewx_kunde_ref'] ?? '', $kz['ewx_kunde_name'] ?? '', $kz['kundennummer'] ?? ''], [$host, $ref1, 'Firma ' . $kennung, 'K-77'],
        'der Kunde bekommt die Eventworx-Kennung (mit Namen zur Kontrolle) und die fehlende Kundennummer');
    $antworten = [$json(200, ['success' => true, 'data' => $j2])];
    $r = KundenService::vorgangVerknuepfen($k1, $j2['id'], false, false);
    pruefe($r['ok'] && $vorgaenge($k1) === ['AU-7001', 'LS-7002'], 'Lieferschein desselben Eventworx-Kunden: ohne Rückfrage verknüpft');
    $antworten = [$json(200, ['success' => true, 'data' => $j2])];
    pruefe(!KundenService::vorgangVerknuepfen($k1, $j2['id'], true, false)['ok'], 'derselbe Vorgang noch einmal → abgelehnt');
    $treffer = static fn (array ...$jobs): array => array_map(static fn (array $j): array => ['id' => $j['id'], 'nummer' => $j['jobNumber'], 'art' => $j['docType']], $jobs);
    KundenService::trefferMerken($k1, $treffer($j1, $j3, $j4, $j5));
    $antworten = [$json(200, ['success' => true, 'data' => $j3])];
    $r = KundenService::vorgangVerknuepfen($k1, $j3['id'], true, false);
    gleich([$r['ok'], $r['message']], [false, 'Der Vorgang gehört in Eventworx zu einem anderen Kunden.'], 'andere Kunden-Kennung → abgelehnt, auch bestätigt');
    $antworten = [$json(200, ['success' => true, 'data' => $j5])];
    $r = KundenService::vorgangVerknuepfen($k1, $j5['id'], true, false);
    pruefe(!$r['ok'] && str_contains($r['message'], 'Nur Aufträge und Lieferscheine'), 'Angebot → abgelehnt: ' . $r['message']);
    $antworten = [$json(200, ['success' => true, 'data' => $j4])];
    $r = KundenService::vorgangVerknuepfen($k1, $j4['id'], false, false);
    pruefe(!$r['ok'] && !empty($r['bestaetigen']) && str_contains($r['message'], 'keine Kunden-Kennung'), 'Vorgang ohne Kunden-Kennung: nur mit Bestätigung');
    $antworten = [$json(200, ['success' => true, 'data' => $j4])];
    pruefe(KundenService::vorgangVerknuepfen($k1, $j4['id'], true, false)['ok']
        && (string) Database::scalar('SELECT ewx_kunde_ref FROM kunden_vorgaenge WHERE ewx_id = ?', [$j4['id']]) === '', '… bestätigt: verknüpft, ohne Kennung');
    KundenService::trefferMerken($k2, $treffer($j1, $j7));
    $antworten = [$json(200, ['success' => true, 'data' => $j1])];
    $r = KundenService::vorgangVerknuepfen($k2, $j1['id'], true, false);
    gleich($r['message'], 'Der Vorgang AU-7001 ist schon Kunde „' . $kennung . ' Muster Events“ zugeordnet.', 'Vorgang eines anderen Kunden → abgelehnt');
    $antworten = [$json(200, ['success' => true, 'data' => $j7])];
    $r = KundenService::vorgangVerknuepfen($k2, $j7['id'], true, false);
    pruefe(!$r['ok'] && str_contains($r['message'], 'schon Kunde „' . $kennung . ' Muster Events“'), 'Eventworx-Kunde gehört schon einem anderen Kunden → abgelehnt: ' . $r['message']);
    gleich($vorgaenge($k2), [], '… K2 hat keinen Vorgang');
    $antworten = [$json(200, ['success' => true, 'total' => 1, 'data' => [$j1]])];
    gleich(array_column(KundenService::eventworxSuchen($k2, 'AU-7001')['treffer'], 'verknuepft'), [$kennung . ' Muster Events'], 'Treffer zeigt, bei wem er schon ist');
    $antworten = [$json(200, ['success' => true, 'total' => 1, 'data' => [$j1]])];
    gleich(array_column(KundenService::eventworxSuchen($k1, 'AU-7001')['treffer'], 'verknuepft'), ['dieser'], '… und beim eigenen Kunden „dieser“');
    // Ohne „berichte.eventworx“ (zeigt alle Vorgänge des Mandanten): nichts, ohne Netz.
    $rolleOhne = (int) BenutzerService::rolleSpeichern(null, $kennung . ' ohne Eventworx', '', ['kunden.view', 'kunden.manage'])['id'];
    $ohneEwx = BenutzerService::anlegen($kennung . '-ohne', 'Pruefung-' . bin2hex(random_bytes(6)), (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$rolleOhne]), false);
    alsBenutzer($ohneEwx);
    $anfragen = [];
    pruefe(!KundenService::eventworxSuchen($k2, 'AU-7001')['ok'] && !KundenService::vorgangVerknuepfen($k2, $j7['id'], true, false)['ok'] && $anfragen === [],
        'ohne „berichte.eventworx“: weder suchen noch verknüpfen, ohne Eventworx zu fragen');
    alsBenutzer($benutzer);
    $details = array_column(Database::select("SELECT details FROM audit_log WHERE action = 'kunden.vorgang_verknuepft' AND target_id IN (?, ?)", [(string) $k1, (string) $k2]), 'details');
    pruefe(count($details) === 3 && !str_contains(implode(' ', $details), $kennung) && str_contains(implode(' ', $details), '"ewx_nummer":"AU-7001"'),
        'Audit der Verknüpfung: IDs und Nummer, kein Name, kein Titel');
    // Lösen: Nachweise aus dem Vorgang bleiben, ohne Verweis; mit dem letzten Vorgang geht die Kennung.
    $v4 = (int) Database::scalar('SELECT id FROM kunden_vorgaenge WHERE ewx_id = ?', [$j4['id']]);
    $nw = Database::insert('pruefnachweise', ['kunde_id' => $k1, 'status' => 'entwurf', 'quelle' => 'hand', 'bezeichnung' => $kennung . ' Nachweis',
        'vorgang_id' => $v4, 'created_at' => gmdate('c')]);
    pruefe(!KundenService::vorgangLoesen($k2, $v4)['ok'] && $vorgaenge($k1) === ['AU-7001', 'LS-7002', 'AU-7004'], 'Vorgang über einen anderen Kunden lösen → abgelehnt');
    $r = KundenService::vorgangLoesen($k1, $v4);
    pruefe($r['ok'] && Database::scalar('SELECT vorgang_id FROM pruefnachweise WHERE id = ?', [$nw]) === null && $vorgaenge($k1) === ['AU-7001', 'LS-7002'],
        'gelöst: Nachweis bleibt, ohne Verweis auf den Vorgang');
    foreach (Database::select('SELECT id FROM kunden_vorgaenge WHERE kunde_id = ?', [$k1]) as $z) {
        KundenService::vorgangLoesen($k1, (int) $z['id']);
    }
    KundenService::vergessen();
    $kz = KundenService::find($k1) ?? [];
    pruefe(array_key_exists('ewx_kunde_ref', $kz) && $kz['ewx_kunde_ref'] === null && ($kz['ewx_kunde_name'] ?? 'x') === '' && ($kz['ewx_host'] ?? 'x') === '',
        'mit dem letzten Vorgang geht die Eventworx-Kennung des Kunden');
    Database::execute('DELETE FROM pruefnachweise WHERE id = ?', [$nw]);

    // ------------------------------------------------ Lese-Probe (Geräte-Prüfdaten)
    foreach (['stock/update', 'stock/inventoryList', 'file/delete/d00000000000000000000001', 'file/d00000000000000000000001', 'job/duplicate', 'job/abcdefgh',
        'stock/D00000000000000000000001', 'workshop/list', 'contact/d00000000000000000000001', 'stock/d00000000000000000000001/x', 'article', 'file/list/x'] as $pfad) {
        pruefe($ohneNetz(static fn () => Eventworx::probeLesen($pfad)), 'Probe: Pfad abgelehnt, bevor etwas hinausgeht: ' . $pfad);
    }
    $oid = static fn (int $n): string => sprintf('e%023x', $n);
    $probeAntworten = static function (string $url) use ($oid, $kennung, $jobOid): array {
        $pfad = substr((string) parse_url($url, PHP_URL_PATH), strlen('/backend/'));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $daten = static fn (mixed $d, ?int $total = null): array => ['ok' => true, 'status' => 200, 'fehler' => '',
            'rumpf' => (string) json_encode(['success' => true, 'data' => $d] + ($total !== null ? ['total' => $total] : []))];
        $geraet = static fn (int $n): array => ['id' => $oid($n), 'name' => 'GERAET-' . $kennung, 'serialNumber' => 'SERIE-' . $kennung, 'status' => 'active',
            'inventoryNumber' => 'INV-' . $kennung, 'lastInspectionDate' => '2026-03-01T00:00:00Z', 'nextInspection' => 1790000000000, 'inspectionInterval' => 12,
            'article' => ['id' => $oid(900), 'name' => 'ARTIKEL-' . $kennung], 'description' => 'BESCHREIBUNG-' . $kennung, 'Seriennummer 4711-' . $kennung => true];
        $filter = json_decode((string) ($q['filter'] ?? '[]'), true);
        $wert = is_array($filter) ? ($filter[0]['value'] ?? []) : [];
        $dateiEintrag = static fn (string $owner, array $mehr = []): array => $mehr + ['id' => $oid(500), 'documentName' => 'DATEINAME-' . $kennung . '.pdf',
            'contentType' => 'application/pdf', 'path' => '/job/documents/P-2026-' . $kennung, 'ownerDocumentId' => $owner, 'owner' => 'BENUTZER-' . $kennung];
        return match (true) {
            $pfad === 'check/ping'    => $daten(null),
            $pfad === 'check/version' => $daten('7.3.1'),
            $pfad === 'job'           => $daten([['id' => $jobOid, 'docType' => 'order', 'jobNumber' => 'AU-' . $kennung]], 1),
            $pfad === 'stock' && isset($q['query']) => $daten([['id' => $oid(1), 'serialNumber' => (string) $q['query']]], 1),
            $pfad === 'stock'         => $daten([$geraet(1), $geraet(2)], 2),
            str_starts_with($pfad, 'stock/') => $daten($geraet(1)),
            str_starts_with($pfad, 'article/') => $daten(['id' => $oid(900), 'name' => 'ARTIKEL-' . $kennung, 'inspectionIntervalMonths' => 12, 'serialized' => true]),
            $pfad === 'file/list' && ($wert['ownerDocumentId'] ?? '') === $jobOid && isset($wert['path']) => $daten([$dateiEintrag($jobOid)], 1),
            $pfad === 'file/list' && ($wert['ownerDocumentId'] ?? '') === $jobOid => $daten([$dateiEintrag($jobOid),
                $dateiEintrag('ffffffffffffffffffffffff', ['id' => $oid(501), 'metaDocType' => 'order'])], 2),
            $pfad === 'file/list'     => $daten([], 0),
            str_starts_with($pfad, 'job/') => $daten(['id' => $jobOid, 'docType' => 'order', 'title' => 'TITEL-' . $kennung, 'positionTree' => [
                ['name' => 'POSITION-' . $kennung, 'deliveryItems' => [$oid(7)], 'deliveryItemsSerials' => 'SERIE-' . $kennung,
                    'children' => [['deliveryItemsInventoryNumber' => 'INV-' . $kennung]]]]]),
            default                   => ['ok' => true, 'status' => 404, 'fehler' => '', 'rumpf' => '{"success":false,"errorCode":"NOT_FOUND"}'],
        };
    };
    $probePfade = [];
    $wieProbe = null;
    Http::$attrappe = static function (string $methode, string $url, array $optionen) use (&$probePfade, &$methoden, &$wieProbe, $probeAntworten): array {
        $probePfade[] = (string) parse_url($url, PHP_URL_PATH);
        $methoden[$methode] = true;
        return $wieProbe !== null ? $wieProbe($url) : $probeAntworten($url);
    };
    $p = EventworxProbe::lauf(null, null, 'SERIE-' . $kennung);
    $ausgabe = (string) json_encode($p, JSON_UNESCAPED_UNICODE);
    pruefe($p['ok'] && $p['anfragen'] <= EventworxProbe::BUDGET && $p['anfragen'] === count($probePfade), 'Probe läuft durch: ' . $p['meldung'] . ' (' . $p['anfragen'] . ' Anfragen)');
    foreach (['GERAET-', 'SERIE-', 'INV-', 'DATEINAME-', 'BESCHREIBUNG-', 'ARTIKEL-', 'POSITION-', 'TITEL-', 'BENUTZER-', $kennung, '4711'] as $marke) {
        pruefe(!str_contains($ausgabe, $marke), 'Probe-Ausgabe ohne Inhalte: ' . $marke);
    }
    pruefe(array_filter($probePfade, static fn (string $pf): bool => preg_match(EventworxProbe::PFADE, substr($pf, strlen('/backend/'))) !== 1) === []
        && !in_array('/backend/file/' . $oid(500), $probePfade, true), 'Probe: nur Pfade ihrer Liste, kein Download');
    $schritt = static function (string $nr) use (&$p): array {
        return array_values(array_filter($p['schritte'], static fn (array $s): bool => $s['schritt'] === $nr));
    };
    $p3 = $schritt('P3')[0] ?? ['pruef' => [], 'schluessel' => [], 'hinweise' => [], 'kennwerte' => []];
    pruefe(in_array('nextInspection', $p3['pruef'], true) && in_array('lastInspectionDate', $p3['pruef'], true) && in_array('inspectionInterval', $p3['pruef'], true),
        'Probe: Prüf-Schlüssel hervorgehoben: ' . implode(', ', $p3['pruef']));
    gleich([$p3['schluessel']['nextInspection'] ?? '', $p3['schluessel']['lastInspectionDate'] ?? '', $p3['schluessel']['serialNumber'] ?? '', $p3['schluessel']['article.id'] ?? '',
        $p3['kennwerte']['status'] ?? ''], ['ms-Zeit', 'ISO-Zeit', 'Text(' . mb_strlen('SERIE-' . $kennung) . ')', 'ObjectId', 'active'],
        'Probe: je Schlüssel nur die Art des Werts, Kennwerte im Klartext');
    pruefe(str_contains(implode(' ', $p3['hinweise']), 'Schlüssel ohne Namensform'), 'Probe: Schlüssel, die wie Inhalt aussehen, nur gezählt');
    pruefe(($schritt('P4')[0]['schluessel']['inspectionIntervalMonths'] ?? '') === 'int', 'Probe P4: Artikel des Geräts aus derselben Antwort');
    $p5a = implode(' ', $schritt('P5a')[0]['hinweise'] ?? []);
    $p5b = implode(' ', $schritt('P5b')[0]['hinweise'] ?? []);
    pruefe(str_contains($p5a, 'davon 0 mit anderem') && str_contains($p5b, 'davon 1 mit anderem') && str_contains($p5b, 'metaDocType order: 1')
        && str_contains($p5a, 'path-Muster: /job/documents/<nr>') && str_contains($p5a, 'contentType application/pdf: 1'), 'Probe P5a/P5b: fremde Owner, Arten, Muster: ' . $p5a . ' | ' . $p5b);
    $p6 = implode(' ', array_merge(...array_map(static fn (array $s): array => $s['hinweise'], $schritt('P6'))));
    pruefe(str_contains($p6, 'positionTree[].deliveryItems (Liste(1))') && str_contains($p6, 'Gegenprobe ObjectId aus den Positionen als Gerät: ja'), 'Probe P6: Geräte-Schlüssel in Positionen: ' . $p6);
    pruefe(str_contains(implode(' ', $schritt('P7')[0]['hinweise'] ?? []), 'Seriennummer unter den Treffern: ja'), 'Probe P7: nur ja/nein');
    gleich($schritt('P1')[1]['kennwerte']['version'] ?? '', '7.3.1', 'Probe P1: Version');
    // ACCESS_DENIED für stock schaltet nur diesen Schritt ab.
    $wieProbe = static fn (string $url): array => str_ends_with((string) parse_url($url, PHP_URL_PATH), '/backend/stock')
        ? ['ok' => true, 'status' => 200, 'fehler' => '', 'rumpf' => '{"success":false,"errorCode":"ACCESS_DENIED"}'] : $probeAntworten($url);
    $p = EventworxProbe::lauf(null, $jobOid);
    pruefe($p['ok'] && ($schritt('P2')[0]['code'] ?? '') === 'ACCESS_DENIED' && $schritt('P3') === [] && $schritt('P5a') !== [] && $schritt('P6') !== [],
        'Probe: ACCESS_DENIED bei stock schaltet nur diesen Schritt ab');
    // HTML (Anmeldeseite) bricht ab; 401 auch.
    $probePfade = [];
    $wieProbe = static fn (string $url): array => str_ends_with((string) parse_url($url, PHP_URL_PATH), '/check/version')
        ? ['ok' => true, 'status' => 200, 'fehler' => '', 'rumpf' => '<html>Anmeldung</html>'] : $probeAntworten($url);
    $p = EventworxProbe::lauf();
    pruefe(!$p['ok'] && str_contains($p['meldung'], 'keine JSON-Antwort') && count($probePfade) === 2, 'Probe: Abbruch bei HTML: ' . $p['meldung']);
    $probePfade = [];
    $wieProbe = static fn (string $url): array => ['ok' => true, 'status' => 401, 'fehler' => '', 'rumpf' => '{"success":false}'];
    $p = EventworxProbe::lauf();
    pruefe(!$p['ok'] && str_contains($p['meldung'], '401') && count($probePfade) === 1, 'Probe: Abbruch bei 401');
    // Budget: höchstens 25 Anfragen, höchstens 60 s.
    $wieProbe = null;
    $roh2 = (new ReflectionClass(EventworxProbe::class))->newInstanceWithoutConstructor();
    $setze = static function (object $o, string $feld, mixed $wert): void {
        (new ReflectionProperty($o, $feld))->setValue($o, $wert);
    };
    $setze($roh2, 'beginn', Eventworx::jetzt());
    $setze($roh2, 'anfragen', EventworxProbe::BUDGET - 1);
    $probePfade = [];
    (new ReflectionMethod($roh2, 'ablauf'))->invoke($roh2, null, null, null);
    pruefe(count($probePfade) === 1 && str_contains((string) (new ReflectionProperty($roh2, 'abbruch'))->getValue($roh2), 'Budget'), 'Probe: nach 25 Anfragen ist Schluss');
    $roh2 = (new ReflectionClass(EventworxProbe::class))->newInstanceWithoutConstructor();
    $setze($roh2, 'beginn', Eventworx::jetzt() - EventworxProbe::SEKUNDEN - 1);
    $probePfade = [];
    (new ReflectionMethod($roh2, 'ablauf'))->invoke($roh2, null, null, null);
    pruefe($probePfade === [] && str_contains((string) (new ReflectionProperty($roh2, 'abbruch'))->getValue($roh2), 'Zeitbudget'), 'Probe: nach 60 s ist Schluss');
    $probePfade = [];
    foreach ([['kaputt', null, null], [null, 'AU-1042', null], [null, null, 'mit leerzeichen'], [null, null, str_repeat('a', 65)]] as [$st, $jb, $se]) {
        pruefe(!EventworxProbe::lauf($st, $jb, $se)['ok'], 'Probe: Eingabe abgelehnt: ' . json_encode([$st, $jb, $se]));
    }
    gleich($probePfade, [], 'Probe: abgelehnte Eingaben fragen Eventworx nicht');
    gleich([EventworxProbe::art(null), EventworxProbe::art(true), EventworxProbe::art(12), EventworxProbe::art(1790000000000), EventworxProbe::art('2026-07-01'),
        EventworxProbe::art($oid(3)), EventworxProbe::art('Erika'), EventworxProbe::art([1, 2]), EventworxProbe::art(['a' => 1]), EventworxProbe::art(1.5)],
        ['null', 'bool', 'int', 'ms-Zeit', 'ISO-Zeit', 'ObjectId', 'Text(5)', 'Liste(2)', 'Objekt', 'Zahl'], 'Probe: Arten der Werte');
    gleich([EventworxProbe::pfadMuster('/job/documents/P-2026-017'), EventworxProbe::pfadMuster('/job/documents/Muster Events')],
        ['/job/documents/<nr>', '/job/documents/<text>'], 'Probe: Pfade ohne Nummern und Namen');
    // Kommandozeile: kaputte Eingaben lehnt sie ab, bevor sie Eventworx fragt (die Konfiguration
    // dieser Arbeitskopie gilt dort – ohne gültige Eingabe geht ohnehin nichts hinaus).
    $befehl = static function (string ...$argumente): array {
        $p = proc_open(array_merge([PHP_BINARY, APP_ROOT . '/bin/power'], $argumente), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
        $aus = stream_get_contents($rohre[1]) . stream_get_contents($rohre[2]);
        fclose($rohre[1]);
        fclose($rohre[2]);
        return [proc_close($p), (string) $aus];
    };
    [$code, $aus] = $befehl('eventworx:sonde', '--stock=kaputt');
    pruefe($code === 1 && str_contains($aus, '--stock braucht eine ObjectId'), 'bin/power eventworx:sonde --stock=kaputt → Exit 1 mit Meldung');
    [$code, $aus] = $befehl('eventworx:geraete-pruefen', '--job', 'kaputt');
    pruefe($code === 1 && str_contains($aus, '--job braucht eine ObjectId'), 'bin/power eventworx:geraete-pruefen --job kaputt → Exit 1 mit Meldung');
    [$code, $aus] = $befehl('eventworx:sonde', '--serie=a b', '--json');
    $j = json_decode($aus, true);
    pruefe($code === 1 && is_array($j) && ($j['ok'] ?? true) === false && ($j['anfragen'] ?? -1) === 0, 'bin/power … --json: maschinenlesbar, keine Anfrage');
    Http::$attrappe = static function (string $methode, string $url, array $optionen) use (&$anfragen, &$antworten, &$t, &$methoden): array {
        $anfragen[] = ['methode' => $methode, 'url' => $url, 'optionen' => $optionen, 'zeit' => $t];
        $methoden[$methode] = true;
        $antwort = array_shift($antworten);
        return $antwort ?? ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => 'keine Antwort vorbereitet'];
    };

    // ------------------------------------------------ Geheimnisse
    $antworten = [$json(200, ['success' => true, 'total' => 0, 'data' => []])];
    Eventworx::suchen($suchtext);
    foreach ($meldungen as $m) {
        pruefe(!str_contains($m, $token) && !str_contains($m, 'evil.example'), 'Meldung ohne Token und Ziel: ' . $m);
    }
    clearstatcache();
    $neu = is_file($logDatei) ? (string) file_get_contents($logDatei, false, null, $logAb) : '';
    pruefe(str_contains($neu, '"pfad":"job/<id>"') && str_contains($neu, '"code":"ACCESS_DENIED"'), 'Log mit Pfadmuster und Fehlercode');
    pruefe(!str_contains($neu, $id) && !str_contains($neu, $suchtext) && !str_contains($neu, 'AU-1042') && !str_contains($neu, 'Musterfrau'),
        'Log ohne Vorgangskennung, Suchtext und Kundendaten');
    pruefe(str_contains($neu, '"pfad":"file/list"') && str_contains($neu, '"pfad":"file/<id>"') && str_contains($neu, '"code":"KEIN_PDF"')
        && str_contains($neu, '"pfad":"stock/<id>"'), 'Log mit Pfadmustern der Dateien und der Probe');
    pruefe(!str_contains($neu, 'd00000000000000000000001') && !str_contains($neu, 'Söhne') && !str_contains($neu, 'Prüfprotokoll') && !str_contains($neu, $jobOid)
        && !str_contains($neu, 'GERAET-') && !str_contains($neu, 'SERIE-'), 'Log ohne Datei- und Vorgangskennung, Dateinamen, Gerätedaten');
    foreach (glob(APP_ROOT . '/storage/logs/*') ?: [] as $datei) {
        pruefe(!is_file($datei) || !str_contains((string) file_get_contents($datei), $token), 'Token nicht in ' . basename($datei));
    }
    gleich((int) Database::scalar('SELECT COUNT(*) FROM audit_log WHERE details LIKE ?', ['%' . $token . '%']), 0, 'Token nicht im Audit');
    pruefe(!str_contains((string) json_encode([Eventworx::zustand(), $treffer, $v]), $token), 'Token in keiner Antwort');

    // ------------------------------------------------ Demo
    Config::set('app.demo', true);
    $anfragen = [];
    $demo = Eventworx::suchen('au-1042');
    pruefe(($demo[0]['nummer'] ?? '') === 'AU-1042' && ($demo[0]['genau'] ?? false) === true, 'Demo: AU-1042 gefunden');
    $dv = Eventworx::vorgang((string) ($demo[0]['id'] ?? ''));
    gleich([$dv['gruppen']['kunde']['firma'], $dv['gruppen']['ansprechpartner']['ansprechpartner'], $dv['gruppen']['veranstaltung']['veranstaltung']],
        ['Muster Events GmbH & Co. KG', 'Frau Erika Musterfrau, Produktion', 'Sommerfestival 2026'], 'Demo: Vorgang dekodiert');
    gleich([$dv['verweis']['kunde_ref'], Eventworx::abbilden(Eventworx::demo()[1])['verweis']['kunde_ref']], ['65c3716b43f4c400000a0001', '65c3716b43f4c400000a0002'],
        'Demo: die beiden Vorgänge mit eigener Kunden-Kennung');
    pruefe(in_array('AN-1050-01', array_column(Eventworx::suchen('Musterstadt'), 'nummer'), true), 'Demo: Angebot AN-1050-01');
    pruefe(Eventworx::verbindungTesten()['ok'] && Eventworx::eingerichtet(), 'Demo: eingerichtet, Verbindungstest ohne Netz');
    pruefe($fehler(static fn () => Eventworx::vorgang('unbekanntervorgang1')) !== null, 'Demo: unbekannter Vorgang');
    $demoDok = Eventworx::dokumente((string) Eventworx::demo()[0]['id']);
    gleich(array_column($demoDok['dateien'], 'name'), ['DGUV V3 Prüfprotokolle Verteiler und Kabel 2026.pdf'], 'Demo: am Auftrag das hochgeladene Prüfprotokoll, nicht der Beleg');
    $demoPdf = Eventworx::datei((string) ($demoDok['dateien'][0]['id'] ?? ''));
    pruefe(str_starts_with($demoPdf, '%PDF-') && $demoPdf === Eventworx::datei((string) ($demoDok['dateien'][0]['id'] ?? ''))
        && (new finfo(FILEINFO_MIME_TYPE))->buffer($demoPdf) === 'application/pdf', 'Demo: die Datei ist ein PDF, zweimal dieselben Bytes');
    gleich(Eventworx::dokumente((string) Eventworx::demo()[1]['id'])['dateien'], [], 'Demo: am Angebot keine Dateien');
    pruefe(!EventworxProbe::lauf()['ok'], 'Demo: keine Lese-Probe');
    gleich($anfragen, [], 'Demo: die Attrappe wurde nie gerufen');
    Config::set('app.demo', false);

    gleich(array_keys($methoden), ['GET'], 'alle Anfragen mit GET');
} finally {
    Http::$attrappe = null;
    Eventworx::$uhr = null;
    Eventworx::$schlafen = null;
    alsBenutzer(null);
    foreach ($konfigVorher as $k => $wert) {
        Config::set($k, $wert);
    }
    foreach ($vorher as $s => $wert) {
        $wert === null ? Settings::forget($s) : Settings::put($s, $wert);
    }
    foreach ($testKunden as $k) {
        Database::execute('DELETE FROM pruefnachweise WHERE kunde_id = ?', [$k]);
        Database::execute('DELETE FROM kunden_vorgaenge WHERE kunde_id = ?', [$k]);
        Database::delete('kunden', $k);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'kunde' AND target_id = ?", [(string) $k]);
    }
    if ($ohneEwx !== null) {
        BenutzerService::kontoEntfernen($ohneEwx);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $ohneEwx]);
    }
    if ($rolleOhne !== null) {
        BenutzerService::rolleLoeschen($rolleOhne);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'role' AND target_id = ?", [(string) $rolleOhne]);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$kennung . '%', '%' . $kennung . '%']);
    if ($benutzer !== null) {
        BenutzerService::loeschen($benutzer);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $benutzer]);
    }
}

ende('eventworx');
