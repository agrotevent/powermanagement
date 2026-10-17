<?php
use App\Services\InventarService;
use App\Support\Csrf;
use App\Support\View;
use App\Tanks\TankRechnung;

$title = $item['kennung'];
$subtitle = $item['art_name'] . ' · ' . $item['typ'] . ($item['name'] !== '' ? ' · ' . $item['name'] : '');
$e = $item['einsatz'];
$ts = $item['art'] === 'tanksensor';
// Sensordaten eines Tanksensors als Zeilen „Beschriftung – Wert“.
$sensorZeilen = [];
$sensorHinweise = [];
if ($ts && $item['eigenschaften'] !== []) {
    $s = TankRechnung::sensorNormal($item['eigenschaften']);
    $z = static fn (mixed $w): string => TankRechnung::zahl((float) $w);
    $einheit = TankRechnung::ROHFELDER[$s['feld']][1] ?? '';
    $sensorZeilen['Messprinzip'] = TankRechnung::PRINZIPIEN[$s['prinzip']] ?? $s['prinzip'];
    $sensorZeilen['Wert des Geräts'] = (TankRechnung::ROHFELDER[$s['feld']][0] ?? $s['feld']) . ($einheit !== '' ? ' in ' . $einheit : '') . ' (' . $s['feld'] . ')';
    $sensorZeilen['Signal'] = TankRechnung::SIGNALE[$s['signal']] ?? $s['signal'];
    if ($s['buerde_ohm'] !== null) {
        $sensorZeilen['Bürde'] = $z($s['buerde_ohm']) . ' Ω';
    }
    if ($s['u_bei_4ma'] !== null && $s['u_bei_20ma'] !== null) {
        $sensorZeilen['Wandler'] = '4 mA = ' . $z($s['u_bei_4ma']) . ' V, 20 mA = ' . $z($s['u_bei_20ma']) . ' V';
    }
    if ($s['bereich_bis'] !== null) {
        $sensorZeilen['Messbereich'] = $z($s['bereich_von']) . '–' . $z($s['bereich_bis']) . ' ' . (TankRechnung::BEREICHSEINHEITEN[$s['bereich_einheit'] ?? ''] ?? '');
    }
    if ($s['direkt_einheit'] !== null) {
        $sensorZeilen['Liefert'] = ['prozent' => 'Füllstand in %', 'liter' => 'Inhalt in Litern', 'mm' => 'Füllhöhe in mm'][$s['direkt_einheit']];
    }
    if ($s['totzone_mm'] !== null) {
        $sensorZeilen['Totzone'] = $z($s['totzone_mm']) . ' mm';
    }
    $sensorZeilen['Meldeschritt'] = $z($s['schritt_roh']) . ($einheit !== '' ? ' ' . $einheit : '');
    if ($s['hinweis'] !== '') {
        $sensorZeilen['Hinweis'] = $s['hinweis'];
    }
    if ($s['signal'] === '4-20mA-wandler' && (float) $s['u_bei_4ma'] < 0.5) {
        $sensorHinweise[] = TankRechnung::HINWEIS_LEITUNGSBRUCH;
    }
    if ($s['signal'] === '0-10V') {
        $sensorHinweise[] = TankRechnung::HINWEIS_0_10V;
    }
}
?>
<div class="toolbar-zeile">
    <?php if ($item['zustand'] === 'defekt'): ?><span class="badge bad">defekt</span>
    <?php elseif ($e !== null): ?><span class="badge info">im Einsatz</span>
    <?php else: ?><span class="badge ok">im Lager</span><?php endif; ?>
    <?php if ($item['seriennummer'] !== ''): ?><span class="small muted">Seriennummer <?= View::e($item['seriennummer']) ?></span><?php endif; ?>
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
    <div>
    <?php if ($ts): ?>
        <div class="card">
            <div class="card-head"><h2>Sensordaten</h2><span class="hint">daraus wird beim Einbau die Kennlinie</span></div>
            <div class="card-body">
                <?php if ($sensorZeilen === []): ?><p class="small muted">Noch keine Sensordaten – ohne sie gibt es im Tank nur den Rohwert.</p><?php endif; ?>
                <dl class="kv">
                    <?php foreach ($sensorZeilen as $beschriftung => $wert): ?><dt><?= View::e($beschriftung) ?></dt><dd><?= View::e($wert) ?></dd><?php endforeach; ?>
                </dl>
                <?php foreach ($sensorHinweise as $h): ?><div class="notice warn mt"><?= View::e($h) ?></div><?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-head"><h2><?= $ts ? 'Messkanal' : 'Shellys in diesem Stück' ?></h2><span class="hint"><?= $ts ? 'misst im Tank, in dem der Sensor eingebaut ist' : 'messen dort, wo das Stück eingesetzt ist' ?></span></div>
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
    </div>
    <div class="card">
        <div class="card-head"><h2>Einsätze</h2></div>
        <div class="card-body">
            <?php if ($geschichte === []): ?><p class="muted small"><?= $ts
                ? 'Noch nie eingebaut. Eingebaut wird auf der Seite des Tanks oder in der Karte (Aufbau).'
                : 'Noch nie eingesetzt. Eingesetzt wird an der Anlage (Reiter „Messung“) oder in der Karte.' ?></p><?php endif; ?>
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
