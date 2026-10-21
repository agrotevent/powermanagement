<?php
/**
 * Fehlerseite im Kundenbereich. Für 403 und 404 immer derselbe Text: ob
 * eine Seite fehlt oder zu einem anderen Kunden gehört, soll man ihr nicht
 * ansehen (gleicher Rumpf für fremd und nicht vorhanden).
 *
 * @var int $code
 */
use App\Kunden\Kundenbereich;
use App\Support\View;

$title = 'Nicht gefunden';
$unerwartet = (int) $code >= 500;
if ($unerwartet) {
    $title = 'Fehler';
}
?>
<div class="card kb-fehler">
    <div class="card-body">
        <h1><?= $unerwartet ? 'Das hat nicht geklappt' : 'Nicht gefunden' ?></h1>
        <p class="muted"><?= $unerwartet
            ? 'Bei dieser Anfrage ist ein unerwarteter Fehler aufgetreten. Bitte versuchen Sie es später noch einmal.'
            : 'Diese Seite gibt es nicht (mehr) – oder sie gehört nicht zu Ihrem Zugang.' ?></p>
        <a class="btn btn-primary" href="<?= View::e(Kundenbereich::PFAD) ?>">Zur Übersicht</a>
    </div>
</div>
