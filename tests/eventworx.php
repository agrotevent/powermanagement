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
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Messung\Http;
use App\Services\BenutzerService;
use App\Services\Eventworx;
use App\Services\EventworxFehler;
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
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$kennung . '%', '%' . $kennung . '%']);
    if ($benutzer !== null) {
        BenutzerService::loeschen($benutzer);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $benutzer]);
    }
}

ende('eventworx');
