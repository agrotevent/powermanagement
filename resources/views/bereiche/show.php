<?php
use App\Messung\Messdaten;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = $bereich['name'];
$subtitle = 'Bereich · ' . ($bereich['site_name'] ?? '');
$zeitraumQuery = static function (array $zusatz) use ($vorgabe, $von, $bis, $reiter): string {
    $basis = ['zeitraum' => $vorgabe, 'reiter' => $reiter];
    if ($vorgabe === 'frei') {
        $basis['von'] = Zeit::fuerEingabe(Zeit::iso($von));
        $basis['bis'] = Zeit::fuerEingabe(Zeit::iso($bis));
    }
    return '?' . http_build_query(array_filter(array_merge($basis, $zusatz), static fn ($v) => $v !== null));
};
$l = $live;
$id = (int) $bereich['id'];
$zeitParameter = $vorgabe === 'frei' ? '&von=' . rawurlencode(Zeit::fuerEingabe(Zeit::iso($von))) . '&bis=' . rawurlencode(Zeit::fuerEingabe(Zeit::iso($bis))) : '';
?>
<div class="grid grid-2">
    <?= View::partial('bereiche/kachel', ['bereich' => $bereich + ['mitglieder_zahl' => count($aufbau)], 'l' => $l]) ?>
    <div class="card">
        <div class="card-body">
            <?php if ((string) $bereich['notizen'] !== ''): ?><p class="small"><?= nl2br(View::e((string) $bereich['notizen'])) ?></p><?php endif; ?>
            <div class="btn-row">
                <?php if ($darf): ?><a class="btn btn-sm" href="/bereiche/<?= $id ?>/bearbeiten">Bearbeiten</a><?php endif; ?>
                <a class="btn btn-sm" href="/bereiche/<?= $id ?>?reiter=material">Materialliste</a>
                <?php if (View::can('statistik.export')): ?><a class="btn btn-sm" href="/bereiche/<?= $id ?>/csv?zeitraum=<?= View::e($vorgabe) ?><?= View::e($zeitParameter) ?>">CSV</a><?php endif; ?>
                <?php if ($darf): ?>
                    <form method="post" action="/bereiche/<?= $id ?>/loeschen" data-confirm="Bereich „<?= View::e($bereich['name']) ?>“ löschen? Die Anlagen bleiben."><?= Csrf::field() ?>
                        <button class="btn btn-sm btn-ghost" type="submit">Löschen</button></form>
                <?php endif; ?>
            </div>
            <span class="small muted" data-live-url="/bereiche/live?id=<?= $id ?>"></span>
        </div>
    </div>
</div>

<div class="tabs mt">
    <a class="tab<?= $reiter === 'last' ? ' active' : '' ?>" href="?reiter=last">Last</a>
    <a class="tab<?= $reiter === 'material' ? ' active' : '' ?>" href="?reiter=material">Material</a>
</div>

