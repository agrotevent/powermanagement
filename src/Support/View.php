<?php
declare(strict_types=1);

namespace App\Support;

use App\Auth\Auth;

final class View
{
    private static string $base = '';
    private static array $shared = [];

    /** Einmal je Anfrage ermittelt, dann gemerkt. */
    private static string $stempel = '';

    /**
     * Adresse einer mitgelieferten Datei, mit Kennung gegen alte Stände.
     *
     * Hintergrund: nginx liefert alles unter /assets/ mit sieben Tagen
     * Haltbarkeit aus. Das ist richtig – es spart bei jedem Seitenaufruf
     * ein Dutzend Anfragen. Ohne Kennung in der Adresse heißt es aber
     * auch: ein Fehler in einer JavaScript-Datei bleibt nach dem Update
     * bis zu sieben Tage bestehen, weil der Browser die Datei gar nicht
     * erst neu holt. Genau daran ist der Grundriss-Fix nach dem letzten
     * Update vorbeigelaufen.
     *
     * Die Kennung ist die Änderungszeit der Datei. Nach einem Update ist
     * sie neu, die Adresse damit auch, und der Browser holt die Datei –
     * ohne dass an der Haltbarkeit etwas geändert werden müsste.
     */
    public static function asset(string $pfad): string
    {
        $pfad = '/' . ltrim($pfad, '/');
        if (!is_file(dirname(__DIR__, 2) . '/public' . $pfad)) {
            // Eine erfundene Kennung an einer Adresse, die es nicht gibt,
            // würde den Fehler nur verschleiern.
            return $pfad;
        }
        return $pfad . '?v=' . self::assetVersion();
    }

    /**
     * Eine Kennung für den gesamten Bestand unter public/assets.
     *
     * Absichtlich nicht je Datei: ein Modul holt sich das nächste selbst
     * (plan.js lädt plan-karte.js). Die Kennung dafür kann es nur aus der
     * eigenen Adresse nehmen – stünde dort die Änderungszeit von plan.js,
     * bliebe eine Korrektur in plan-karte.js im Cache hängen, und genau
     * das sollte die Kennung ja verhindern. Gemessen: nach einer Änderung
     * allein an plan-karte.js holte der Browser die alte Fassung weiter.
     *
     * Eine gemeinsame Kennung heißt: nach einem Update werden die Dateien
     * einmal neu geholt, auch die unveränderten. Das sind ein paar hundert
     * Kilobyte, einmalig – der Preis dafür, dass ein Fix sicher ankommt.
     */
    public static function assetVersion(): string
    {
        if (self::$stempel !== '') {
            return self::$stempel;
        }

        $wurzel = dirname(__DIR__, 2) . '/public/assets';
        $juengste = 0;
        if (is_dir($wurzel)) {
            $lauf = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($lauf as $datei) {
                if ($datei->isFile()) {
                    $juengste = max($juengste, (int) $datei->getMTime());
                }
            }
        }

        self::$stempel = dechex($juengste ?: time());
        return self::$stempel;
    }

    public static function setBase(string $dir): void
    {
        self::$base = rtrim($dir, '/');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], string $layout = 'layout/app'): string
    {
        $result = self::evaluate($template, $data);
        if ($layout === '') {
            return $result['content'];
        }

        // Titel/Untertitel dürfen im Template gesetzt werden und landen im Layout.
        foreach (['title', 'subtitle'] as $key) {
            if (isset($result['vars'][$key])) {
                $data[$key] = $result['vars'][$key];
            }
        }

        return self::partial($layout, array_merge($data, ['content' => $result['content']]));
    }

    public static function partial(string $template, array $data = []): string
    {
        return self::evaluate($template, $data)['content'];
    }

    /** @return array{content:string,vars:array<string,mixed>} */
    private static function evaluate(string $template, array $data): array
    {
        $__file = self::$base . '/' . str_replace('.', '/', $template) . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException("View nicht gefunden: {$template}");
        }
        extract(array_merge(self::$shared, $data), EXTR_SKIP);
        ob_start();
        include $__file;
        $content = (string) ob_get_clean();

        $vars = get_defined_vars();
        unset($vars['__file'], $vars['template'], $vars['data'], $vars['content']);

        return ['content' => $content, 'vars' => $vars];
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Darf der angemeldete Benutzer das? Für Navigations-/Button-Sichtbarkeit. */
    public static function can(string $permission): bool
    {
        return Auth::can($permission);
    }

    /**
     * Ein Verweis, der nur erscheint, wenn er auch irgendwo hinführt.
     *
     * Ohne das Recht bleibt der Text stehen (ohne Verweis) – oder er fällt
     * ganz weg, wenn er für sich genommen nichts aussagt. So sieht niemand
     * Wege, die für ihn ohnehin bei „kein Zugriff" enden.
     */
    public static function linkIf(
        string $permission,
        string $href,
        string $text,
        string $attributes = '',
        bool $textOhneRecht = true
    ): string {
        if (!self::can($permission)) {
            return $textOhneRecht ? self::e($text) : '';
        }
        return sprintf(
            '<a href="%s"%s>%s</a>',
            self::e($href),
            $attributes !== '' ? ' ' . $attributes : '',
            self::e($text)
        );
    }
}
