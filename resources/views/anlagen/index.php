<?php
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Support\View;

$artInfo = AnlagenService::ARTEN[$filter['art']] ?? null;
$fest = $filter['fest'];
$title = $fest === true ? 'Feststromanschlüsse' : ($artInfo['mehrzahl'] ?? ($fest === false ? 'Mobile Anlagen' : 'Anlagen'));
$subtitle = $fest === true
    ? 'Fest installiert, gehören zur Location – werden beim Location-Abgleich übertragen'
    : ($filter['art'] === 'tank' ? 'Kraftstofftanks im Plan – mit Inhalt, Maßen und Zulassung' : 'Stromanschlüsse, Generatoren, Wechselrichter, Verteilungen und Tanks');
$neuArt = $artInfo !== null ? $filter['art'] : ($fest === true ? 'anschluss' : 'verteilung');
$neuLink = '/anlagen/neu?art=' . $neuArt . ($fest === true ? '&amp;fest=1' : '');
$neuText = $fest === true && ($filter['art'] === '' || $filter['art'] === 'anschluss') ? 'Neuer Feststromanschluss' : ($artInfo['neu'] ?? 'Neue Anlage');
?>
<div class="toolbar-zeile">
    <form method="get" action="/anlagen" class="inline-form">
        <select name="art" data-absenden aria-label="Art">
            <option value="">Alle Arten</option>
            <?php foreach (AnlagenService::ARTEN as $k => $a): ?>
                <option value="<?= $k ?>"<?= $filter['art'] === $k ? ' selected' : '' ?>><?= View::e($a['mehrzahl']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="fest" data-absenden aria-label="Fest oder mobil">
            <option value="">Fest und mobil</option>
            <option value="1"<?= $fest === true ? ' selected' : '' ?>>Feststromanschlüsse</option>
            <option value="0"<?= $fest === false ? ' selected' : '' ?>>Mobile Anlagen</option>
        </select>
        <select name="standort" data-absenden aria-label="Standort">
            <option value="0">Alle Standorte</option>
            <?php foreach ($standorte as $s): ?>
                <option value="<?= (int) $s['id'] ?>"<?= (int) $filter['site_id'] === (int) $s['id'] ? ' selected' : '' ?>><?= View::e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="suche" value="<?= View::e($filter['suche']) ?>" placeholder="Name, Kennung, Modell …">
        <button class="btn btn-sm" type="submit">Suchen</button>
    </form>
    <div class="spacer"></div>
    <?php if (View::can('anlagen.manage')): ?>
        <a class="btn btn-primary btn-sm" href="<?= $neuLink ?>">+ <?= View::e($neuText) ?></a>
    <?php endif; ?>
    <span class="small muted" data-live-url="/dashboard/live<?= $filter['site_id'] ? '?standort=' . (int) $filter['site_id'] : '' ?>"></span>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead>
            <?php $nurTanks = $filter['art'] === 'tank'; ?>
            <tr><th>Anlage</th><th>Standort</th><th><?= $nurTanks ? 'Inhalt' : 'Anschlüsse' ?></th><th>Status</th><th class="num"><?= $nurTanks ? 'Inhalt jetzt' : 'Leistung' ?></th><th class="num"><?= $nurTanks ? 'Füllstand' : 'Auslastung' ?></th></tr>
            </thead>
            <tbody>
            <?php if ($objekte === []): ?>
                <tr><td colspan="6" class="muted">Keine Anlagen gefunden.</td></tr>
            <?php endif; ?>
            <?php foreach ($objekte as $o): ?>
                <?php $l = $live[(int) $o['id']] ?? []; $art = AnlagenService::ARTEN[$o['art']]; ?>
                <tr data-objekt-id="<?= (int) $o['id'] ?>" class="live-zeile stufe-<?= View::e($l['stufe'] ?? 'keine') ?>">
                    <td data-label="Anlage">
                        <span class="art-zeichen" style="--art-farbe: <?= View::e($art['farbe']) ?>"><?= View::e($art['kuerzel']) ?></span>
                        <a href="/anlagen/<?= (int) $o['id'] ?>"><strong><?= View::e($o['name']) ?></strong></a>
                        <?php if ($o['kennung'] !== ''): ?><span class="small muted"><?= View::e($o['kennung']) ?></span><?php endif; ?>
                        <?php if ($o['fest'] === 1): ?><span class="badge info" title="fest installiert – gehört zur Location">fest</span><?php endif; ?>
                        <div class="small muted"><?= View::e(trim($o['hersteller'] . ' ' . $o['modell'])) ?></div>
                    </td>
                    <td data-label="Standort"><?= View::e($o['site_name'] ?? '–') ?><?= $o['floor_name'] ? '<div class="small muted">' . View::e($o['floor_name']) . '</div>' : '' ?></td>
                    <td data-label="<?= AnlagenService::elektrisch((string) $o['art']) ? 'Anschlüsse' : 'Inhalt' ?>" class="small"><?= View::e($o['kurzform']) ?></td>
                    <td data-label="Status"><span class="badge status-<?= View::e($o['status']) ?>"><?= View::e(AnlagenService::STATUS[$o['status']] ?? $o['status']) ?></span></td>
                    <?php if (AnlagenService::elektrisch((string) $o['art'])): ?>
                        <td data-label="Leistung" class="num" data-feld="p"><?= View::e(Messdaten::format($l['p'] ?? null, 'W')) ?></td>
                        <td data-label="Auslastung" class="num" data-feld="auslastung"><?= ($l['auslastung'] ?? null) !== null ? (int) round((float) $l['auslastung']) . ' %' : '' ?></td>
                    <?php else: ?>
                        <?php $t = is_array($l['tank'] ?? null) ? $l['tank'] : []; ?>
                        <td data-label="Inhalt jetzt" class="num" data-tank="menge"><?= is_numeric($t['liter'] ?? null) ? View::e(\App\Tanks\TankService::zahl(round((float) $t['liter']))) . ' l'
                            : (is_numeric($t['hoehe_mm'] ?? null) ? View::e(\App\Tanks\TankService::zahl(round((float) $t['hoehe_mm']))) . ' mm' : '–') ?></td>
                        <td data-label="Füllstand" class="num" data-tank="prozent"><?= is_numeric($t['prozent'] ?? null) ? View::e(\App\Tanks\TankService::zahl(round((float) $t['prozent']))) . ' %' : '' ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
