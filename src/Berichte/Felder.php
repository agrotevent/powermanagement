<?php
declare(strict_types=1);

namespace App\Berichte;

use App\Support\View;

/**
 * Eingabefelder des Berichtsformulars.
 *
 * Der Zustand des Formulars steht in den Feldern selbst: jedes trägt in
 * `data-feld` seinen Pfad im Inhalt („kopf.kunde.firma“, in einer Zeile
 * relativ: „w.zs.w“). bericht.js baut daraus das JSON fürs Autosave –
 * kein JSON in der Seite, kein Inline-JavaScript.
 *
 * Zahlen stehen im Inhalt als Dezimaltext mit Punkt („2.5“); im Formular
 * mit Komma, wie man sie eintippt. Der Server liest beides.
 *
 * IDs: „b-kopf-kunde-firma“ oben, „k-<zeile>-w-zs-w“ in einer Zeile. In
 * der Vorlage für neue Zeilen steht „__Z__“; bericht.js setzt dort den
 * Zeilenschlüssel ein.
 */
final class Felder
{
    public const VORLAGE = '__Z__';

    /** Zahlen mit Komma anzeigen – nur, was wirklich eine Zahl ist. */
    public static function komma(string $wert): string
    {
        return preg_match('/^\d+(\.\d+)?$/', $wert) === 1 ? str_replace('.', ',', $wert) : $wert;
    }

    public static function id(string $pfad, string $z = ''): string
    {
        $teil = (string) preg_replace('/[^a-z0-9_-]+/i', '-', $pfad);
        return $z === '' ? 'b-' . $teil : 'k-' . $z . '-' . $teil;
    }

    /**
     * Ein Text- oder Zahlenfeld mit Beschriftung.
     *
     * @param array{z?:string,typ?:string,mehrzeilig?:bool,zahl?:bool,hilfe?:string,platzhalter?:string,klasse?:string,max?:int,einheit?:string,abgeleitet?:bool,zeilen?:int,auto?:string} $o
     */
    public static function text(string $pfad, string $wert, string $label, array $o = []): string
    {
        $z = (string) ($o['z'] ?? '');
        $id = self::id($pfad, $z);
        $attr = ' id="' . View::e($id) . '" data-feld="' . View::e($pfad) . '"';
        if (isset($o['max'])) {
            $attr .= ' maxlength="' . (int) $o['max'] . '"';
        }
        if (($o['platzhalter'] ?? '') !== '') {
            $attr .= ' placeholder="' . View::e((string) $o['platzhalter']) . '"';
        }
        if (!empty($o['zahl'])) {
            $wert = self::komma($wert);
            $attr .= ' inputmode="decimal" enterkeyhint="next" autocomplete="off"';
        } elseif (($o['auto'] ?? '') !== '') {
            $attr .= ' autocomplete="' . View::e((string) $o['auto']) . '"';
        }
        if (!empty($o['mehrzeilig'])) {
            $feld = '<textarea' . $attr . ' rows="' . (int) ($o['zeilen'] ?? 3) . '">' . View::e($wert) . '</textarea>';
        } else {
            $typ = (string) ($o['typ'] ?? 'text');
            $feld = '<input type="' . View::e($typ) . '"' . $attr . ' value="' . View::e($wert) . '">';
        }
        if (($o['einheit'] ?? '') !== '') {
            $feld = '<span class="mit-einheit">' . $feld . '<span class="einheit">' . View::e((string) $o['einheit']) . '</span></span>';
        }
        return '<div class="field' . (($o['klasse'] ?? '') !== '' ? ' ' . View::e((string) $o['klasse']) : '') . '">'
            . '<label for="' . View::e($id) . '">' . View::e($label) . self::abgeleitet(!empty($o['abgeleitet']), $pfad) . '</label>'
            . $feld
            . (($o['hilfe'] ?? '') !== '' ? '<div class="help">' . View::e((string) $o['hilfe']) . '</div>' : '')
            . '</div>';
    }

