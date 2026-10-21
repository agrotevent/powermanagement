<?php
use App\Berichte\BerichtService;
use App\Support\View;
use App\Support\Zeit;

$title = 'Übergabeberichte';
$subtitle = 'Prüfprotokolle der elektrischen Anlage – am Tablet ausfüllen, unterschreiben, als PDF übergeben';
$klasse = ['entwurf' => 'warn', 'geprueft' => 'info', 'abgeschlossen' => 'ok', 'ersetzt' => '', 'verworfen' => ''];
$datum = static fn (string $d): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
?>
<div class="toolbar-zeile">
    <?php if (View::can('berichte.manage')): ?><a class="btn btn-primary" href="/berichte/neu">+ Neuer Bericht</a><?php endif; ?>
    <a class="btn" href="/berichte/pruefgeraete">Prüfgeräte</a>
    <div class="spacer"></div>
    <form method="get" action="/berichte" class="inline-form bericht-filter" role="search">
        <select name="status" aria-label="Status" data-absenden>
            <option value="">alle Stände</option>
            <?php foreach (BerichtService::STATUS as $k => $n): ?>
                <option value="<?= View::e($k) ?>"<?= $filter['status'] === $k ? ' selected' : '' ?>><?= View::e($n) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (count($standorte) > 1): ?>
            <select name="site" aria-label="Standort" data-absenden>
                <option value="">alle Standorte</option>
                <?php foreach ($standorte as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (int) $filter['site_id'] === (int) $s['id'] ? ' selected' : '' ?>><?= View::e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <?php if ($jahre !== []): ?>
            <select name="jahr" aria-label="Jahr" data-absenden>
                <option value="">alle Jahre</option>
                <?php foreach ($jahre as $j): ?><option value="<?= $j ?>"<?= (string) $filter['jahr'] === (string) $j ? ' selected' : '' ?>><?= $j ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <input type="search" name="suche" value="<?= View::e($filter['suche']) ?>" placeholder="Nummer, Kunde, Veranstaltung" aria-label="Suche">
        <button class="btn" type="submit">Suchen</button>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Bericht</th><th>Stand</th><th>Kunde</th><th>Veranstaltung</th><th>Anlage</th><th>Prüfdatum</th></tr></thead>
            <tbody>
            <?php if ($liste === []): ?>
                <tr><td colspan="6" class="muted"><?= array_filter($filter) !== [] ? 'Nichts gefunden.' : 'Noch kein Übergabebericht.' ?>
                    <?= View::can('berichte.manage') ? 'Angelegt wird er im Aufbau, an der Anlage oder über „Neuer Bericht“.' : '' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($liste as $b): ?>
                <tr>
                    <td data-label="Bericht"><a href="/berichte/<?= (int) $b['id'] ?>"><strong><?= View::e($b['nummer'] ?? ('Entwurf #' . $b['id'])) ?></strong></a>
                        <?= (int) $b['fassung'] > 1 ? '<span class="small muted">Fassung ' . (int) $b['fassung'] . '</span>' : '' ?>
                        <?php if ((string) $b['ewx_nummer'] !== ''): ?><div class="small muted">Eventworx <?= View::e($b['ewx_nummer']) ?></div><?php endif; ?></td>
                    <td data-label="Stand"><span class="badge <?= $klasse[$b['status']] ?? '' ?>"><?= View::e(BerichtService::STATUS[$b['status']] ?? $b['status']) ?></span></td>
                    <td data-label="Kunde"><?= View::e($b['kunde_name'] !== '' ? $b['kunde_name'] : '–') ?></td>
                    <td data-label="Veranstaltung"><?= View::e($b['veranstaltung'] !== '' ? $b['veranstaltung'] : '–') ?></td>
                    <td data-label="Anlage"><?= View::e($b['titel'] !== '' ? $b['titel'] : '–') ?>
                        <div class="small muted"><?= View::e((string) ($b['site_name'] ?? $b['standort_name'])) ?></div></td>
                    <td data-label="Prüfdatum" class="nowrap"><?= View::e($datum((string) $b['pruefdatum'])) ?>
                        <?php if ($b['status'] === 'entwurf' && $b['updated_at']): ?><div class="small muted">geändert <?= View::e(Zeit::anzeige((string) $b['updated_at'])) ?></div><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
