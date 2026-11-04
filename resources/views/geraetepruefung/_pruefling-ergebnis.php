<?php
/**
 * Ergebnis einer Prüfung bzw. das letzte Ergebnis eines Prüflings – Symbol
 * und Wort, nie nur eine Farbe. Bewertet hat das Prüfgerät; PowerManagement
 * leitet das Gesamtergebnis nur ab.
 *
 * @var string $ergebnis '' | bestanden | nicht_bestanden
 */
use App\Support\View;

$e = (string) ($ergebnis ?? '');
if ($e === 'bestanden') {
    echo '<span class="badge ok"><span aria-hidden="true">✓</span> bestanden</span>';
} elseif ($e === 'nicht_bestanden') {
    echo '<span class="badge bad"><span aria-hidden="true">✗</span> nicht bestanden</span>';
} else {
    echo '<span class="muted">' . View::e('noch nicht geprüft') . '</span>';
}