    /**
     * Auswahlliste.
     *
     * @param array<string,string> $optionen Wert → Text
     * @param array{z?:string,hilfe?:string,klasse?:string,abgeleitet?:bool} $o
     */
    public static function auswahl(string $pfad, string $wert, string $label, array $optionen, array $o = []): string
    {
        $z = (string) ($o['z'] ?? '');
        $id = self::id($pfad, $z);
        $html = '<div class="field' . (($o['klasse'] ?? '') !== '' ? ' ' . View::e((string) $o['klasse']) : '') . '">'
            . '<label for="' . View::e($id) . '">' . View::e($label) . self::abgeleitet(!empty($o['abgeleitet']), $pfad) . '</label>'
            . '<select id="' . View::e($id) . '" data-feld="' . View::e($pfad) . '">';
        if (!array_key_exists($wert, $optionen) && $wert !== '') {
            $optionen = [$wert => $wert] + $optionen;
        }
        foreach ($optionen as $w => $text) {
            $html .= '<option value="' . View::e((string) $w) . '"' . ((string) $w === $wert ? ' selected' : '') . '>' . View::e($text) . '</option>';
        }
        return $html . '</select>'
            . (($o['hilfe'] ?? '') !== '' ? '<div class="help">' . View::e((string) $o['hilfe']) . '</div>' : '')
            . '</div>';
    }

    /**
     * Segmentschalter (Radiogruppe) – z. B. i.O. / n.i.O. / entfällt.
     * Ein zweiter Tipp auf die gewählte Stufe leert sie wieder (bericht.js),
     * außer mit 'fest' => true.
     *
     * @param array<string,string> $optionen Wert → Text
     * @param array{z?:string,label?:string,fest?:bool,klasse?:string,kurz?:bool} $o
     */
    public static function dreiwahl(string $pfad, string $wert, array $optionen, array $o = []): string
    {
        $z = (string) ($o['z'] ?? '');
        $name = self::id($pfad, $z);
        $html = '<div class="dreiwahl' . (($o['klasse'] ?? '') !== '' ? ' ' . View::e((string) $o['klasse']) : '') . '" role="radiogroup"'
            . (($o['label'] ?? '') !== '' ? ' aria-label="' . View::e((string) $o['label']) . '"' : '')
            . (!empty($o['fest']) ? ' data-fest' : '') . (!empty($o['kurz']) ? ' data-kurz' : '') . '>';
        foreach ($optionen as $w => $text) {
            $w = (string) $w;
            $html .= '<label class="wahl-' . View::e($w !== '' ? $w : 'leer') . '"><input type="radio" name="' . View::e($name) . '" value="' . View::e($w) . '"'
                . ' data-feld="' . View::e($pfad) . '"' . ($w === $wert ? ' checked data-war="1"' : '') . '><span>' . View::e($text) . '</span></label>';
        }
        return $html . '</div>';
    }

    /**
     * Ein Messwert {op, w}: Umschalter „=“/„>“/„<“ (der Ziffernblock von iOS
     * hat weder > noch <) und das Zahlenfeld. `data-grenze` nimmt den
     * Grenzwert aus der Bewertung des Servers auf.
     *
     * @param array{op?:string,w?:string} $wert
     * @param array{z?:string,grenze?:string,grenzeText?:string,klasse?:string} $o
     */
    public static function messwert(string $pfad, array $wert, string $label, string $einheit, array $o = []): string
    {
        $z = (string) ($o['z'] ?? '');
        $id = self::id($pfad . '.w', $z);
        $op = (string) ($wert['op'] ?? '');
        $op = in_array($op, ['<', '>'], true) ? $op : '';
        return '<div class="messfeld' . (($o['klasse'] ?? '') !== '' ? ' ' . View::e((string) $o['klasse']) : '') . '">'
            . '<label for="' . View::e($id) . '">' . View::e($label) . ($einheit !== '' ? ' <span class="einheit">' . View::e($einheit) . '</span>' : '') . '</label>'
            . '<div class="messfeld-eingabe">'
            . '<button type="button" class="op-wahl" data-op data-feld="' . View::e($pfad . '.op') . '" value="' . View::e($op) . '"'
            . ' aria-label="' . View::e(self::opText($op)) . '">' . View::e($op === '' ? '=' : $op) . '</button>'
            . '<input type="text" id="' . View::e($id) . '" data-feld="' . View::e($pfad . '.w') . '" value="' . View::e(self::komma((string) ($wert['w'] ?? ''))) . '"'
            . ' inputmode="decimal" enterkeyhint="next" autocomplete="off" maxlength="16">'
            . '</div>'
            . (($o['grenze'] ?? '') !== '' ? '<div class="grenze" data-grenze="' . View::e((string) $o['grenze']) . '">' . View::e((string) ($o['grenzeText'] ?? '')) . '</div>' : '')
            . '</div>';
    }

