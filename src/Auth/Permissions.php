<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * Katalog aller Rechte – Grundlage der Rollenverwaltung.
 *
 * Die Rechte sagen, **was** jemand tun darf. **Woran** (welcher Standort,
 * welche Anlage, welches Gerät) und **wann** (Gültigkeit, sichtbarer
 * Datenzeitraum) regeln die Freigaben in Zugriff – es sei denn, jemand hat
 * `anlagen.scope.all`, dann gilt alles ohne Grenze.
 */
final class Permissions
{
    public const ALL = [
        'Übersicht' => [
            'dashboard.view'     => 'Übersicht und Live-Werte ansehen (nur freigegebene Anlagen)',
        ],
        'Anlagen' => [
            'anlagen.view'       => 'Anschlüsse, Generatoren, Wechselrichter und Verteilungen ansehen (nur freigegebene)',
            'anlagen.manage'     => 'Anlagen anlegen, ändern, löschen und ihre Anschlüsse dokumentieren (nur freigegebene mit „bearbeiten“)',
            'anlagen.scope.all'  => 'Alle Standorte, Anlagen und Messdaten ohne Freigabe und ohne Zeitgrenze',
            'stecker.manage'     => 'Steckverbinder-Katalog pflegen (Powerlock, CEE, Schuko …)',
            'inventar.manage'    => 'Inventar pflegen: Verteiler und Zwischenstecker mit ihren Shellys (ansehen: „Datenquellen ansehen“)',
        ],
        'Übergabeberichte' => [
            'berichte.view'      => 'Übergabeberichte ansehen und als PDF laden (ohne „ausfüllen“ nur abgeschlossene, nur mit Freigabe für den Standort und im freigegebenen Datenzeitraum)',
            'berichte.manage'    => 'Übergabeberichte anlegen, ausfüllen und vom Kunden unterschreiben lassen; Prüfgeräte pflegen (nur Standorte mit „bearbeiten“)',
            'berichte.pruefen'   => 'Als Prüfer unterschreiben, ohne Kunde abschließen, zurückziehen, Korrektur anlegen (nur mit hinterlegter Qualifikation)',
            'berichte.eventworx' => 'Kundendaten aus Eventworx suchen und übernehmen (Eventworx zeigt dort alle Vorgänge)',
        ],
        // Für Kundenzugänge selbst gibt es kein Recht: sie haben keine Rolle
        // und keine Rechte (Auth::permissions() = []), das Tor im Router
        // lässt sie nur in den Kundenbereich (App\Kunden\Kundenbereich).
        'Kunden & Kundenbereich' => [
            'kunden.view'        => 'Kunden mit ihren Protokollen, Prüfnachweisen und Eventworx-Vorgängen ansehen (Zugänge, Freigaben und Abrufe nur mit „Kunden pflegen“)',
            'kunden.manage'      => 'Kunden und Kundenzugänge pflegen, Übergabeberichte zuordnen, Vorgänge verknüpfen; Standorte freigeben und Zugänge ausgeben nur mit „Alle Standorte, Anlagen und Messdaten“',
            'kunden.nachweise'   => 'Prüfnachweise (DGUV V3) hochladen, aus Eventworx übernehmen, freigeben und zurückziehen',
        ],
        'Karte & Standorte' => [
            'karte.view'         => 'Karte mit Grundriss ansehen',
            'plans.manage'       => 'Standorte, Etagen und Grundrisse pflegen, Maßstab setzen',
            'leitungen.manage'   => 'Kabel, Kabelwege und Kabelbrücken einzeichnen und verbinden',
        ],
        'Messdaten' => [
            'statistik.view'     => 'Statistiken und Verläufe ansehen (im freigegebenen Datenzeitraum)',
            'statistik.export'   => 'Messdaten als CSV exportieren',
            'datenquellen.view'  => 'Datenquellen, Geräte und Registerprofile ansehen',
            'datenquellen.manage'=> 'Datenquellen anlegen, Zugänge hinterlegen, Messpunkte zuordnen',
            'mqtt.zugaenge'      => 'Eigene MQTT-Zugänge der Shellys einrichten, prüfen und entfernen (nur zusammen mit „Alle Standorte, Anlagen und Messdaten“)',
        ],
        'Steuerung' => [
            // Schalten ist ein Eingriff in den laufenden Betrieb: ein
            // falscher Klick nimmt einer Bühne den Strom. Deshalb braucht
            // es zusätzlich eine Freigabe mit „schalten“ – je Standort,
            // Anlage oder einzelnem Gerät.
            'steuern'            => 'Geräte schalten (Ein/Aus, Start/Stopp) – nur mit Schalt-Freigabe',
            'steuern.protokoll'  => 'Protokoll aller Schaltvorgänge ansehen',
        ],
        'Location- und Verteiler-Abgleich' => [
            'inventory.pull'     => 'Locations und Verteiler aus dem gemeinsamen Repository übernehmen (Verteiler: nur mit dem Recht, Katalog bzw. Inventar zu pflegen)',
            'inventory.push'     => 'Locations, Feststromanschlüsse und Verteiler ins Repository hochladen und dort entfernen',
        ],
        'Schnittstelle (API)' => [
            // Was ein Token von außen sieht, legen seine Befugnisse und
            // Standorte fest (ApiZugang) – nicht die Freigaben in Zugriff.
            // Deshalb gibt Standorte nur frei, wer selbst alles sieht.
            'api.manage'         => 'Zugänge (Tokens) für andere Instanzen anlegen, anzeigen und widerrufen – Standorte freigeben nur mit „Alle Standorte, Anlagen und Messdaten“',
            'api.partner'        => 'Andere Instanzen eintragen, Locations von dort übernehmen und Änderungen zurückschicken (nur auf Standorten, die man ändern darf)',
            'api.vorschlaege'    => 'Änderungsvorschläge anderer Instanzen ansehen, annehmen und ablehnen (annehmen nur mit „bearbeiten“ am Standort)',
        ],
        'System' => [
            'system.users.manage'=> 'Benutzer, Rollen und Freigaben verwalten',
            'settings.manage'    => 'Systemeinstellungen ändern (InfluxDB, MQTT, Repository)',
            'audit.view'         => 'Audit-Log ansehen',
            'sso.manage'         => 'Anmeldung über SSO (OpenID Connect, etwa Authentik) einrichten: Verbindung, Gruppen → Rollen, Anmeldeanfragen – Rollen zuordnen und Konten freischalten nur, wer sie selbst vergeben bzw. verwalten darf; Verwaltung per SSO, MFA und „nur SSO“ nur mit allen Rechten',
            'system.update'      => 'PowerManagement aktualisieren und automatische Updates einstellen (startet Dienste neu – Messung und Schalten kurz unterbrochen)',
        ],
    ];

