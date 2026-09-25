<?php
/**
 * Abgleich Planung ↔ Umsetzung – auf der Seite und im Druck gleich.
 *
 * @var array<string,mixed> $aufbau
 * @var array<string,mixed> $abgleich aus AufbauService::abgleich()
 * @var array{0:int,1:int} $betrieb
 */
use App\Messung\Messdaten;
use App\Services\AufbauService;
use App\Support\View;
use App\Support\Zeit;

$g = $abgleich;
$z = $g['zaehler'];
$klasse = ['offen' => '', 'bestaetigt' => 'ok', 'geaendert' => 'warn', 'entfallen' => 'bad', 'zusaetzlich' => 'info'];
$meter = static fn ($v) => $v === null ? '–' : number_format((float) $v, 1, ',', '.') . ' m';
$prozent = static fn ($v) => $v === null ? '–' : number_format((float) $v, 0, ',', '.') . ' %';
$stuecke = static fn (array $s) => $s === [] ? '–' : implode(', ', array_map(static fn ($l, $n) => $n . ' × ' . $l . ' m', array_keys($s), $s));
[$von, $bis] = $betrieb;
?>
<div class="grid grid-4 kennzahlen">
    <div class="metric"><span class="metric-label">Wie geplant</span><span class="metric-value"><?= (int) $z['bestaetigt'] ?> / <?= array_sum($z) - (int) $z['zusaetzlich'] ?></span></div>
    <div class="metric"><span class="metric-label">Geändert · entfallen · zusätzlich</span><span class="metric-value"><?= (int) $z['geaendert'] ?> · <?= (int) $z['entfallen'] ?> · <?= (int) $z['zusaetzlich'] ?></span></div>
    <div class="metric"><span class="metric-label">Kabel Plan → echt</span><span class="metric-value"><?= View::e($meter($g['meter_plan'])) ?> → <?= View::e($meter($g['meter_ist'])) ?></span></div>
    <div class="metric"><span class="metric-label">Abweichung der Kabellängen</span><span class="metric-value"><?= $g['abweichung_prozent'] !== null ? ($g['abweichung_prozent'] > 0 ? '+' : '') . View::e($prozent($g['abweichung_prozent'])) : '–' ?></span></div>
</div>
<?php if ($g['reserve_empfehlung'] !== null): ?>
    <div class="notice warn mt">Die Kabel waren insgesamt <?= View::e($prozent($g['abweichung_prozent'])) ?> länger als im Plan – die Reserve von
        <?= View::e($prozent($g['reserve'])) ?> reicht nicht. Für die nächste Planung: <strong><?= (int) $g['reserve_empfehlung'] ?> %</strong>
        (<em>System → Einstellungen → Kabelreserve</em>) – oder die Wege im Plan genauer zeichnen.</div>
<?php elseif ($z['offen'] > 0 && $aufbau['status'] !== 'aufbau'): ?>
    <div class="notice small mt"><?= (int) $z['offen'] ?> Position(en) wurden nie bestätigt – sie zählen hier mit ihrem Stand aus der Karte.</div>
<?php endif; ?>

<h2 class="mt">Last im Betrieb</h2>
<p class="small muted">Gemessen <?= View::e(Zeit::anzeige(Zeit::iso($von))) ?> – <?= View::e($aufbau['beendet_am'] ? Zeit::anzeige(Zeit::iso($bis)) : 'jetzt') ?>:
    höchster Phasenstrom gegen den Nennstrom der Anlage. Daran sieht man, wo beim nächsten Mal kleiner oder größer geplant werden sollte.</p>
<div class="table-wrap"><table class="tabelle-karten">
    <thead><tr><th>Anlage</th><th class="num">Spitze</th><th class="num">höchster Strom</th><th class="num">Auslastung</th><th>Für die nächste Planung</th></tr></thead>
    <tbody>
    <?php if ($g['last'] === []): ?><tr><td colspan="5" class="muted">Noch keine Messwerte aus dem Betrieb.</td></tr><?php endif; ?>
    <?php foreach ($g['last'] as $l): ?>
        <tr><td data-label="Anlage"><strong><?= View::e($l['name']) ?></strong><div class="small muted"><?= View::e($l['art'] . ($l['modell'] !== '' ? ' · ' . $l['modell'] : '')) ?></div></td>
            <td data-label="Spitze" class="num"><?= View::e(Messdaten::format($l['p_max'], 'W')) ?></td>
            <td data-label="höchster Strom" class="num"><?= View::e(Messdaten::format($l['i_max'], 'A')) ?><?= $l['nennstrom'] !== null ? ' <span class="small muted">/ ' . View::e(number_format((float) $l['nennstrom'], 0, ',', '.')) . ' A</span>' : '' ?></td>
            <td data-label="Auslastung" class="num"><?= View::e($prozent($l['auslastung'])) ?></td>
            <td data-label="Empfehlung"><?= $l['empfehlung']['stufe'] !== '' ? '<span class="badge ' . View::e($l['empfehlung']['stufe']) . '">' . View::e($l['empfehlung']['text']) . '</span>' : '<span class="small muted">' . View::e($l['empfehlung']['text']) . '</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<?php if ($g['bereiche'] !== []): ?>
    <h3 class="mt">Bereiche</h3>
    <div class="table-wrap"><table class="tabelle-karten">
        <thead><tr><th>Bereich</th><th class="num">höchste gleichzeitige Last</th><th class="num">Grenze</th><th class="num">Auslastung</th><th class="num">Energie</th></tr></thead>
        <tbody>
        <?php foreach ($g['bereiche'] as $b): ?>
            <tr><td data-label="Bereich"><strong><?= View::e($b['name']) ?></strong></td>
                <td data-label="Spitze" class="num"><?= View::e(Messdaten::format($b['p_max'], 'W')) ?></td>
                <td data-label="Grenze" class="num"><?= View::e(Messdaten::format($b['grenze_w'], 'W')) ?></td>
                <td data-label="Auslastung" class="num"><?= View::e($prozent($b['auslastung'])) ?></td>
                <td data-label="Energie" class="num"><?= View::e(Messdaten::format($b['energie_wh'], 'Wh')) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>

