<?php
/**
 * Übersicht des Kundenbereichs: die Locations, die der Kunde jetzt sieht
 * (mit Veranstaltung und „sichtbar bis“), die kommenden (nur Name und
 * „ab …“), die letzten fünf Protokolle, Ansprechpartner und Hilfe.
 *
 * @var array<string,mixed> $kunde
 * @var list<array<string,mixed>> $locations KundenSicht::locations()
 * @var list<array{name:string,ab:string}> $kommend KundenSicht::kommend()
 * @var list<array<string,mixed>> $berichte KundenSicht::berichte()
 * @var array{text:string,telefon:?string} $kontakt KundenEinstellungen::kontakt()
 */
use App\Kunden\Kundenbereich;
use App\Support\View;
use App\Support\Zeit;

$title = 'Übersicht';
?>
<h1 class="kb-titel">Willkommen</h1>
<p class="kb-einleitung">Kundenbereich für <strong><?= View::e((string) $kunde['name']) ?></strong><?php
    if ((string) $kunde['kundennummer'] !== ''): ?> · Kundennummer <?= View::e((string) $kunde['kundennummer']) ?><?php endif;
    if (!empty($kunde['gueltig_bis'])): ?> · Zugang bis <?= View::e(Zeit::anzeige((string) $kunde['gueltig_bis'], 'd.m.Y')) ?><?php endif; ?></p>

<div class="kb-karten">
    <section class="card kb-breit" aria-labelledby="kb-orte-titel">
        <div class="card-head">
            <h2 id="kb-orte-titel">Ihre Locations</h2>
            <span class="spacer"></span>
            <?php if ($locations !== []): ?><a href="<?= View::e(Kundenbereich::PFAD . '/locations') ?>">Alle ansehen</a><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($locations === []): ?>
                <p class="kb-leer">Derzeit ist keine Location für Sie freigegeben.<?= $kommend !== [] ? ' Was demnächst dazukommt, steht unten.' : '' ?></p>
            <?php else: ?>
                <?= View::partial('kundenbereich/_orte', ['locations' => $locations]) ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="card kb-breit" aria-labelledby="kb-protokolle-titel">
        <div class="card-head">
            <h2 id="kb-protokolle-titel">Ihre Protokolle</h2>
            <span class="spacer"></span>
            <?php if (count($berichte) > 5): ?><a href="<?= View::e(Kundenbereich::PFAD . '/protokolle') ?>">Alle <?= count($berichte) ?> ansehen</a><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($berichte === []): ?>
                <p class="kb-leer">Noch kein abgeschlossenes Protokoll. Übergabeberichte erscheinen hier nach dem Abschluss.</p>
            <?php else: ?>
                <?= View::partial('kundenbereich/_protokolle', ['berichte' => array_slice($berichte, 0, 5)]) ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($kommend !== []): ?>
        <section class="card" aria-labelledby="kb-kommend-titel">
            <div class="card-head"><h2 id="kb-kommend-titel">Demnächst</h2></div>
            <div class="card-body">
                <?= View::partial('kundenbereich/_kommend', ['kommend' => $kommend]) ?>
            </div>
        </section>
    <?php endif; ?>

    <?= View::partial('kundenbereich/_kontakt', ['kontakt' => $kontakt]) ?>

    <section class="card" aria-labelledby="kb-hilfe-titel">
        <div class="card-head"><h2 id="kb-hilfe-titel">Hilfe</h2></div>
        <div class="card-body">
            <p>Was ein Übergabebericht ist, was ein Prüfnachweis, und was Sie vor Ort prüfen sollten.</p>
            <a class="btn" href="<?= View::e(Kundenbereich::PFAD . '/hilfe') ?>">Zur Hilfe</a>
        </div>
    </section>
</div>
