<?php
/**
 * Ergebnis einer Prüfung bzw. das letzte Ergebnis eines Prüflings – Symbol
 * und Wort, nie nur eine Farbe. Bewertet hat das Prüfgerät; PowerManagement
 * leitet das Gesamtergebnis nur ab.
 *
 * @var string $ergebnis '' | bestanden | nicht_bestanden
 * @var bool|null $geprueft (freiwillig) es gibt ein Datum der letzten Prüfung –
 *      ohne Ergebnis dann „Ergebnis nicht bekannt“ (von Hand eingetragen) statt
 *      „noch nicht geprüft“
 */
use App\Support\View;

$e = (string) ($ergebnis ?? '');
$geprueft = ($geprueft ?? false) === true;
if ($e === 'bestanden') {
    echo '<span class="badge ok"><span aria-hidden="true">✓</span> bestanden</span>';
} elseif ($e === 'nicht_bestanden') {
    echo '<span class="badge bad"><span aria-hidden="true">✗</span> nicht bestanden</span>';
} else {
    echo '<span class="muted">' . View::e($geprueft ? 'Ergebnis nicht bekannt' : 'noch nicht geprüft') . '</span>';
}
