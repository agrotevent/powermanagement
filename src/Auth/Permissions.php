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
        ],
        'Steuerung' => [
            // Schalten ist ein Eingriff in den laufenden Betrieb: ein
            // falscher Klick nimmt einer Bühne den Strom. Deshalb braucht
            // es zusätzlich eine Freigabe mit „schalten“ – je Standort,
            // Anlage oder einzelnem Gerät.
            'steuern'            => 'Geräte schalten (Ein/Aus, Start/Stopp) – nur mit Schalt-Freigabe',
            'steuern.protokoll'  => 'Protokoll aller Schaltvorgänge ansehen',
        ],
        'Location-Abgleich' => [
            'inventory.pull'     => 'Locations aus dem gemeinsamen Repository übernehmen',
            'inventory.push'     => 'Locations und Feststromanschlüsse ins Repository hochladen',
        ],
        'System' => [
            'system.users.manage'=> 'Benutzer, Rollen und Freigaben verwalten',
            'settings.manage'    => 'Systemeinstellungen ändern (InfluxDB, MQTT, Repository)',
            'audit.view'         => 'Audit-Log ansehen',
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
                    'datenquellen.view', 'inventory.pull',
                ],
            ],
            'technik' => [
                'name'        => 'Elektrotechnik vor Ort',
                'description' => 'Sieht und dokumentiert die freigegebenen Standorte, schaltet mit Schalt-Freigabe.',
                'permissions' => [
                    'dashboard.view', 'anlagen.view', 'anlagen.manage', 'karte.view', 'leitungen.manage',
                    'statistik.view', 'datenquellen.view', 'steuern', 'steuern.protokoll',
                ],
            ],
            'kunde' => [
                'name'        => 'Kunde / Veranstalter',
                'description' => 'Sieht Anlagen und Statistiken der freigegebenen Standorte im freigegebenen Zeitraum.',
                'permissions' => ['dashboard.view', 'anlagen.view', 'karte.view', 'statistik.view', 'statistik.export'],
            ],
        ];
    }
}