    /** @return array<int,string> */
    public static function keys(): array
    {
        $out = [];
        foreach (self::ALL as $group) {
            foreach ($group as $key => $_label) {
                $out[] = $key;
            }
        }
        return $out;
    }

    public static function label(string $key): string
    {
        foreach (self::ALL as $group) {
            if (isset($group[$key])) {
                return $group[$key];
            }
        }
        return $key;
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * Die Rollen, die bei der Installation entstehen.
     *
     * @return array<string,array{name:string,description:string,permissions:list<string>}>
     */
    public static function standardRollen(): array
    {
        return [
            'admin' => [
                'name'        => 'Administration',
                'description' => 'Darf alles.',
                'permissions' => ['*'],
            ],
            'planung' => [
                'name'        => 'Stromplanung',
                'description' => 'Plant Verteilungen, Kabelwege und Anschlüsse auf allen Standorten.',
                'permissions' => [
                    'dashboard.view', 'anlagen.view', 'anlagen.manage', 'anlagen.scope.all', 'stecker.manage',
                    'karte.view', 'plans.manage', 'leitungen.manage', 'statistik.view', 'statistik.export',
                    'datenquellen.view', 'inventory.pull', 'inventar.manage',
                    'berichte.view', 'berichte.manage', 'berichte.eventworx',
                    'kunden.view', 'kunden.manage', 'kunden.nachweise',
                ],
            ],
            'technik' => [
                'name'        => 'Elektrotechnik vor Ort',
                'description' => 'Sieht und dokumentiert die freigegebenen Standorte, schaltet mit Schalt-Freigabe.',
                'permissions' => [
                    'dashboard.view', 'anlagen.view', 'anlagen.manage', 'karte.view', 'leitungen.manage',
                    'statistik.view', 'datenquellen.view', 'steuern', 'steuern.protokoll',
                    'berichte.view', 'berichte.manage', 'berichte.eventworx', 'berichte.pruefen',
                    'kunden.view', 'kunden.nachweise',
                ],
            ],
            // Keine Übergabeberichte: ein Gelände hat mehrere Veranstalter,
            // und Berichte enthalten Kundendaten. Wer sie sehen soll, bekommt
            // „berichte.view“ einzeln – mit Freigabe und Datenzeitraum.
            // Auch nichts aus „Kunden & Kundenbereich“: Kunden mit eigenem
            // Zugang (Kundenbereich) haben gar keine Rolle; diese Rolle ist
            // der ältere Weg über Freigaben am Konto.
            'kunde' => [
                'name'        => 'Kunde / Veranstalter',
                'description' => 'Sieht Anlagen und Statistiken der freigegebenen Standorte im freigegebenen Zeitraum.',
                'permissions' => ['dashboard.view', 'anlagen.view', 'karte.view', 'statistik.view', 'statistik.export'],
            ],
        ];
    }
}
