<?php
use App\Services\KatalogImport;
use App\Support\Csrf;
use App\Support\View;

$title = 'Katalog importieren';
$quelle = $stand['quelle'] !== '' ? $stand['quelle'] : $datei;
$subtitle = 'Vorschau: ' . $quelle;
$z = $stand['zahlen'];
$anzahl = count($stand['typen']);
$waehlbar = $z['neu'] + $z['anders'];
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="/anlagen/katalog/import">← andere Datei</a>
    <div class="spacer"></div>
    <form method="post" action="/anlagen/katalog/import/verwerfen">
        <?= Csrf::field() ?>
        <button class="btn btn-sm btn-ghost" type="submit">Vorschau verwerfen</button>
    </form>
</div>

<div class="card">
    <div class="card-head"><h2><?= View::e($quelle) ?></h2>
        <span class="hint"><?= $datei !== '' && $datei !== $quelle ? View::e($datei) . ' · ' : '' ?><?= $anzahl ?> <?= $anzahl === 1 ? 'Typ' : 'Typen' ?></span></div>
    <div class="card-body">
        <div class="import-zahlen">
            <?php foreach (KatalogImport::ZUSTAENDE as $k => [$text, $klasse]): ?>
                <?php if ($z[$k] > 0): ?><span class="badge <?= View::e($klasse) ?>"><?= (int) $z[$k] ?> <?= View::e($text) ?></span><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php if ($stand['steckertypen'] !== []): ?>
            <div class="notice<?= $erlaubt['stecker'] ? '' : ' warn' ?> mt">
                <strong>Neue Steckertypen:</strong>
                <?= View::e(implode(', ', array_map(static fn ($s) => $s['name'] . ' (' . count($s['typen']) . ' ' . (count($s['typen']) === 1 ? 'Typ' : 'Typen') . ')', $stand['steckertypen']))) ?>.
                <?= $erlaubt['stecker']
                    ? 'Sie werden mit angelegt, sobald ein Typ übernommen wird, der sie benutzt.'
                    : 'Anlegen darf sie nur, wer den Steckverbinder-Katalog pflegt – Typen mit diesen Steckern lassen sich so nicht übernehmen.' ?>
            </div>
        <?php endif; ?>
        <?php if ($z['anders'] > 0): ?>
            <p class="small mt mb0"><strong>hier anders</strong> heißt: der Typ steht hier anders als in der Datei – meist, weil ihn jemand
                korrigiert hat. Er bleibt, wie er ist, außer du hakst ausdrücklich <em>überschreiben</em> an.</p>
        <?php endif; ?>
        <?php if ($waehlbar === 0): ?>
            <p class="small mt mb0 muted">Nichts zu übernehmen – alle Typen stehen schon genauso hier oder taugen nicht.</p>
        <?php endif; ?>
    </div>
</div>

<form method="post" action="/anlagen/katalog/import/uebernehmen" class="card" id="import-auswahl">
    <?= Csrf::field() ?>
    <input type="hidden" name="kennung" value="<?= View::e($kennung) ?>">
    <div class="card-head"><h2>Typen</h2>
        <?php if ($waehlbar > 0): ?>
            <div class="btn-row">
                <button class="btn btn-sm" type="button" data-auswahl-an="#import-auswahl input[data-zustand=neu]">alle neuen</button>
                <button class="btn btn-sm btn-ghost" type="button" data-auswahl-aus="#import-auswahl input[type=checkbox]">keine</button>
            </div>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="tabelle-karten import-tabelle">
            <thead><tr><th>Auswahl</th><th>Typ</th><th>Art</th><th>Inhalt</th><th>Zustand</th></tr></thead>
            <tbody>
            <?php foreach ($stand['typen'] as $n => $t): ?>
                <?php [$text, $klasse] = KatalogImport::ZUSTAENDE[$t['zustand']]; ?>
                <tr class="import-<?= View::e($t['zustand']) ?>">
                    <td class="import-haken">
                        <?php if ($t['zustand'] === 'neu'): ?>
                            <label class="check"><input type="checkbox" name="neu[]" value="<?= View::e($t['schluessel']) ?>" data-zustand="neu" checked> übernehmen</label>
                        <?php elseif ($t['zustand'] === 'anders'): ?>
                            <label class="check"><input type="checkbox" name="ueberschreiben[]" value="<?= View::e($t['schluessel']) ?>" data-zustand="anders"> überschreiben</label>
                        <?php else: ?>
                            <span class="muted small"><?= $t['zustand'] === 'gleich' ? 'nichts zu tun' : 'nicht übernehmbar' ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Typ">
                        <strong><?= $t['id'] !== null ? '<a href="/anlagen/' . (int) $t['id'] . '">' . View::e($t['name']) . '</a>' : View::e($t['name']) ?></strong>
                        <?php if ($t['schluessel'] !== ''): ?><div class="small muted mono"><?= View::e($t['schluessel']) ?></div><?php endif; ?>
                    </td>
                    <td data-label="Art"><?= View::e($t['art_name'] !== '' ? $t['art_name'] : '–') ?></td>
                    <td data-label="Inhalt" class="small"><?= View::e($t['kurz'] !== '' ? $t['kurz'] : '–') ?></td>
                    <td data-label="Zustand">
                        <span class="badge <?= View::e($klasse) ?>"><?= View::e($text) ?></span>
                        <?php if ($t['fehler'] !== null): ?><div class="small import-grund"><?= View::e($t['fehler']) ?></div><?php endif; ?>
                        <?php if ($t['zustand'] === 'anders'): ?>
                            <details class="import-unterschiede">
                                <summary class="small">Unterschiede: <?= View::e(implode(', ', $t['unterschiede'])) ?></summary>
                                <dl class="import-abweichungen">
                                    <?php foreach ($t['abweichungen'] as $a): ?>
                                        <div>
                                            <dt><?= View::e($a['feld']) ?></dt>
                                            <dd><span class="import-wo">hier</span><?= View::e($a['hier']) ?></dd>
                                            <dd><span class="import-wo">Datei</span><?= View::e($a['datei']) ?></dd>
                                        </div>
                                    <?php endforeach; ?>
                                </dl>
                            </details>
                        <?php endif; ?>
                        <?php foreach ($t['hinweise'] as $h): ?><div class="small muted"><?= View::e($h) ?></div><?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($waehlbar > 0): ?>
        <div class="card-footer btn-row import-fuss">
            <button class="btn btn-primary" type="submit">Ausgewählte übernehmen</button>
            <span class="small muted" data-auswahl-zahl="#import-auswahl input[type=checkbox]" aria-live="polite"></span>
        </div>
    <?php endif; ?>
</form>
