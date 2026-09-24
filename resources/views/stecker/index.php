<?php
use App\Support\Csrf;
use App\Support\View;

$title = 'Steckverbinder';
$subtitle = 'Katalog der Anschlussarten – Powerlock, CEE und Schuko stehen von Anfang an darin';
$zahl = static fn ($v) => rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',');
?>
<div class="grid grid-sidebar">
    <div class="card">
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Steckverbinder</th><th class="num">Nennstrom</th><th>Netz</th><th class="num">in Gebrauch</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($typen as $t): ?>
                    <tr>
                        <td data-label="Steckverbinder"><span class="stecker" style="--stecker-farbe: <?= View::e($t['farbe']) ?>"></span>
                            <strong><?= View::e($t['name']) ?></strong> <?= (int) $t['ist_standard'] === 1 ? '<span class="badge">Standard</span>' : '' ?>
                            <?php if ((string) $t['notizen'] !== ''): ?><div class="small muted"><?= View::e((string) $t['notizen']) ?></div><?php endif; ?></td>
                        <td data-label="Nennstrom" class="num"><?= View::e($zahl($t['strom_a'])) ?> A</td>
                        <td data-label="Netz"><?= (int) $t['phasen'] ?>~ <?= (int) $t['spannung_v'] ?> V</td>
                        <td data-label="in Gebrauch" class="num"><?= (int) $t['benutzt'] ?></td>
                        <td class="right">
                            <details class="inline-details">
                                <summary class="btn btn-sm">Ändern</summary>
                                <?= View::partial('stecker/form', ['t' => $t]) ?>
                                <?php if ((int) $t['benutzt'] === 0): ?>
                                    <form method="post" action="/stecker/loeschen" data-confirm="Steckertyp löschen?">
                                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                        <button class="btn btn-sm btn-danger" type="submit">Löschen</button>
                                    </form>
                                <?php endif; ?>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Weiterer Steckverbinder</h2></div>
        <div class="card-body">
            <?= View::partial('stecker/form', ['t' => null]) ?>
            <p class="help">Etwa CEE 16 A blau (230 V), CEE 32 A blau, Harting, Socapex, Perilex. Die Farbe ist die Farbe des Punkts in Listen und Plänen.</p>
        </div>
    </div>
</div>
