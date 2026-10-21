<?php
/**
 * Liste der Locations, die der Kunde jetzt sieht – je Location eine Zeile,
 * die ganze Zeile führt zur Location (Übersicht und Locations).
 *
 * @var list<array{site:array{id:int,name:string,address:string},freigaben:list<array<string,mixed>>}> $locations
 * @var bool $mitAnschrift
 */
use App\Kunden\Kundenbereich;
use App\Support\View;
use App\Support\Zeit;

$mitAnschrift = $mitAnschrift ?? false;
?>
<ul class="kb-orte">
    <?php foreach ($locations as $l): ?>
        <li>
            <a class="kb-ort" href="<?= View::e(Kundenbereich::PFAD . '/locations/' . (int) $l['site']['id']) ?>">
                <span class="kb-ort-text">
                    <span class="kb-ort-name"><?= View::e($l['site']['name']) ?></span>
                    <?php if ($mitAnschrift && $l['site']['address'] !== ''): ?><span class="kb-meta"><?= View::e($l['site']['address']) ?></span><?php endif; ?>
                    <?php foreach ($l['freigaben'] as $f): ?>
                        <span class="kb-meta"><?= $f['veranstaltung'] !== '' ? View::e((string) $f['veranstaltung']) . ' · ' : '' ?>sichtbar bis <?= View::e(Zeit::anzeige($f['bis'])) ?></span>
                    <?php endforeach; ?>
                </span>
                <span class="kb-pfeil" aria-hidden="true">›</span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