    public static function opText(string $op): string
    {
        return match ($op) {
            '>'     => 'über dem Messbereich (>)',
            '<'     => 'unter dem Messbereich (<)',
            default => 'Messwert genau (=)',
        };
    }

    /**
     * Ein Häkchen für einen Wahrheitswert ("1"/"0") – oder zwei andere
     * Werte ('an'/'aus', z. B. „nicht_gemessen“/„offen“).
     *
     * @param array{z?:string,hilfe?:string,an?:string,aus?:string} $o
     */
    public static function haken(string $pfad, bool $an, string $label, array $o = []): string
    {
        $id = self::id($pfad, (string) ($o['z'] ?? ''));
        $werte = isset($o['an']) ? ' data-an="' . View::e((string) $o['an']) . '" data-aus="' . View::e((string) ($o['aus'] ?? '')) . '"' : '';
        return '<label class="check" for="' . View::e($id) . '"><input type="checkbox" id="' . View::e($id) . '" data-feld="' . View::e($pfad) . '" value="1"'
            . $werte . ($an ? ' checked' : '') . '> <span>' . View::e($label) . '</span></label>'
            . (($o['hilfe'] ?? '') !== '' ? '<div class="help">' . View::e((string) $o['hilfe']) . '</div>' : '');
    }

    /**
     * Mehrfachauswahl als Chips (Liste im Inhalt).
     *
     * @param list<string> $gewaehlt
     * @param array<string,string> $optionen Wert → Text
     * @param array<string,string> $zusatz Wert → kleiner Zusatz (z. B. „Kalibrierung abgelaufen“)
     */
    public static function chips(string $pfad, array $gewaehlt, array $optionen, array $zusatz = []): string
    {
        $html = '<div class="chips">';
        foreach ($optionen as $w => $text) {
            $w = (string) $w;
            $html .= '<label class="chip-wahl"><input type="checkbox" data-feld="' . View::e($pfad) . '" data-liste value="' . View::e($w) . '"'
                . (in_array($w, $gewaehlt, true) ? ' checked' : '') . '><span>' . View::e($text)
                . (($zusatz[$w] ?? '') !== '' ? ' <small>' . View::e($zusatz[$w]) . '</small>' : '') . '</span></label>';
        }
        return $html . '</div>';
    }

    /**
     * Stufe einer Bewertung als Klasse und Text – immer Symbol und Wort,
     * nie nur Farbe. Wie BEWERTUNG in bericht.js.
     *
     * @return array{0:string,1:string} [Klasse, Text]
     */
    public static function bewertung(string $auto, string $pruefer = 'auto'): array
    {
        if ($pruefer === 'io') {
            return ['ok', '✓ i.O. (Prüfer)'];
        }
        if ($pruefer === 'nio') {
            return ['mangel', '✗ n.i.O. (Prüfer)'];
        }
        return match ($auto) {
            'ok'      => ['ok', '✓ i.O.'],
            'hinweis' => ['hinweis', '! Hinweis'],
            'mangel'  => ['mangel', '✗ Mangel'],
            'fehlt'   => ['fehlt', '… Wert fehlt'],
            'manuell' => ['manuell', '? selbst bewerten'],
            'entf'    => ['entf', '– nicht gemessen'],
            default   => ['offen', 'offen'],
        };
    }

