<?php
declare(strict_types=1);

namespace App\Berichte;

/**
 * Was ein Übergabebericht abfragt: Prüfpunkte der Besichtigung und
 * Erprobung, Normen, Gründe, Netzformen.
 *
 * Die Schlüssel bleiben stabil – sie stehen in jedem Bericht. Die Texte
 * dürfen sich ändern; beim Einfrieren kopiert der Bericht sie (`labels`),
 * damit ein alter Bericht auch nach einer neuen Fassung dieses Katalogs
 * gleich aussieht. Ändert sich der Inhalt fachlich (neue Norm), steigt
 * VERSION.
 *
 * `kurz` = Kurzliste, immer sichtbar; die übrigen Punkte sind aufklappbar.
 * `quelle` nennt, woher der Punkt kommt (Norm bzw. Vorlage).
 */
final class Katalog
{
    public const VERSION = 1;

    /** @return array<string, array{text:string, kurz:bool, quelle:string}> */
    public static function besichtigung(): array
    {
        return [
            'auswahl'                => ['text' => 'Richtige Auswahl der Betriebsmittel', 'kurz' => true, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'maengelfrei'            => ['text' => 'Betriebsmittel ohne sichtbare Schäden', 'kurz' => true, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'verlegung'              => ['text' => 'Leitungsverlegung ordnungsgemäß und geschützt (Fluchtwege, Kabelbrücken)', 'kurz' => true, 'quelle' => 'DIN VDE 0100-711'],
            'kennzeichnung'          => ['text' => 'Kennzeichnung der Stromkreise und Verteiler vorhanden', 'kurz' => true, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'rcd30'                  => ['text' => 'Zusätzlicher Schutz: RCD ≤ 30 mA für Endstromkreise ≤ 32 A', 'kurz' => true, 'quelle' => 'DIN VDE 0100-711'],
            'potentialausgleich'     => ['text' => 'Schutzpotentialausgleich vorhanden', 'kurz' => true, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'zus_potentialausgleich' => ['text' => 'Zusätzlicher (örtlicher) Schutzpotentialausgleich', 'kurz' => true, 'quelle' => 'DIN VDE 0100-711'],
            'schutzart'              => ['text' => 'Schutzart passend zum Einsatzort (im Freien mindestens IP44)', 'kurz' => true, 'quelle' => 'DIN VDE 0100-711'],
            'leitungsbauart'         => ['text' => 'Leitungen für den Einsatz geeignet (z. B. H07RN-F)', 'kurz' => false, 'quelle' => 'DIN VDE 0100-711'],
            'geraetepruefung'        => ['text' => 'Wiederholungsprüfung der ortsveränderlichen Betriebsmittel gültig', 'kurz' => true, 'quelle' => 'DGUV Vorschrift 3 § 5'],
            'herstellerangaben'      => ['text' => 'Betriebsmittel nach Herstellerangaben installiert', 'kurz' => false, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'beruehrungsschutz'      => ['text' => 'Schutz gegen direktes Berühren (Basisschutz)', 'kurz' => false, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'verdrahtung'            => ['text' => 'Verdrahtung und Installation fachgerecht', 'kurz' => false, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'dokumentation'          => ['text' => 'Übereinstimmung von Ausrüstung und Dokumentation', 'kurz' => false, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'bedienelemente'         => ['text' => 'Kennzeichnung der Bedienelemente dauerhaft', 'kurz' => false, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'warnschilder'           => ['text' => 'Warnschilder vorhanden', 'kurz' => false, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'sicherheitseinrichtungen' => ['text' => 'Sicherheitseinrichtungen vorhanden (höchste Sicherheitskategorie angeben)', 'kurz' => false, 'quelle' => 'DIN EN 60204-1'],
            'selv'                   => ['text' => 'Sichere Trennung SELV/PELV von anderen Stromkreisen', 'kurz' => false, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'einstellwerte'          => ['text' => 'Einstellwerte der Schutzeinrichtungen', 'kurz' => false, 'quelle' => 'DIN VDE 0100-600 6.4.2'],
            'klima'                  => ['text' => 'Filter und Klimatisierung der Schaltanlagen', 'kurz' => false, 'quelle' => 'DIN EN 60204-1'],
        ];
    }

    /** @return array<string, array{text:string, kurz:bool, quelle:string}> */
    public static function erprobung(): array
    {
        return [
            'funktion_anlage'     => ['text' => 'Funktion der elektrischen Anlage', 'kurz' => true, 'quelle' => 'DIN VDE 0100-600 6.4.3.10'],
            'drehfeld'            => ['text' => 'Rechtsdrehfeld der Drehstrom-Steckdosen', 'kurz' => true, 'quelle' => 'DIN VDE 0100-600 6.4.3.9'],
            'rcd'                 => ['text' => 'Funktion der RCD (Prüftaste und Auslösung)', 'kurz' => true, 'quelle' => 'DIN VDE 0100-600 6.4.3.7'],
            'trenneinrichtung'    => ['text' => 'Funktion der Trenneinrichtung (Hauptschalter, Not-Aus)', 'kurz' => true, 'quelle' => 'DIN VDE 0100-600 6.4.3.10'],
            'schutzeinrichtungen' => ['text' => 'Funktion der Schutz-, Sicherheits- und Überwachungseinrichtungen', 'kurz' => false, 'quelle' => 'DIN VDE 0100-600 6.4.3.10'],
            'durchgaengigkeit'    => ['text' => 'Durchgängigkeit der Schutzleiter und des Potentialausgleichs', 'kurz' => false, 'quelle' => 'DIN VDE 0100-600 6.4.3.2'],
            'motoren'             => ['text' => 'Drehrichtung der Motoren', 'kurz' => false, 'quelle' => 'DIN VDE 0100-600 6.4.3.10'],
            'bus'                 => ['text' => 'Durchgängigkeit und Polarität der Busleitungen', 'kurz' => false, 'quelle' => 'DIN EN 60204-1'],
            'imd'                 => ['text' => 'Isolationsüberwachung (IMD) im IT-System', 'kurz' => false, 'quelle' => 'DIN VDE 0100-410'],
        ];
    }

    /** @return array<string,string> Schlüssel → Text */
    public static function normen(): array
    {
        return [
            '0100-600' => 'DIN VDE 0100-600 (Erstprüfung)',
            '0100-711' => 'DIN VDE 0100-711 (Ausstellungen, Shows, Stände)',
            '0100-740' => 'DIN VDE 0100-740 (Vergnügungseinrichtungen, Märkte, Zirkusse)',
            '0100-551' => 'DIN VDE 0100-551 (Stromerzeugungsanlagen)',
            '0105-100' => 'DIN VDE 0105-100 (Betrieb, Wiederholungsprüfung)',
            '0113-1'   => 'DIN EN 60204-1 (VDE 0113-1)',
            'dguv-v3'  => 'DGUV Vorschrift 3',
        ];
    }

    /** @return array<string,string> */
    public static function gruende(): array
    {
        return [
            'aufbau'         => 'Aufbau (Erstprüfung)',
            'neuanlage'      => 'Neuanlage',
            'erweiterung'    => 'Erweiterung',
            'aenderung'      => 'Änderung',
            'instandsetzung' => 'Instandsetzung',
            'wiederholung'   => 'Wiederholungsprüfung',
        ];
    }

    /** @return array<string,string> */
    public static function netzformen(): array
    {
        return [
            'TN-S'   => 'TN-S',
            'TN-C'   => 'TN-C',
            'TN-C-S' => 'TN-C-S',
            'TT'     => 'TT',
            'IT'     => 'IT',
        ];
    }

    /** @return array<string,string> */
    public static function einspeisungen(): array
    {
        return [
            'generator' => 'Generator',
            'fest'      => 'Feststromanschluss',
            'speicher'  => 'Speicher/Wechselrichter',
            'hybrid'    => 'Hybrid (Generator und Speicher)',
            'sonstige'  => 'Sonstige',
        ];
    }

    /** @return array<string,string> Zustand eines Prüfpunkts; '' = nicht geprüft */
    public static function zustaende(): array
    {
        return [
            'io'   => 'i.O.',
            'nio'  => 'n.i.O.',
            'entf' => 'entfällt',
            ''     => 'nicht geprüft',
        ];
    }

    /** @return array<string,array{text:string,pflicht:bool}> Häkchen des Kunden */
    public static function bestaetigungen(): array
    {
        return [
            'erhalten'    => ['text' => 'Bericht erhalten', 'pflicht' => true],
            'eingewiesen' => ['text' => 'Einweisung erhalten', 'pflicht' => false],
            'uebernommen' => ['text' => 'Anlage übernommen', 'pflicht' => false],
        ];
    }

    /** Text eines Prüfpunkts (Besichtigung oder Erprobung) – für Meldungen. */
    public static function punktText(string $schluessel): string
    {
        return self::besichtigung()[$schluessel]['text'] ?? self::erprobung()[$schluessel]['text'] ?? $schluessel;
    }
}
