<?php
/**
 * Übersicht des Kundenbereichs: die Locations, die der Kunde jetzt sieht
 * (mit Veranstaltung und „sichtbar bis“), die kommenden (nur Name und
 * „ab …“), die letzten fünf Protokolle, die Prüfnachweise (Anzahl, was bald
 * oder schon abgelaufen ist), Ansprechpartner und Hilfe.
 *
 * @var array<string,mixed> $kunde
 * @var list<array<string,mixed>> $locations KundenSicht::locations()
 * @var list<array{name:string,ab:string}> $kommend KundenSicht::kommend()
 * @var list<array<string,mixed>> $berichte KundenSicht::berichte()
 * @var list<array<string,mixed>> $nachweise KundenSicht::nachweise()
 * @var array{text:string,telefon:?string} $kontakt KundenEinstellungen::kontakt()
 */
use App\Kunden\Kundenbereich;
use App\Kunden\KundenSicht;
use App\Support\View;
use App\Support\Zeit;

$title = 'Übersicht';
$nz = KundenSicht::nachweisZahlen($nachweise);
// Was Aufmerksamkeit braucht, zuerst: abgelaufen, dann bald fällig (höchstens fünf).
$dringend = array_values(array_filter($nachweise, static fn (array $n): bool => in_array($n['gueltigkeit']['code'], ['abgelaufen', 'laeuft_ab'], true)));
usort($dringend, static fn (array $a, array $b): int => [$a['gueltigkeit']['code'] !== 'abgelaufen', (string) $a['naechste_pruefung']]
    <=> [$b['gueltigkeit']['code'] !== 'abgelaufen', (string) $b['naechste_pruefung']]);
$dringend = array_slice($dringend, 0, 5);
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

    <section class="card kb-breit" aria-labelledby="kb-nachweise-titel">
        <div class="card-head">
            <h2 id="kb-nachweise-titel">Ihre Prüfnachweise</h2>
            <span class="spacer"></span>
            <?php if ($nachweise !== []): ?><a href="<?= View::e(Kundenbereich::PFAD . '/pruefnachweise') ?>">Alle <?= (int) $nz['alle'] ?> ansehen</a><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($nachweise === []): ?>
                <p class="kb-leer">Noch kein Prüfnachweis. Die Prüfprotokolle der Geräte, die Sie bei uns mieten, erscheinen hier nach unserer Freigabe.</p>
            <?php else: ?>
                <ul class="kb-zahlen">
                    <li><strong><?= (int) $nz['alle'] ?></strong> <?= $nz['alle'] === 1 ? 'Nachweis' : 'Nachweise' ?></li>
                    <?php if ($nz['abgelaufen'] > 0): ?><li class="kb-frist-abgelaufen"><span aria-hidden="true">✗</span> <strong><?= (int) $nz['abgelaufen'] ?></strong> abgelaufen</li><?php endif; ?>
                    <?php if ($nz['laeuft_ab'] > 0): ?><li class="kb-frist-laeuft_ab"><span aria-hidden="true">!</span> <strong><?= (int) $nz['laeuft_ab'] ?></strong> <?= $nz['laeuft_ab'] === 1 ? 'läuft' : 'laufen' ?> in 30 Tagen ab</li><?php endif; ?>
                    <?php if ($nz['abgelaufen'] + $nz['laeuft_ab'] === 0): ?><li class="kb-frist-gueltig"><span aria-hidden="true">✓</span> keiner läuft in den nächsten 30 Tagen ab</li><?php endif; ?>
                </ul>
                <?php if ($dringend !== []): ?>
                    <ul class="kb-orte">
                        <?php foreach ($dringend as $n): ?>
                            <li class="kb-ort"><span class="kb-ort-text"><span class="kb-geraet"><?= View::e($n['bezeichnung']) ?></span>
                                <span class="kb-frist kb-frist-<?= View::e($n['gueltigkeit']['code']) ?>"><span aria-hidden="true"><?= View::e($n['gueltigkeit']['symbol']) ?></span>
                                    <?= View::e($n['gueltigkeit']['wort']) ?></span>
                                <span class="kb-meta"><?= View::e($n['gueltigkeit']['text']) ?></span></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
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
