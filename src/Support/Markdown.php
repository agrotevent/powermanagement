<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Markdown, so weit das Handbuch es braucht – und keinen Schritt weiter.
 *
 * Die Dokumentation liegt als Markdown neben dem Programm und soll sich
 * in der Oberfläche lesen lassen, ohne dass jemand ins Internet muss.
 * Dafür genügt ein kleiner Übersetzer: Überschriften, Absätze, Listen,
 * Tabellen, Codeblöcke, Zitate, Bilder, Links und die Auszeichnungen im
 * Fließtext. Genau das steht in `docs/*.md`.
 *
 * Was hier **nicht** steht, ist Absicht: verschachtelte Listen,
 * Fußnoten, HTML im Text. Ein vollständiger Markdown-Übersetzer wäre
 * mehr Code als das, was er hier übersetzen soll – und jede Zeile davon
 * wollte gepflegt werden.
 *
 * Alles wird maskiert, bevor es gesetzt wird. Die Dateien kommen zwar
 * aus dem eigenen Haus, aber eine Dokumentation, die Skripte ausführen
 * kann, ist keine Dokumentation.
 */
final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $zeilen = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $html   = [];

        $imCode   = false;
        $absatz   = [];
        $liste    = null;   // 'ul' | 'ol' | null
        $zitat    = [];
        $tabelle  = [];

        $absatzSchliessen = static function () use (&$absatz, &$html): void {
            if ($absatz !== []) {
                $html[] = '<p>' . self::inline(implode(' ', $absatz)) . '</p>';
                $absatz = [];
            }
        };
        $listeSchliessen = static function () use (&$liste, &$html): void {
            if ($liste !== null) {
                $html[] = '</' . $liste . '>';
                $liste = null;
            }
        };
        $zitatSchliessen = static function () use (&$zitat, &$html): void {
            if ($zitat !== []) {
                $html[] = '<blockquote>' . self::toHtml(implode("\n", $zitat)) . '</blockquote>';
                $zitat = [];
            }
        };
        $tabelleSchliessen = static function () use (&$tabelle, &$html): void {
            if ($tabelle !== []) {
                $html[] = self::tabelle($tabelle);
                $tabelle = [];
            }
        };

        foreach ($zeilen as $zeile) {
            $roh = rtrim($zeile);

            // Codeblock: alles dazwischen bleibt, wie es ist.
            if (preg_match('/^```/', $roh) === 1) {
                if ($imCode) {
                    $html[] = '</code></pre>';
                    $imCode = false;
                } else {
                    $absatzSchliessen();
                    $listeSchliessen();
                    $zitatSchliessen();
                    $tabelleSchliessen();
                    $html[] = '<pre class="md-code"><code>';
                    $imCode = true;
                }
                continue;
            }
            if ($imCode) {
                $html[] = htmlspecialchars($zeile, ENT_QUOTES, 'UTF-8');
                continue;
            }

            // Tabellenzeile
            if (str_starts_with(ltrim($roh), '|')) {
                $absatzSchliessen();
                $listeSchliessen();
                $zitatSchliessen();
                $tabelle[] = trim($roh);
                continue;
            }
            $tabelleSchliessen();

            // Zitat
            if (preg_match('/^>\s?(.*)$/', $roh, $m) === 1) {
                $absatzSchliessen();
                $listeSchliessen();
                $zitat[] = $m[1];
                continue;
            }
            $zitatSchliessen();

            // Leerzeile beendet, was offen ist
            if (trim($roh) === '') {
                $absatzSchliessen();
                $listeSchliessen();
                continue;
            }

            // Trennlinie
            if (preg_match('/^(-{3,}|\*{3,})$/', trim($roh)) === 1) {
                $absatzSchliessen();
                $listeSchliessen();
                $html[] = '<hr>';
                continue;
            }

            // Überschrift
            if (preg_match('/^(#{1,6})\s+(.*)$/', $roh, $m) === 1) {
                $absatzSchliessen();
                $listeSchliessen();
                $stufe = strlen($m[1]);
                $text  = trim($m[2]);
                $html[] = sprintf(
                    '<h%d id="%s">%s</h%d>',
                    $stufe,
                    self::anker($text),
                    self::inline($text),
                    $stufe
                );
                continue;
            }

            // Listenpunkt
            if (preg_match('/^\s*[-*+]\s+(.*)$/', $roh, $m) === 1) {
                $absatzSchliessen();
                if ($liste !== 'ul') {
                    $listeSchliessen();
                    $html[] = '<ul>';
                    $liste = 'ul';
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            if (preg_match('/^\s*\d+\.\s+(.*)$/', $roh, $m) === 1) {
                $absatzSchliessen();
                if ($liste !== 'ol') {
                    $listeSchliessen();
                    $html[] = '<ol>';
                    $liste = 'ol';
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            /*
             * Eine eingerückte Folgezeile gehört zum Listenpunkt davor –
             * so sind die Absätze in den Dateien umbrochen. Ohne das
             * risse jeder Umbruch einen neuen Absatz auf.
             */
            if ($liste !== null && preg_match('/^\s+\S/', $zeile) === 1) {
                $letzte = array_pop($html);
                if (is_string($letzte) && str_ends_with($letzte, '</li>')) {
                    $html[] = substr($letzte, 0, -5) . ' ' . self::inline(trim($roh)) . '</li>';
                    continue;
                }
                $html[] = $letzte;
            }
            $listeSchliessen();

            $absatz[] = trim($roh);
        }

        if ($imCode) {
            $html[] = '</code></pre>';
        }
        $absatzSchliessen();
        $listeSchliessen();
        $zitatSchliessen();
        $tabelleSchliessen();

        return implode("\n", $html);
    }

    /**
     * Eine Tabelle setzen.
     *
     * Die zweite Zeile ist die Trennlinie (`|---|---|`) und wird
     * übersprungen; steht keine da, ist es keine Tabelle, sondern
     * jemand hat einen senkrechten Strich am Zeilenanfang.
     *
     * @param array<int,string> $zeilen
     */
    private static function tabelle(array $zeilen): string
    {
        $zellen = static function (string $zeile): array {
            $zeile = trim($zeile, '| ');
            return array_map('trim', explode('|', $zeile));
        };

        $hatTrenner = isset($zeilen[1]) && preg_match('/^\|?[\s:|-]+\|[\s:|-]*$/', $zeilen[1]) === 1;
        if (!$hatTrenner) {
            $absatz = [];
            foreach ($zeilen as $zeile) {
                $absatz[] = self::inline($zeile);
            }
            return '<p>' . implode(' ', $absatz) . '</p>';
        }

        $out = ['<div class="table-wrap"><table>'];
        $out[] = '<thead><tr>';
        foreach ($zellen($zeilen[0]) as $kopf) {
            $out[] = '<th>' . self::inline($kopf) . '</th>';
        }
        $out[] = '</tr></thead><tbody>';

        foreach (array_slice($zeilen, 2) as $zeile) {
            $out[] = '<tr>';
            foreach ($zellen($zeile) as $zelle) {
                $out[] = '<td>' . self::inline($zelle) . '</td>';
            }
            $out[] = '</tr>';
        }
        $out[] = '</tbody></table></div>';

        return implode('', $out);
    }

    /**
     * Auszeichnungen im Fließtext.
     *
     * Maskiert wird zuerst – was danach zu HTML wird, ist genau das, was
     * dieser Übersetzer erzeugt, und nichts aus der Datei.
     */
    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Code zuerst: darin gilt keine weitere Auszeichnung.
        $codes = [];
        $text = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = $m[1];
            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text);

        // Bild vor Link – sonst frisst der Link die Klammern.
        $text = (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)\)/',
            static fn (array $m) => sprintf(
                '<img src="%s" alt="%s" loading="lazy">',
                self::quelle($m[2]),
                $m[1]
            ),
            $text
        );
        $text = (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            static fn (array $m) => sprintf('<a href="%s">%s</a>', self::ziel($m[2]), $m[1]),
            $text
        );

        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $text);

        foreach ($codes as $i => $code) {
            $text = str_replace("\x00" . $i . "\x00", '<code>' . $code . '</code>', $text);
        }

        return $text;
    }

    /**
     * Das Ziel eines Links.
     *
     * Verweise auf andere Dateien der Dokumentation führen auf die
     * Handbuchseite; alles andere bleibt, wie es ist. Ein Verweis auf
     * eine Datei, die der Browser nicht hat, wäre sonst ein toter Link.
     */
    private static function ziel(string $ziel): string
    {
        if (preg_match('/^([a-z0-9-]+)\.md(#.*)?$/i', $ziel, $m) === 1) {
            return '/handbuch/' . strtolower($m[1]) . ($m[2] ?? '');
        }
        if (str_starts_with($ziel, 'javascript:')) {
            return '#';
        }
        return $ziel;
    }

    /** Die Adresse eines Bildes – die Screenshots liegen unter /handbuch/bild. */
    private static function quelle(string $pfad): string
    {
        if (str_starts_with($pfad, 'screenshots/')) {
            return '/handbuch/bild/' . rawurlencode(substr($pfad, strlen('screenshots/')));
        }
        return $pfad;
    }

    /**
     * Der Anker einer Überschrift.
     *
     * Dieselbe Regel wie auf GitHub, damit die Verweise in den Dateien
     * („#12--erstkonfiguration") auch hier treffen.
     */
    public static function anker(string $text): string
    {
        $text = strip_tags(self::inline($text));
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = mb_strtolower(trim($text));
        $text = (string) preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);
        /*
         * Jedes Leerzeichen wird ein Bindestrich – auch zwei
         * nebeneinander. „12 · Erstkonfiguration" wird nach dem
         * Wegfallen des Trennpunktes zu „12  Erstkonfiguration" und
         * damit zu „12--erstkonfiguration". Wer hier zusammenfasst,
         * bekommt einen Anker, den die Verweise in den Dateien nicht
         * treffen – und die sind nach GitHubs Regel geschrieben.
         */
        $text = (string) preg_replace('/\s/u', '-', $text);
        return trim($text, '-');
    }

    /**
     * Die Überschriften einer Datei – für das Inhaltsverzeichnis.
     *
     * @return array<int,array{stufe:int,text:string,anker:string}>
     */
    public static function gliederung(string $markdown): array
    {
        $out = [];
        $imCode = false;
        foreach (explode("\n", $markdown) as $zeile) {
            if (preg_match('/^```/', $zeile) === 1) {
                $imCode = !$imCode;
                continue;
            }
            if ($imCode) {
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.*)$/', rtrim($zeile), $m) === 1) {
                $text = trim($m[2]);
                $out[] = [
                    'stufe' => strlen($m[1]),
                    'text'  => $text,
                    'anker' => self::anker($text),
                ];
            }
        }
        return $out;
    }
}
