<?php
/*
 * Vorläufige Seite des Moduls „Geräteprüfung“: Die Routen stehen schon,
 * ihre Seiten kommen nach und nach. Erwartet $seite (Überschrift).
 */
use App\Support\View;

$title = 'Geräteprüfung';
$subtitle = (string) ($seite ?? '');
?>
<div class="card">
    <div class="card-head"><h2><?= View::e((string) ($seite ?? 'Geräteprüfung')) ?></h2></div>
    <div class="card-body">
        <p class="muted">Noch nicht verfügbar.</p>
        <?php if (View::can('geraetepruefung.view')): ?>
            <p><a class="btn btn-sm btn-ghost" href="/geraetepruefung">← Geräteprüfung</a></p>
        <?php endif; ?>
    </div>
</div>
