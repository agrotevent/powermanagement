<?php
/**
 * Prüfnachweise: die Prüfprotokolle der Geräte, die Sie bei uns gemietet
 * haben (DGUV Vorschrift 3, DIN EN 50699) – freigegebene, mit Frist und PDF.
 * Wahlweise nur die einer Location (GET, ohne Skript mit Knopf).
 *
 * @var array<string,mixed> $kunde
 * @var list<array<string,mixed>> $nachweise KundenSicht::nachweise() (gefiltert)
 * @var array{alle:int,laeuft_ab:int,abgelaufen:int,unbekannt:int} $zahlen über alle Nachweise
 * @var array<int,string> $orte Locations mit Nachweisen (ID => Name)
 * @var ?int $ort gewählte Location
 */
use App\Kunden\Kundenbereich;
use App\Support\View;

$title = 'Prüfnachweise';
?>
<h1 class="kb-titel">Prüfnachweise</h1>
<p class="kb-einleitung">Prüfprotokolle der Geräte, die Sie bei uns gemietet haben – Verteiler, Kabel, Verlängerungen (DGUV Vorschrift 3,
    DIN EN 50699). Sie belegen die Prüfung des einzelnen Geräts; die Prüfung der Anlage vor Ort steht im
    <a href="<?= View::e(Kundenbereich::PFAD . '/protokolle') ?>">Übergabebericht</a>. Mehr dazu in der
    <a href="<?= View::e(Kundenbereich::PFAD . '/hilfe') ?>">Hilfe</a>.</p>

<?php if ($zahlen['alle'] > 0): ?>
    <ul class="kb-zahlen" aria-label="Stand der Prüffristen">
        <li><strong><?= (int) $zahlen['alle'] ?></strong> <?= $zahlen['alle'] === 1 ? 'Nachweis' : 'Nachweise' ?></li>
        <?php if ($zahlen['abgelaufen'] > 0): ?><li class="kb-frist-abgelaufen"><span aria-hidden="true">✗</span> <strong><?= (int) $zahlen['abgelaufen'] ?></strong> abgelaufen</li><?php endif; ?>
        <?php if ($zahlen['laeuft_ab'] > 0): ?><li class="kb-frist-laeuft_ab"><span aria-hidden="true">!</span> <strong><?= (int) $zahlen['laeuft_ab'] ?></strong> <?= $zahlen['laeuft_ab'] === 1 ? 'läuft' : 'laufen' ?> in 30 Tagen ab</li><?php endif; ?>
        <?php if ($zahlen['unbekannt'] > 0): ?><li><span aria-hidden="true">?</span> <strong><?= (int) $zahlen['unbekannt'] ?></strong> ohne Datum der nächsten Prüfung</li><?php endif; ?>
    </ul>
<?php endif; ?>

<?php if (count($orte) > 0): ?>
    <form method="get" action="<?= View::e(Kundenbereich::PFAD . '/pruefnachweise') ?>" class="kb-filter">
        <label for="kb-ort">Location</label>
        <select id="kb-ort" name="location" data-absenden>
            <option value="">alle Locations</option>
            <?php foreach ($orte as $sid => $name): ?>
                <option value="<?= (int) $sid ?>"<?= $ort === (int) $sid ? ' selected' : '' ?>><?= View::e($name) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-ghost" type="submit">Anzeigen</button>
    </form>
<?php endif; ?>

<section class="card" aria-labelledby="kb-nachweise-titel">
    <div class="card-head"><h2 id="kb-nachweise-titel"><?= $ort !== null ? View::e($orte[$ort]) : 'Ihre Prüfnachweise' ?></h2></div>
    <?php if ($nachweise === []): ?>
        <div class="card-body"><p class="kb-leer"><?= $zahlen['alle'] > 0 ? 'Für diese Location liegt kein Prüfnachweis vor.'
            : 'Für Sie liegt noch kein Prüfnachweis vor. Prüfnachweise erscheinen hier, sobald wir sie für Sie freigegeben haben.' ?></p></div>
    <?php else: ?>
        <?= View::partial('kundenbereich/_nachweise', ['nachweise' => $nachweise]) ?>
    <?php endif; ?>
    <div class="card-footer small muted">Werden Geräte an wechselnden Orten eingesetzt, muss dort ein Nachweis der letzten Prüfung vorliegen
        (BetrSichV § 14 Abs. 7) – laden Sie die PDFs dafür aufs Telefon oder drucken Sie diese Seite.</div>
</section>
