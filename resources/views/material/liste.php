<?php
/**
 * Materialliste – für Bereich und Etage, auf der Seite und im Druck gleich.
 *
 * @var array<string,mixed> $material aus MaterialService::berechnen()
 */
use App\Support\View;

$m = $material;
$meter = static fn (float $v): string => number_format($v, 0, ',', '.') . ' m';
// Eine Liste aus einem älteren Aufbau kennt die Schläuche noch nicht.
$schlaeuche = (array) ($m['schlaeuche'] ?? []);
$schlaeucheOhne = (array) ($m['schlaeuche_ohne_laenge'] ?? []);
$kupplungen = (array) ($m['kupplungen'] ?? []);
$festeSchlaeuche = (int) ($m['feste_schlaeuche'] ?? 0);
?>
<p class="small muted">Kabel: Länge im Plan (bzw. eingetragene Länge) plus <?= View::e(number_format((float) $m['reserve'], 0, ',', '.')) ?> % Reserve,
    geteilt in 5, 10, 20, 25 und 50 m. 75 und 100 m nur bei 63 A, 125 A und Powerlock und nur, wo sie mindestens zwei Stücke sparen.</p>

<h3>Anlagen</h3>
<?php if ($m['anlagen'] === []): ?><p class="muted small">Keine mobilen Anlagen.</p><?php else: ?>
<div class="table-wrap"><table class="tabelle-karten material-tabelle">
    <thead><tr><th>Typ</th><th class="num">Anzahl</th><th>Wofür</th></tr></thead>
    <tbody>
    <?php foreach ($m['anlagen'] as $a): ?>
        <tr><td data-label="Typ"><strong><?= View::e($a['titel']) ?></strong></td><td data-label="Anzahl" class="num"><?= (int) $a['anzahl'] ?></td>
            <td data-label="Wofür" class="small"><?= View::e(implode(', ', $a['namen'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<h3 class="mt">Kabel</h3>
<?php if ($m['kabel'] === [] && $m['ohne_laenge'] === []): ?><p class="muted small">Keine mobilen Kabel.</p><?php endif; ?>
<?php if ($m['kabel'] !== []): ?>
<div class="table-wrap"><table class="tabelle-karten material-tabelle">
    <thead><tr><th>Kabel</th><th>Stücke</th><th class="num">Summe</th><th class="num">im Plan</th><th class="num">Kupplungen</th></tr></thead>
    <tbody>
    <?php foreach ($m['kabel'] as $k): ?>
        <tr>
            <td data-label="Kabel"><strong><?= View::e($k['titel']) ?></strong>
                <div class="small muted"><?= (int) $k['kabel'] ?> Strecke(n)<?= $k['einleiter'] ? ' · Stücke je Einzelleiter gezählt' : '' ?></div></td>
            <td data-label="Stücke"><div class="stueckliste"><?php foreach ($k['stuecke'] as $laenge => $anzahl): ?>
                <span class="stueck<?= $laenge >= 75 ? ' selten' : '' ?>"><strong><?= (int) $anzahl ?> ×</strong> <?= (int) $laenge ?> m</span>
            <?php endforeach; ?></div></td>
            <td data-label="Summe" class="num"><?= View::e($meter((float) $k['meter_stuecke'])) ?></td>
            <td data-label="im Plan" class="num"><?= View::e($meter((float) $k['meter_plan'])) ?></td>
            <td data-label="Kupplungen" class="num"><?= (int) $k['kupplungen'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<details class="mt"<?= !empty($druck) ? ' open' : '' ?>><summary class="small">Stückelung je Kabel</summary>
    <div class="table-wrap"><table class="tabelle-karten material-tabelle">
        <thead><tr><th>Strecke</th><th>Kabel</th><th class="num">Plan</th><th class="num">mit Reserve</th><th>Stücke</th></tr></thead>
        <tbody>
        <?php foreach ($m['kabel_einzeln'] as $e): ?>
            <tr><td data-label="Strecke"><?= View::e($e['name']) ?></td><td data-label="Kabel" class="small"><?= View::e($e['titel']) ?></td>
                <td data-label="Plan" class="num"><?= View::e(number_format((float) $e['laenge'], 1, ',', '.')) ?> m<?= $e['gemessen'] ? '' : ' <span class="small muted">(Plan)</span>' ?></td>
                <td data-label="mit Reserve" class="num"><?= (int) $e['benoetigt'] ?> m</td>
                <td data-label="Stücke"><?= View::e(implode(' + ', array_map(static fn ($s) => $s . ' m', $e['stuecke']))) ?><?= $e['faktor'] > 1 ? ' <span class="small muted">× ' . (int) $e['faktor'] . '</span>' : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</details>
<?php endif; ?>
<?php if ($m['ohne_laenge'] !== []): ?>
    <div class="notice warn small mt"><?= count($m['ohne_laenge']) ?> Kabel ohne Länge (kein Maßstab und keine eingetragene Länge):
        <?= View::e(implode(', ', array_map(static fn ($e) => $e['name'] . ' (' . $e['titel'] . ')', $m['ohne_laenge']))) ?></div>
<?php endif; ?>

<h3 class="mt">Kabelbrücken</h3>
<?php if ($m['bruecken'] === []): ?><p class="muted small">Keine.</p><?php else: ?>
<div class="table-wrap"><table class="tabelle-karten material-tabelle">
    <thead><tr><th>Modell</th><th class="num">Elemente</th></tr></thead>
    <tbody><?php foreach ($m['bruecken'] as $modell => $anzahl): ?>
        <tr><td data-label="Modell"><?= View::e((string) $modell) ?></td><td data-label="Elemente" class="num"><?= (int) $anzahl ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php if ($schlaeuche !== [] || $schlaeucheOhne !== []): ?>
    <h3 class="mt">Kraftstoff</h3>
    <?php if ($schlaeuche !== []): ?>
    <div class="table-wrap"><table class="tabelle-karten material-tabelle">
        <thead><tr><th>Schlauch</th><th class="num">Stränge</th><th class="num">Summe</th><th class="num">im Plan</th></tr></thead>
        <tbody><?php foreach ($schlaeuche as $s): ?>
            <tr><td data-label="Schlauch"><strong><?= View::e($s['titel']) ?></strong>
                    <div class="small muted"><?= (int) $s['schlaeuche'] ?> Strecke(n) · Vor- und Rücklauf je ein Strang</div></td>
                <td data-label="Stränge" class="num"><?= (int) $s['straenge'] ?></td>
                <td data-label="Summe" class="num"><?= View::e($meter((float) $s['meter'])) ?></td>
                <td data-label="im Plan" class="num"><?= View::e($meter((float) $s['meter_plan'])) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <details class="mt"<?= !empty($druck) ? ' open' : '' ?>><summary class="small">Schläuche je Strecke</summary>
        <div class="table-wrap"><table class="tabelle-karten material-tabelle">
            <thead><tr><th>Strecke</th><th>Schlauch</th><th class="num">Plan</th><th>mitbringen</th><th>Kupplung</th></tr></thead>
            <tbody><?php foreach ((array) ($m['schlaeuche_einzeln'] ?? []) as $e): ?>
                <tr><td data-label="Strecke"><?= View::e($e['name']) ?></td><td data-label="Schlauch" class="small"><?= View::e($e['titel']) ?></td>
                    <td data-label="Plan" class="num"><?= View::e(number_format((float) $e['laenge'], 1, ',', '.')) ?> m<?= $e['gemessen'] ? '' : ' <span class="small muted">(Plan)</span>' ?></td>
                    <td data-label="mitbringen"><?= (int) $e['anzahl'] ?> × <?= (int) $e['benoetigt'] ?> m</td>
                    <td data-label="Kupplung" class="small"><?= View::e($e['kupplung']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    </details>
    <?php endif; ?>
    <?php if ($kupplungen !== []): ?>
        <p class="small mt">Kupplungen (je Strang zwei): <?= View::e(implode(', ', array_map(static fn ($k, $n) => $n . ' × ' . $k, array_keys($kupplungen), $kupplungen))) ?></p>
    <?php endif; ?>
    <?php if ($schlaeucheOhne !== []): ?>
        <div class="notice warn small mt"><?= count($schlaeucheOhne) ?> Schlauch/Schläuche ohne Länge (kein Maßstab und keine eingetragene Länge):
            <?= View::e(implode(', ', array_map(static fn ($e) => $e['name'] . ' (' . $e['titel'] . ')', $schlaeucheOhne))) ?></div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($m['vorhanden'] !== [] || $m['feste_kabel'] > 0 || $festeSchlaeuche > 0): ?>
    <h3 class="mt">Vor Ort vorhanden (fest installiert)</h3>
    <ul class="small">
        <?php foreach ($m['vorhanden'] as $a): ?><li><?= (int) $a['anzahl'] ?> × <?= View::e($a['titel']) ?> – <?= View::e(implode(', ', $a['namen'])) ?></li><?php endforeach; ?>
        <?php if ($m['feste_kabel'] > 0): ?><li><?= (int) $m['feste_kabel'] ?> fest verlegte(s) Kabel</li><?php endif; ?>
        <?php if ($festeSchlaeuche > 0): ?><li><?= $festeSchlaeuche ?> fest verlegte(r) Kraftstoffschlauch/-schläuche</li><?php endif; ?>
    </ul>
<?php endif; ?>
