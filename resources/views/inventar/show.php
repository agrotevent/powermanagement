<?php
use App\Services\InventarService;
use App\Support\Csrf;
use App\Support\View;

$title = $item['kennung'];
$subtitle = $item['art_name'] . ' · ' . $item['typ'] . ($item['name'] !== '' ? ' · ' . $item['name'] : '');
$e = $item['einsatz'];
?>
<div class="toolbar-zeile">
    <?php if ($item['zustand'] === 'defekt'): ?><span class="badge bad">defekt</span>
    <?php elseif ($e !== null): ?><span class="badge info">im Einsatz</span>
    <?php else: ?><span class="badge ok">im Lager</span><?php endif; ?>
    <?php if ($item['seriennummer'] !== ''): ?><span class="small muted">Seriennummer <?= View::e($item['seriennummer']) ?></span><?php endif; ?>
    <?php if (View::can('berichte.view')): ?>
        <span class="small">Letzte Prüfung: <?php if (!empty($letztePruefung)): ?><a href="/berichte/<?= (int) $letztePruefung['id'] ?>"><?= View::e((string) ($letztePruefung['nummer'] ?? ('#' . $letztePruefung['id']))) ?></a>
            am <?= View::e(preg_replace('/^(\d{4})-(\d{2})-(\d{2})$/', '$3.$2.$1', (string) $letztePruefung['pruefdatum'])) ?><?php else: ?><span class="muted">keine</span><?php endif; ?></span>
    <?php endif; ?>
    <div class="spacer"></div>
    <?php if ($darf): ?>
        <div class="btn-row">
            <a class="btn btn-sm btn-primary" href="/inventar/<?= (int) $item['id'] ?>/bearbeiten">Bearbeiten</a>
            <?php if ($e === null): ?>
                <form method="post" action="/inventar/<?= (int) $item['id'] ?>/loeschen" data-confirm="<?= View::e($item['kennung']) ?> aus dem Inventar löschen?">
                    <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Löschen</button></form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-sidebar">
    <div class="card">
        <div class="card-head"><h2>Shellys in diesem Stück</h2><span class="hint">messen dort, wo das Stück eingesetzt ist</span></div>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Gerät · Kanal</th><th>Sitzt an</th><th>Bezeichnung</th></tr></thead>
                <tbody>
                <?php if ($messgeraete === []): ?><tr><td colspan="3" class="muted">Noch kein Shelly eingetragen.</td></tr><?php endif; ?>
                <?php foreach ($messgeraete as $g): ?>
                    <tr><td data-label="Gerät · Kanal" class="mono small"><?= View::e($g['geraet']) ?> · <?= View::e($g['kanal']) ?></td>
                        <td data-label="Sitzt an"><?= View::e($stellen[$g['stelle']] ?? $g['stelle']) ?></td>
                        <td data-label="Bezeichnung" class="small"><?= View::e($g['bezeichnung']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ((string) $item['notizen'] !== ''): ?><div class="card-body small"><?= nl2br(View::e((string) $item['notizen'])) ?></div><?php endif; ?>
    </div>
    <div class="card">
        <div class="card-head"><h2>Einsätze</h2></div>
        <div class="card-body">
            <?php if ($geschichte === []): ?><p class="muted small">Noch nie eingesetzt. Eingesetzt wird an der Anlage (Reiter „Messung“) oder in der Karte.</p><?php endif; ?>
            <ul class="liste-schlicht einsatz-liste">
                <?php foreach ($geschichte as $g): ?>
                    <li>
                        <strong><?php if ($g['sichtbar']): ?><a href="/anlagen/<?= (int) $g['objekt_id'] ?>"><?= View::e($g['anlage_name'] ?? $g['objekt_name']) ?></a><?php else: ?><?= View::e($g['anlage_name'] !== null ? 'nicht freigegeben' : $g['objekt_name'] . ' (gelöscht)') ?><?php endif; ?></strong>
                        <?= $g['sichtbar'] && $g['anschluss_name'] ? '<span class="small">· ' . View::e($g['anschluss_name']) . '</span>' : '' ?>
                        <div class="small muted"><?= View::e(($g['sichtbar'] ? (string) ($g['site_name'] ?? '') . ' · ' : '') . InventarService::zeitraum($g)) ?></div>
                        <?php if ($g['darf_abbauen']): ?>
                            <form method="post" action="/einsatz/<?= (int) $g['id'] ?>/beenden" data-confirm="<?= View::e($item['kennung']) ?> abbauen? Die Shellys messen dann nicht mehr dort.">
                                <?= Csrf::field() ?><input type="hidden" name="zurueck" value="inventar">
                                <button class="btn btn-sm" type="submit">Abbauen</button></form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