<h2 class="mt">Anlagen</h2>
<div class="table-wrap"><table class="tabelle-karten">
    <thead><tr><th>Anlage</th><th>Plan</th><th>Aufgebaut</th><th>Stand</th><th>Abweichung</th></tr></thead>
    <tbody>
    <?php if ($g['anlagen'] === []): ?><tr><td colspan="5" class="muted">Keine mobilen Anlagen geplant.</td></tr><?php endif; ?>
    <?php foreach ($g['anlagen'] as $a): ?>
        <tr><td data-label="Anlage"><strong><?= View::e($a['name']) ?></strong></td>
            <td data-label="Plan" class="small"><?= View::e($a['plan']) ?></td>
            <td data-label="Aufgebaut" class="small"><?= View::e($a['ist']) ?></td>
            <td data-label="Stand"><span class="badge <?= $klasse[$a['status']] ?? '' ?>"><?= View::e(AufbauService::POSTEN[$a['status']] ?? $a['status']) ?></span></td>
            <td data-label="Abweichung" class="small"><?= View::e(implode(', ', $a['abweichungen'])) ?><?= $a['notiz'] !== '' ? '<div class="muted">' . View::e($a['notiz']) . '</div>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<h2 class="mt">Kabel und Kabelbrücken</h2>
<div class="table-wrap"><table class="tabelle-karten">
    <thead><tr><th>Strecke</th><th class="num">Plan</th><th class="num">echt</th><th class="num">Differenz</th><th>Stand</th></tr></thead>
    <tbody>
    <?php if ($g['kabel'] === []): ?><tr><td colspan="5" class="muted">Keine mobilen Kabel geplant.</td></tr><?php endif; ?>
    <?php foreach ($g['kabel'] as $k): ?>
        <?php $d = $k['art'] === 'kabel' ? $k['differenz'] : null; ?>
        <tr><td data-label="Strecke"><strong><?= View::e($k['name']) ?></strong><?= $k['art'] === 'kabelbruecke' ? ' <span class="small muted">Kabelbrücke</span>' : '' ?>
                <?= $k['notiz'] !== '' ? '<div class="small muted">' . View::e($k['notiz']) . '</div>' : '' ?></td>
            <td data-label="Plan" class="num"><?= $k['art'] === 'kabel' ? View::e($meter($k['laenge_plan'])) : '' ?></td>
            <td data-label="echt" class="num"><?= $k['art'] === 'kabel' ? View::e($meter($k['laenge_ist'])) : '' ?></td>
            <td data-label="Differenz" class="num<?= $d !== null && abs($d) >= 5 ? ' abweichung' : '' ?>"><?= $d !== null ? ($d > 0 ? '+' : '') . View::e($meter($d)) : '' ?></td>
            <td data-label="Stand"><span class="badge <?= $klasse[$k['status']] ?? '' ?>"><?= View::e(AufbauService::POSTEN[$k['status']] ?? $k['status']) ?></span></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<h2 class="mt">Material: geplant und gebraucht</h2>
<div class="table-wrap"><table class="tabelle-karten material-tabelle">
    <thead><tr><th>Kabel</th><th>geplant</th><th>gebraucht</th><th class="num">Meter geplant → gebraucht</th></tr></thead>
    <tbody>
    <?php if ($g['material'] === []): ?><tr><td colspan="4" class="muted">Keine Kabel mit Länge.</td></tr><?php endif; ?>
    <?php foreach ($g['material'] as $m): ?>
        <tr<?= $m['plan'] != $m['ist'] ? ' class="abweichung-zeile"' : '' ?>><td data-label="Kabel"><strong><?= View::e($m['titel']) ?></strong></td>
            <td data-label="geplant" class="small"><?= View::e($stuecke($m['plan'])) ?></td>
            <td data-label="gebraucht" class="small"><?= View::e($stuecke($m['ist'])) ?></td>
            <td data-label="Meter" class="num"><?= View::e(number_format($m['meter_plan'], 0, ',', '.') . ' → ' . number_format($m['meter_ist'], 0, ',', '.')) ?> m</td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