    /**
     * Der Grenzwert an einem Messfeld, aus `grenzen` der Bewertung – wie
     * grenzeText() in bericht.js.
     *
     * @param array<string,?string> $g
     */
    public static function grenzeText(string $feld, array $g): string
    {
        $k = static fn (?string $v): string => $v === null || $v === '' ? '' : Grenzwerte::komma((float) $v);
        return match ($feld) {
            'zs'     => ($g['zs_max'] ?? null) !== null ? '≤ ' . $k($g['zs_max']) . ' Ω' : '',
            'ik'     => ($g['ik_min'] ?? null) !== null ? '≥ ' . $k($g['ik_min']) . ' A' : '',
            'riso'   => ($g['riso_min'] ?? null) !== null ? '≥ ' . $k($g['riso_min']) . ' MΩ' : '',
            'rpe'    => ($g['rpe_max'] ?? null) !== null ? '≤ ' . $k($g['rpe_max']) . ' Ω' : '',
            'idelta' => ($g['idelta_max'] ?? null) !== null ? '> ' . $k($g['idelta_min'] ?? null) . ' … ≤ ' . $k($g['idelta_max']) . ' mA' : '',
            'ta'     => ($g['ta_max'] ?? null) !== null
                ? (($g['ta_min'] ?? null) !== null ? $k($g['ta_min']) . ' … ' . $k($g['ta_max']) . ' ms' : '≤ ' . $k($g['ta_max']) . ' ms') : '',
            'ub'     => ($g['ub_max'] ?? null) !== null ? '≤ ' . $k($g['ub_max']) . ' V' : '',
            default  => '',
        };
    }

    /**
     * Kurzform von Leitung und Schutz eines Kreises: „Schuko · 3×2,5 mm² ·
     * LS C16 · RCD F2 40 A/30 mA Typ A“ – wie schutzText() in bericht.js.
     */
    public static function schutzText(array $k): string
    {
        $teile = [];
        $l = is_array($k['leitung'] ?? null) ? $k['leitung'] : [];
        $leitung = trim((string) ($l['art'] ?? ''));
        $leiter = trim((string) ($l['leiter'] ?? ''));
        $q = trim((string) ($l['querschnitt'] ?? ''));
        $ader = $q !== '' ? ($leiter !== '' ? $leiter . '×' : '') . self::komma($q) . ' mm²' : ($leiter !== '' ? $leiter . ' Leiter' : '');
        if ($leitung !== '' || $ader !== '') {
            $teile[] = trim($leitung . ($leitung !== '' && $ader !== '' ? ' · ' : '') . $ader);
        }
        $u = is_array($k['ueb'] ?? null) ? $k['ueb'] : [];
        $ueb = self::woerter([(string) ($u['art'] ?? ''), ((string) ($u['char'] ?? '')) . self::komma((string) ($u['in'] ?? ''))]);
        if ($ueb !== '') {
            $teile[] = $ueb;
        }
        $r = is_array($k['rcd'] ?? null) ? $k['rcd'] : [];
        if (trim((string) ($r['idn'] ?? '')) !== '') {
            $teile[] = self::woerter(['RCD', (string) ($r['bez'] ?? ''),
                (trim((string) ($r['in'] ?? '')) !== '' ? self::komma((string) $r['in']) . ' A/' : '') . self::komma((string) $r['idn']) . ' mA',
                ($r['typ'] ?? '') !== '' ? 'Typ ' . $r['typ'] : '', ($r['s'] ?? '0') === '1' ? 'S' : '']);
        }
        return implode(' · ', $teile);
    }

    /** Nicht leere Teile mit je einem Leerzeichen. */
    private static function woerter(array $teile): string
    {
        return implode(' ', array_filter(array_map('trim', $teile), static fn (string $t): bool => $t !== ''));
    }

    /** Marke „aus Plan“ an einem Feld, das die App nur erschlossen hat. */
    private static function abgeleitet(bool $an, string $pfad): string
    {
        return $an ? ' <span class="abgeleitet" data-abgeleitet-marke="' . View::e($pfad) . '" title="von der App erschlossen – bitte prüfen">aus Plan</span>' : '';
    }
}
