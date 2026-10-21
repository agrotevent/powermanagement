<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Controllers\AnlagenController;
use App\Controllers\ApiController;
use App\Controllers\AuditController;
use App\Controllers\AufbauController;
use App\Controllers\AuthController;
use App\Controllers\BereichController;
use App\Controllers\BerichtController;
use App\Controllers\BenutzerController;
use App\Controllers\DashboardController;
use App\Controllers\DatenquellenController;
use App\Controllers\InstanzenController;
use App\Controllers\InventarController;
use App\Controllers\EinstellungenController;
use App\Controllers\KarteController;
use App\Controllers\KatalogImportController;
use App\Controllers\KundenbereichController;
use App\Controllers\LocationController;
use App\Controllers\ManifestController;
use App\Controllers\PruefgeraeteController;
use App\Controllers\MqttZugaengeController;
use App\Controllers\SchnittstelleController;
use App\Controllers\StandortController;
use App\Controllers\StatistikController;
use App\Controllers\SteckerController;
use App\Controllers\SteuerungController;
use App\Controllers\VerteilerAbgleichController;
use App\Controllers\VorschlaegeController;
use App\Support\Request;
use App\Support\Router;
use App\Support\Session;

/*
 * Die Schnittstelle für andere Instanzen (/api/v1) läuft vor Sitzung und
 * Router: ohne Cookie, ohne CSRF-Token, ohne Weiterleitung auf die
 * Anmeldung – jede Anfrage meldet sich mit ihrem Token selbst an und
 * bekommt JSON zurück.
 */
$anfrage = new Request();
if (ApiController::zustaendig($anfrage->path())) {
    (new ApiController())->bearbeiten($anfrage);
    return;
}

Session::start();

$router = new Router();

