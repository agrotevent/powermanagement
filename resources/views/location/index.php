<?php
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Location-Abgleich';
$subtitle = 'Grundrisse und Feststromanschlüsse über das gemeinsame Repository mit MasterAdmin';
$statusText = ['neu' => 'noch nicht hier', 'aktuell' => 'aktuell', 'neuer' => 'dort neuer', 'eingespielt' => 'übernommen'];
?>
<?php if ($hindernis !== null): ?>
    <div class="notice warn"><?= View::e($hindernis) ?></div>
<?php endif; ?>
<div class="card">
    <div class="card-body">
        <p>Grundrisse, Etagen und Maßstab kommen aus <code>location.json</code> – das Format von MasterAdmin. Daneben liegen in
            <code>strom.json</code> die <strong>Feststromanschlüsse</strong> der Location (Hausanschluss, Energiesäulen, Wandverteiler …)
            mit ihren Anschlüssen, Sicherungen und Messgeräten und die <strong>fest verlegten Kabel und Kabelwege</strong> (Erdkabel, Trassen).
            Mobile Verteiler, Aggregate, mobile Kabel und Kabelbrücken gehören zur Veranstaltung: Sie werden nicht übertragen und beim
            Übernehmen nicht angetastet.
            Dosen und Racks aus MasterAdmin erscheinen auf der Karte als abschaltbare Ebene, werden hier aber nicht geändert.</p>
        <p class="small muted mb0">Repository: <span class="mono"><?= View::e($repo !== '' ? $repo : 'nicht eingetragen') ?></span> · Zweig <span class="mono"><?= View::e($zweig) ?></span></p>
    </div>
    <div class="card-footer btn-row">
        <a class="btn btn-primary btn-sm<?= $hindernis !== null ? ' disabled' : '' ?>" href="/location?holen=1">Repository abfragen</a>
        <?php if (View::can('settings.manage') || View::can('inventory.push')): ?><a class="btn btn-sm" href="/system/einstellungen">Einstellungen</a><?php endif; ?>
    </div>
</div>

<?php if ($liste !== null && $liste['ok']): ?>
<div class="card">
    <div class="card-head"><h2>Im Repository</h2><span class="hint">Stand <?= View::e($liste['commit']) ?></span></div>
    <?php if ($liste['hinweis'] !== ''): ?><div class="card-body"><div class="notice warn"><?= View::e($liste['hinweis']) ?></div></div><?php endif; ?>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Location</th><th class="num">Etagen</th><th class="num">Grundrisse</th><th>Feststromanschlüsse</th><th>Hier</th><th></th></tr></thead>
            <tbody>
            <?php if ($liste['locations'] === []): ?><tr><td colspan="6" class="muted">Das Repository enthält noch keine Location.</td></tr><?php endif; ?>
            <?php foreach ($liste['locations'] as $l): ?>
                <tr>
                    <td data-label="Location"><strong><?= View::e($l['name']) ?></strong><div class="small muted mono"><?= View::e($l['schluessel']) ?></div></td>
                    <td data-label="Etagen" class="num"><?= (int) $l['etagen'] ?></td>
                    <td data-label="Grundrisse" class="num"><?= (int) $l['plaene'] ?></td>
                    <td data-label="Feststromanschlüsse"><?= $l['hat_strom'] ? (int) $l['fest'] . ($l['fest_leitungen'] > 0 ? ' · ' . (int) $l['fest_leitungen'] . ' feste Leitungen' : '') : '<span class="muted">keine</span>' ?></td>
                    <td data-label="Hier"><span class="badge <?= $l['status'] === 'aktuell' ? 'ok' : ($l['status'] === 'neuer' ? 'warn' : '') ?>"><?= View::e($statusText[$l['status']] ?? $l['status']) ?></span>
                        <?= $l['synced_at'] !== '' ? '<div class="small muted">' . View::e(Zeit::anzeige($l['synced_at'])) . '</div>' : '' ?></td>
                    <td class="right">
                        <form method="post" action="/location/uebernehmen"><?= Csrf::field() ?><input type="hidden" name="schluessel" value="<?= View::e($l['schluessel']) ?>">
                            <button class="btn btn-sm<?= $l['status'] !== 'aktuell' ? ' btn-primary' : '' ?>" type="submit">Übernehmen</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($darfHoch): ?>
<div class="card">
    <div class="card-head"><h2>Hochladen</h2><span class="hint">schreibt Feststromanschlüsse und feste Leitungen in strom.json, ergänzt location.json nur, wo etwas fehlt</span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Standort</th><th>Schlüssel</th><th>Wird übertragen</th><th>Zuletzt abgeglichen</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($standorte as $s): ?>
                <tr>
                    <td data-label="Standort"><strong><?= View::e($s['name']) ?></strong></td>
                    <td data-label="Schlüssel" class="mono small"><?= View::e($s['sync_key'] !== '' ? $s['sync_key'] : 'wird beim ersten Hochladen vergeben') ?></td>
                    <?php $n = $anzahl[(int) $s['id']] ?? []; ?>
                    <td data-label="Wird übertragen" class="small"><?= (int) ($n['fest'] ?? 0) ?> Feststromanschlüsse<?= ($n['fest_leitungen'] ?? 0) > 0 ? ', ' . (int) $n['fest_leitungen'] . ' feste Leitungen' : '' ?>
                        <?= ($n['mobil'] ?? 0) + ($n['mobil_leitungen'] ?? 0) > 0
                            ? '<div class="muted">' . (int) ($n['mobil'] ?? 0) . ' mobile Anlagen und ' . (int) ($n['mobil_leitungen'] ?? 0) . ' mobile Leitungen bleiben hier</div>' : '' ?></td>
                    <td data-label="Zuletzt" class="small"><?= View::e(Zeit::anzeige($s['synced_at'])) ?></td>
                    <td class="right">
                        <form method="post" action="/location/hochladen" data-confirm="Feststromanschlüsse von „<?= View::e($s['name']) ?>“ ins gemeinsame Repository hochladen?">
                            <?= Csrf::field() ?><input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
                            <button class="btn btn-sm" type="submit"<?= $hindernis !== null ? ' disabled' : '' ?>>Hochladen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