<?php if ($reiter === 'material'): ?>
    <div class="card">
        <div class="card-head"><h2>Material für den Aufbau</h2>
            <a class="btn btn-sm" href="/bereiche/<?= $id ?>/material" target="_blank" rel="noopener">Drucken</a></div>
        <div class="card-body"><?= View::partial('material/liste', ['material' => $material]) ?></div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-head"><h2>Mitglieder</h2><span class="hint">zählt, was nicht schon in einem anderen Mitglied steckt</span></div>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Mitglied</th><th>Art</th><th class="num">Leistung</th><th class="num">Anteil</th><th>Hinweis</th></tr></thead>
                <tbody>
                <?php if ($aufbau === []): ?><tr><td colspan="5" class="muted">Noch keine Mitglieder – über „Bearbeiten“ Anlagen und Abgänge wählen.</td></tr><?php endif; ?>
                <?php foreach ($aufbau as $e): ?>
                    <?php $m = $l['mitglieder'][$e['schluessel']] ?? ['p' => null, 'anteil' => null, 'status' => '']; ?>
                    <tr data-mitglied="<?= View::e($e['schluessel']) ?>"<?= $e['enthalten_in'] !== null ? ' class="muted"' : '' ?>>
                        <td data-label="Mitglied"><a href="/anlagen/<?= (int) $e['objekt']['id'] ?>"><strong><?= View::e($e['titel']) ?></strong></a>
                            <?php if ($e['typ'] === 'messpunkt'): ?><div class="small muted"><?= View::e($e['untertitel']) ?></div><?php endif; ?></td>
                        <td data-label="Art" class="small"><?= $e['typ'] === 'objekt' ? View::e($e['untertitel']) : ($e['ganz'] ? 'Einspeisung' : 'einzelner Abgang') ?></td>
                        <td data-label="Leistung" class="num" data-feld="p"><?= View::e(Messdaten::format($m['p'] ?? null, 'W')) ?></td>
                        <td data-label="Anteil" class="num" data-feld="anteil"><?= ($m['anteil'] ?? null) !== null ? (int) round((float) $m['anteil']) . ' %' : '' ?></td>
                        <td data-label="Hinweis" class="small">
                            <?php if ($e['enthalten_in'] !== null): ?>steckt in „<?= View::e($e['enthalten_in']) ?>“ – zählt nicht doppelt<?php endif; ?>
                            <?= $e['hinweis'] !== '' ? View::e($e['hinweis']) : '' ?>
                            <span data-feld="status"><?= ($m['status'] ?? 'ok') !== 'ok' ? View::e((string) $m['status']) : '' ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($statistik): ?>
        <div class="card">
            <div class="card-head"><h2>Verlauf</h2><span class="hint"><?= View::e(Zeit::anzeige(Zeit::iso($von)) . ' – ' . Zeit::anzeige(Zeit::iso($bis))) ?></span></div>
            <div class="card-body">
                <div class="zeitraum-knoepfe">
                    <?php foreach (['1h' => '1 Std.', '6h' => '6 Std.', 'heute' => 'Heute', '24h' => '24 Std.', '7d' => '7 Tage', '30d' => '30 Tage'] as $k => $n): ?>
                        <a class="btn btn-sm<?= $vorgabe === $k ? ' aktiv' : '' ?>" href="<?= View::e($zeitraumQuery(['zeitraum' => $k, 'von' => null, 'bis' => null])) ?>"><?= $n ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="grid grid-4 kennzahlen mt">
                    <div class="metric"><span class="metric-label">Höchste gleichzeitige Last</span><span class="metric-value"><?= View::e(Messdaten::format($kennzahlen['p_max'] ?? null, 'W')) ?></span></div>
                    <div class="metric"><span class="metric-label">Mittlere Last</span><span class="metric-value"><?= View::e(Messdaten::format($kennzahlen['p_mittel'] ?? null, 'W')) ?></span></div>
                    <div class="metric"><span class="metric-label">Energie</span><span class="metric-value"><?= View::e(Messdaten::format($kennzahlen['energie_wh'] ?? null, 'Wh')) ?></span></div>
                    <div class="metric"><span class="metric-label">Höchster Phasenstrom</span><span class="metric-value"><?= View::e(Messdaten::format($kennzahlen['i_max'] ?? null, 'A')) ?></span></div>
                </div>
                <p class="help">Summe der Mitglieder zu jedem Zeitpunkt (Mittel je <?= (int) round(($kennzahlen['schritt'] ?? 60) / 60) ?> min) – nicht die Summe der einzelnen Spitzen.</p>
                <h3 class="mt">Leistung</h3>
                <div class="diagramm" data-mp="bereich" data-quelle="/bereiche/<?= $id ?>/daten" data-feld="p" data-phasen="L1,L2,L3,sum"
                    <?= $bereich['grenze_kw'] !== null ? 'data-grenze="' . (float) $bereich['grenze_kw'] * 1000 . '"' : '' ?>></div>
                <h3 class="mt">Strom je Phase</h3>
                <div class="diagramm" data-mp="bereich" data-quelle="/bereiche/<?= $id ?>/daten" data-feld="i" data-phasen="L1,L2,L3,sum"
                    <?= $bereich['grenze_a'] !== null ? 'data-grenze="' . (float) $bereich['grenze_a'] . '"' : '' ?>></div>
                <?php if ($energie !== []): ?>
                    <h3 class="mt">Energie je Tag</h3>
                    <div class="table-wrap"><table class="tabelle-karten">
                        <thead><tr><th>Tag</th><th class="num">Energie</th></tr></thead>
                        <tbody>
                        <?php foreach ($energie as $tag => $wh): ?>
                            <tr><td data-label="Tag"><?= View::e((new DateTimeImmutable($tag))->format('d.m.Y')) ?></td><td data-label="Energie" class="num"><?= View::e(Messdaten::format($wh, 'Wh')) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </div>
        <script type="application/json" id="statistik-daten"><?= json_encode([
            'zeitraum' => $vorgabe, 'von' => Zeit::fuerEingabe(Zeit::iso($von)), 'bis' => Zeit::fuerEingabe(Zeit::iso($bis)),
        ], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php endif; ?>
<?php endif; ?>
