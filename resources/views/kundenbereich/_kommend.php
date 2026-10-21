<?php
/**
 * Locations, die der Kunde erst noch sehen wird – nur Name und „ab …“,
 * kein Link (vorher gibt es dort für ihn nichts).
 *
 * @var list<array{name:string,ab:string}> $kommend
 */
use App\Support\View;
use App\Support\Zeit;
?>
<ul class="liste-schlicht">
    <?php foreach ($kommend as $k): ?>
        <li><strong><?= View::e($k['name']) ?></strong>
            <span class="kb-meta">sichtbar ab <?= View::e(Zeit::anzeige($k['ab'])) ?></span></li>
    <?php endforeach; ?>
</ul>