// ---------------------------------------------------------------- Anmeldung
$router->get('/manifest', [ManifestController::class, 'manifest']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/profil', [AuthController::class, 'profile']);
$router->post('/profil/passwort', [AuthController::class, 'changePassword']);

// ---------------------------------------------------------------- Kundenbereich
/*
 * Nur für Kundenzugänge (users.kunde_id); Personal bekommt 403. Kein Recht an
 * der Route – Kundenzugänge haben keine Rechte; KundenbereichController::kunde()
 * prüft das Konto. Kundenzugänge erreichen nichts anderes (Tor im Router,
 * Kundenbereich::erlaubt). Was Kunden sehen, kommt aus Freigaben am Kunden
 * (nur Standorte) und aus ausdrücklicher Zuordnung – nie aus dem Standort
 * allein. Eventworx wird von hier aus nie gefragt.
 */
$router->get('/kundenbereich', [KundenbereichController::class, 'index']);
$router->get('/kundenbereich/konto', [KundenbereichController::class, 'konto']);
$router->post('/kundenbereich/konto/kennwort', [KundenbereichController::class, 'kennwort']);

// ---------------------------------------------------------------- Übersicht
$router->get('/', [DashboardController::class, 'start']);
$router->get('/dashboard', [DashboardController::class, 'index'], 'dashboard.view');
$router->get('/dashboard/live', [DashboardController::class, 'live'], 'dashboard.view');

// ---------------------------------------------------------------- Karte
/*
 * Die Karte ist der Grundriss aus MasterAdmin (dieselben Kacheln, dieselbe
 * Leaflet-Ansicht), belegt mit Anlagen, Kabeln, Kabelwegen und Kabelbrücken.
 * Lesen darf, wer die Karte sehen darf; die Anlagen darauf filtert
 * Zugriff nach den Freigaben.
 */
$router->get('/karte', [KarteController::class, 'index'], 'karte.view');
$router->get('/karte/{id}/stand', [KarteController::class, 'stand'], 'karte.view');
$router->get('/karte/{id}/live', [KarteController::class, 'live'], 'karte.view');
$router->get('/karte/{id}/material', [KarteController::class, 'material'], 'karte.view');
$router->get('/karte/{id}/geojson', [KarteController::class, 'geojson'], 'karte.view');
$router->get('/karte/objekt/{id}', [KarteController::class, 'objekt'], 'karte.view');
$router->get('/karte/leitung/{id}', [KarteController::class, 'leitung'], 'karte.view');
$router->post('/karte/objekt/setzen', [KarteController::class, 'objektSetzen'], 'anlagen.manage');
$router->post('/karte/objekt/verschieben', [KarteController::class, 'objektVerschieben'], 'anlagen.manage');
$router->post('/karte/objekt/entfernen', [KarteController::class, 'objektEntfernen'], 'anlagen.manage');
$router->post('/karte/leitung/speichern', [KarteController::class, 'leitungSpeichern'], 'leitungen.manage');
$router->post('/karte/leitung/verlauf', [KarteController::class, 'leitungVerlauf'], 'leitungen.manage');
$router->post('/karte/leitung/ende', [KarteController::class, 'leitungEnde'], 'leitungen.manage');
$router->post('/karte/leitung/loeschen', [KarteController::class, 'leitungLoeschen'], 'leitungen.manage');
$router->post('/karte/massstab', [KarteController::class, 'massstab'], 'plans.manage');
$router->post('/karte/georeferenz', [KarteController::class, 'georeferenz'], 'plans.manage');
$router->get('/grundriss/{id}/datei', [KarteController::class, 'planDatei'], 'karte.view');
$router->get('/grundriss/{id}/kachel/{z}/{x}/{y}', [KarteController::class, 'planKachel'], 'karte.view');

// ---------------------------------------------------------------- Anlagen
$router->get('/anlagen', [AnlagenController::class, 'index'], 'anlagen.view');
$router->get('/anlagen/baum', [AnlagenController::class, 'baum'], 'anlagen.view');
$router->get('/anlagen/katalog', [AnlagenController::class, 'katalog'], 'anlagen.view');
// Katalog importieren (Standard-Verteiler und Aggregate aus einer Datei):
// dazu alle Standorte, für neue Steckertypen „stecker.manage“ – prüft der Controller.
$router->get('/anlagen/katalog/import', [KatalogImportController::class, 'index'], 'anlagen.manage');
$router->post('/anlagen/katalog/import/vorschau', [KatalogImportController::class, 'vorschau'], 'anlagen.manage');
$router->post('/anlagen/katalog/import/uebernehmen', [KatalogImportController::class, 'uebernehmen'], 'anlagen.manage');
$router->post('/anlagen/katalog/import/verwerfen', [KatalogImportController::class, 'verwerfen'], 'anlagen.manage');
$router->get('/anlagen/neu', [AnlagenController::class, 'neu'], 'anlagen.manage');
$router->post('/anlagen/speichern', [AnlagenController::class, 'speichern'], 'anlagen.manage');
$router->get('/anlagen/{id}', [AnlagenController::class, 'show'], 'anlagen.view');
$router->get('/anlagen/{id}/druck', [AnlagenController::class, 'druck'], 'anlagen.view');
$router->get('/anlagen/{id}/live', [AnlagenController::class, 'live'], 'anlagen.view');
$router->get('/anlagen/{id}/bearbeiten', [AnlagenController::class, 'bearbeiten'], 'anlagen.manage');
$router->post('/anlagen/{id}/loeschen', [AnlagenController::class, 'loeschen'], 'anlagen.manage');
$router->post('/anlagen/{id}/kopieren', [AnlagenController::class, 'kopieren'], 'anlagen.manage');
$router->post('/anlagen/{id}/anschluesse', [AnlagenController::class, 'anschluesseAnzahl'], 'anlagen.manage');
$router->post('/anlagen/{id}/anschluesse/details', [AnlagenController::class, 'anschluesseDetails'], 'anlagen.manage');
$router->post('/anlagen/{id}/schutz', [AnlagenController::class, 'schutzSpeichern'], 'anlagen.manage');
$router->post('/anlagen/{id}/schutz/loeschen', [AnlagenController::class, 'schutzLoeschen'], 'anlagen.manage');
$router->post('/anlagen/{id}/schutz/vorschlag', [AnlagenController::class, 'schutzVorschlag'], 'anlagen.manage');
// Aufbau-Modus: Plan festhalten, vor Ort bestätigen, danach abgleichen.
$router->get('/aufbau', [AufbauController::class, 'index'], 'anlagen.view');
$router->post('/aufbau/starten', [AufbauController::class, 'starten'], 'anlagen.manage');
$router->get('/aufbau/{id}', [AufbauController::class, 'show'], 'anlagen.view');
$router->post('/aufbau/{id}/posten', [AufbauController::class, 'posten'], 'anlagen.manage');
$router->post('/aufbau/{id}/fertig', [AufbauController::class, 'fertig'], 'anlagen.manage');
$router->post('/aufbau/{id}/beenden', [AufbauController::class, 'beenden'], 'anlagen.manage');
$router->post('/aufbau/{id}/loeschen', [AufbauController::class, 'loeschen'], 'anlagen.manage');
$router->get('/aufbau/{id}/abgleich', [AufbauController::class, 'abgleich'], 'anlagen.view');
$router->get('/aufbau/{id}/druck', [AufbauController::class, 'druck'], 'anlagen.view');

// Übergabeberichte – das Recht prüft die Route, „woran“ BerichtService::sichtbar/darf*.
// Feste Pfade vor /berichte/{id}: {id} passt auf jedes Wort.
$router->get('/berichte', [BerichtController::class, 'index'], 'berichte.view');
$router->get('/berichte/neu', [BerichtController::class, 'neu'], 'berichte.manage');
$router->post('/berichte/anlegen', [BerichtController::class, 'anlegen'], 'berichte.manage');
$router->get('/berichte/pruefgeraete', [PruefgeraeteController::class, 'index'], 'berichte.view');
$router->post('/berichte/pruefgeraete/speichern', [PruefgeraeteController::class, 'speichern'], 'berichte.manage');
$router->get('/berichte/{id}', [BerichtController::class, 'show'], 'berichte.view');
$router->get('/berichte/{id}/pdf', [BerichtController::class, 'pdf'], 'berichte.view');
$router->post('/berichte/{id}/speichern', [BerichtController::class, 'speichern'], 'berichte.manage');
$router->post('/berichte/{id}/kreise', [BerichtController::class, 'kreise'], 'berichte.manage');
$router->post('/berichte/{id}/kunde-aus', [BerichtController::class, 'kundeAus'], 'berichte.manage');
$router->post('/berichte/{id}/eventworx/suche', [BerichtController::class, 'eventworxSuche'], 'berichte.eventworx');
$router->post('/berichte/{id}/eventworx/vorgang', [BerichtController::class, 'eventworxVorgang'], 'berichte.eventworx');
$router->post('/berichte/{id}/pruefen', [BerichtController::class, 'pruefen'], 'berichte.manage');
// Unterschriften: Prüfer friert ein und vergibt die Nummer; der Kunde unterschreibt im
// Kundenmodus am Gerät des Prüfers (der Router sperrt dann alles andere, siehe Kundenmodus).
$router->post('/berichte/{id}/unterschreiben', [BerichtController::class, 'unterschreiben'], 'berichte.pruefen');
$router->post('/berichte/{id}/zurueckziehen', [BerichtController::class, 'zurueckziehen'], 'berichte.pruefen');
$router->post('/berichte/{id}/kundenmodus', [BerichtController::class, 'kundenmodus'], 'berichte.manage');
$router->get('/berichte/{id}/kunde', [BerichtController::class, 'kunde'], 'berichte.manage');
$router->post('/berichte/{id}/kunde/unterschreiben', [BerichtController::class, 'kundeUnterschreiben'], 'berichte.manage');
$router->post('/berichte/{id}/kunde/beenden', [BerichtController::class, 'kundeBeenden'], 'berichte.manage');
$router->post('/berichte/{id}/ohne-kunde', [BerichtController::class, 'ohneKunde'], 'berichte.pruefen');
$router->post('/berichte/{id}/korrektur', [BerichtController::class, 'korrektur'], 'berichte.pruefen');
$router->post('/berichte/{id}/verwerfen', [BerichtController::class, 'verwerfen'], 'berichte.pruefen');
$router->post('/berichte/{id}/loeschen', [BerichtController::class, 'loeschen'], 'berichte.manage');

// Inventar: Verteiler und Zwischenstecker mit Shellys, Einsatz beim Aufbau.
$router->get('/inventar', [InventarController::class, 'index'], 'datenquellen.view');
$router->get('/inventar/neu', [InventarController::class, 'neu'], 'inventar.manage');
$router->post('/inventar/speichern', [InventarController::class, 'speichern'], 'inventar.manage');
$router->get('/inventar/{id}', [InventarController::class, 'show'], 'datenquellen.view');
$router->get('/inventar/{id}/bearbeiten', [InventarController::class, 'bearbeiten'], 'inventar.manage');
$router->post('/inventar/{id}/loeschen', [InventarController::class, 'loeschen'], 'inventar.manage');
$router->post('/anlagen/{id}/einsetzen', [InventarController::class, 'einsetzen'], 'anlagen.manage');
$router->post('/einsatz/{id}/beenden', [InventarController::class, 'beenden'], 'anlagen.manage');
$router->post('/anlagen/{id}/messpunkt', [AnlagenController::class, 'messpunktSpeichern'], 'anlagen.manage');
$router->post('/anlagen/{id}/messpunkt/loeschen', [AnlagenController::class, 'messpunktLoeschen'], 'anlagen.manage');

$router->get('/stecker', [SteckerController::class, 'index'], 'stecker.manage');
$router->post('/stecker/speichern', [SteckerController::class, 'speichern'], 'stecker.manage');
$router->post('/stecker/loeschen', [SteckerController::class, 'loeschen'], 'stecker.manage');

// ---------------------------------------------------------------- Standorte
$router->get('/standorte', [StandortController::class, 'index'], 'plans.manage');
$router->post('/standorte/speichern', [StandortController::class, 'speichern'], 'plans.manage');
$router->post('/standorte/loeschen', [StandortController::class, 'loeschen'], 'plans.manage');
$router->post('/standorte/etage', [StandortController::class, 'etageSpeichern'], 'plans.manage');
$router->post('/standorte/etage/loeschen', [StandortController::class, 'etageLoeschen'], 'plans.manage');
$router->post('/standorte/grundriss', [StandortController::class, 'grundriss'], 'plans.manage');

$router->get('/location', [LocationController::class, 'index'], 'inventory.pull');
$router->post('/location/uebernehmen', [LocationController::class, 'uebernehmen'], 'inventory.pull');
$router->post('/location/hochladen', [LocationController::class, 'hochladen'], 'inventory.push');
$router->get('/verteiler-abgleich', [VerteilerAbgleichController::class, 'index'], 'inventory.pull');
$router->post('/verteiler-abgleich/uebernehmen', [VerteilerAbgleichController::class, 'uebernehmen'], 'inventory.pull');
$router->post('/verteiler-abgleich/hochladen', [VerteilerAbgleichController::class, 'hochladen'], 'inventory.push');
$router->post('/verteiler-abgleich/entfernen', [VerteilerAbgleichController::class, 'entfernen'], 'inventory.push');

// ---------------------------------------------------------------- Auswertung
$router->get('/statistik', [StatistikController::class, 'index'], 'statistik.view');
$router->get('/statistik/daten', [StatistikController::class, 'daten'], 'statistik.view');
$router->get('/statistik/csv', [StatistikController::class, 'csv'], 'statistik.export');
$router->post('/auswertung/umschalten', [StatistikController::class, 'umschalten'], 'statistik.view');
$router->post('/auswertung/leeren', [StatistikController::class, 'leeren'], 'statistik.view');

// Bereiche: Last mehrerer Anlagen und Abgänge zusammen, Materialliste.
$router->get('/bereiche', [BereichController::class, 'index'], 'anlagen.view');
$router->get('/bereiche/live', [BereichController::class, 'live'], 'anlagen.view');
$router->get('/bereiche/neu', [BereichController::class, 'neu'], 'anlagen.manage');
$router->post('/bereiche/speichern', [BereichController::class, 'speichern'], 'anlagen.manage');
$router->get('/bereiche/{id}', [BereichController::class, 'show'], 'anlagen.view');
$router->get('/bereiche/{id}/bearbeiten', [BereichController::class, 'bearbeiten'], 'anlagen.manage');
$router->post('/bereiche/{id}/loeschen', [BereichController::class, 'loeschen'], 'anlagen.manage');
$router->get('/bereiche/{id}/daten', [BereichController::class, 'daten'], 'statistik.view');
$router->get('/bereiche/{id}/csv', [BereichController::class, 'csv'], 'statistik.export');
$router->get('/bereiche/{id}/material', [BereichController::class, 'material'], 'anlagen.view');

// ---------------------------------------------------------------- Messtechnik
$router->get('/datenquellen', [DatenquellenController::class, 'index'], 'datenquellen.view');
$router->post('/datenquellen/speichern', [DatenquellenController::class, 'speichern'], 'datenquellen.manage');
$router->post('/datenquellen/loeschen', [DatenquellenController::class, 'loeschen'], 'datenquellen.manage');
$router->post('/datenquellen/testen', [DatenquellenController::class, 'testen'], 'datenquellen.manage');
$router->post('/datenquellen/comap-einheiten', [DatenquellenController::class, 'comapEinheiten'], 'datenquellen.manage');
$router->get('/datenquellen/profile', [DatenquellenController::class, 'profile'], 'datenquellen.view');
$router->get('/datenquellen/profile/{id}', [DatenquellenController::class, 'profil'], 'datenquellen.view');
$router->post('/datenquellen/profile/speichern', [DatenquellenController::class, 'profilSpeichern'], 'datenquellen.manage');
$router->post('/datenquellen/profile/loeschen', [DatenquellenController::class, 'profilLoeschen'], 'datenquellen.manage');
// Eigene MQTT-Zugänge der Shellys: ändern nur mit „mqtt.zugaenge“ und allen
// Geräten (prüft der Controller).
$router->get('/datenquellen/mqtt-zugaenge', [MqttZugaengeController::class, 'index'], 'datenquellen.view');
foreach (['einrichten', 'uebertragen', 'pruefen', 'erneuern', 'entfernen'] as $aktion) {
    $router->post('/datenquellen/mqtt-zugaenge/' . $aktion, [MqttZugaengeController::class, $aktion], 'mqtt.zugaenge');
}

// Schalten: das Recht allein genügt nicht – der Controller prüft zusätzlich
// die Schalt-Freigabe für genau dieses Gerät (Zugriff::darfSteuern).
$router->post('/steuerung/schalten', [SteuerungController::class, 'schalten'], 'steuern');
$router->get('/steuerung/protokoll', [SteuerungController::class, 'protokoll'], 'steuern.protokoll');

// ---------------------------------------------------------------- System
$router->get('/system/benutzer', [BenutzerController::class, 'index'], 'system.users.manage');
$router->post('/system/benutzer/speichern', [BenutzerController::class, 'speichern'], 'system.users.manage');
$router->post('/system/benutzer/loeschen', [BenutzerController::class, 'loeschen'], 'system.users.manage');
$router->post('/system/benutzer/entsperren', [BenutzerController::class, 'entsperren'], 'system.users.manage');
$router->get('/system/freigaben', [BenutzerController::class, 'freigaben'], 'system.users.manage');
$router->post('/system/freigaben/speichern', [BenutzerController::class, 'freigabeSpeichern'], 'system.users.manage');
$router->post('/system/freigaben/loeschen', [BenutzerController::class, 'freigabeLoeschen'], 'system.users.manage');
$router->get('/system/rollen', [BenutzerController::class, 'rollen'], 'system.users.manage');
$router->post('/system/rollen/speichern', [BenutzerController::class, 'rolleSpeichern'], 'system.users.manage');
$router->post('/system/rollen/loeschen', [BenutzerController::class, 'rolleLoeschen'], 'system.users.manage');

$router->get('/system/einstellungen', [EinstellungenController::class, 'index'], 'settings.manage');
$router->post('/system/einstellungen/speichern', [EinstellungenController::class, 'speichern'], 'settings.manage');
$router->post('/system/einstellungen/pruefen', [EinstellungenController::class, 'pruefen'], 'settings.manage');
// Adresse und Token des Location-Repositories setzt, wer hochladen darf.
// Adresse und Zweig des Repositorys: nur mit „Einstellungen ändern“ – wer die
// Adresse umstellt, lenkt das hinterlegte Token dorthin.
$router->post('/system/einstellungen/location', [EinstellungenController::class, 'location'], 'settings.manage');
// Firma & Übergabeberichte: Firmendaten für Kopf und Fuß, Nummernkürzel, Vorgaben, Texte.
$router->post('/system/einstellungen/firma', [EinstellungenController::class, 'firma'], 'settings.manage');
// Eventworx (Kundendaten für Übergabeberichte, nur lesend): Mandant, Token, Verbindungstest.
$router->post('/system/einstellungen/eventworx', [EinstellungenController::class, 'eventworx'], 'settings.manage');
$router->post('/system/einstellungen/eventworx/pruefen', [EinstellungenController::class, 'eventworxPruefen'], 'settings.manage');

// Schnittstelle (API): Zugänge für andere Instanzen. Anlegen, Ändern,
// Anzeigen und Löschen zusätzlich nur mit „Alle Standorte …“ (prüft der
// Controller); widerrufen darf jeder mit dem Recht – das nimmt nur weg.
$router->get('/system/schnittstelle', [SchnittstelleController::class, 'index'], 'api.manage');
$router->post('/system/schnittstelle/anlegen', [SchnittstelleController::class, 'anlegen'], 'api.manage');
$router->get('/system/schnittstelle/{id}', [SchnittstelleController::class, 'zugang'], 'api.manage');
foreach (['speichern', 'anzeigen', 'widerrufen', 'loeschen'] as $aktion) {
    $router->post('/system/schnittstelle/{id}/' . $aktion, [SchnittstelleController::class, $aktion], 'api.manage');
}

// Andere Instanzen (Seite B): Locations von dort übernehmen, zuordnen,
// verknüpfen, lösen und zurückschicken – woran, prüft PartnerAbgleich
// (Standort mit „bearbeiten“, Pläne, Anlagen, Kabel, Steckertypen).
$router->get('/instanzen', [InstanzenController::class, 'index'], 'api.partner');
$router->post('/instanzen/anlegen', [InstanzenController::class, 'anlegen'], 'api.partner');
$router->get('/instanzen/{id}', [InstanzenController::class, 'instanz'], 'api.partner');
$router->get('/instanzen/{id}/zuordnen', [InstanzenController::class, 'zuordnen'], 'api.partner');
foreach (['pruefen', 'loeschen', 'uebernehmen', 'loesen', 'zurueck'] as $aktion) {
    $router->post('/instanzen/{id}/' . $aktion, [InstanzenController::class, $aktion], 'api.partner');
}

// Änderungsvorschläge anderer Instanzen (Seite A): sehen nur mit dem ganzen
// Standort, annehmen nur mit „bearbeiten“ – prüft VorschlagService.
$router->get('/vorschlaege', [VorschlaegeController::class, 'index'], 'api.vorschlaege');
$router->get('/vorschlaege/{id}', [VorschlaegeController::class, 'vorschlag'], 'api.vorschlaege');
$router->get('/vorschlaege/{id}/datei', [VorschlaegeController::class, 'datei'], 'api.vorschlaege');
foreach (['annehmen', 'ablehnen'] as $aktion) {
    $router->post('/vorschlaege/{id}/' . $aktion, [VorschlaegeController::class, $aktion], 'api.vorschlaege');
}

$router->get('/audit', [AuditController::class, 'index'], 'audit.view');

$router->dispatch($anfrage);
