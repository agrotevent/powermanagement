<?php
use App\Services\AnlagenService;
use App\Services\MesspunktService;
use App\Support\View;
use App\Support\Zeit;
use App\Tanks\TankService;

$title = $objekt['name'];
$art = AnlagenService::ARTEN[$objekt['art']];
$abgaenge = array_values(array_filter($anschluesse, static fn ($a) => $a['richtung'] === 'abgang'));
$eingaenge = array_values(array_filter($anschluesse, static fn ($a) => $a['richtung'] === 'eingang'));
?>
<div class="druck-blatt">
    <header class="druck-kopf">
        <div>
            <h1><?= View::e($objekt['name']) ?></h1>
            <div><?= View::e($art['name']) ?><?= $objekt['kennung'] !== '' ? ' · ' . View::e($objekt['kennung']) : '' ?>
                <?= $objekt['site_name'] ? ' · ' . View::e($objekt['site_name']) : '' ?></div>
        </div>
        <div class="right small">
            <?= View::e(trim($objekt['hersteller'] . ' ' . $objekt['modell'])) ?><br>
            Stand <?= View::e(Zeit::anzeige(gmdate('c'))) ?>
        </div>
    </header>

    <?php if (!AnlagenService::elektrisch((string) $objekt['art'])): ?>
        <?php /* Ein Tank hat keine Einspeisung, Schutzorgane und Abgänge – nur seine Daten. */ ?>
        <h2>Daten</h2>
        <table>
            <tbody>
            <?php foreach (AnlagenService::feldGruppen((string) $objekt['art']) as $schluesselListe): ?>
                <?php foreach ($schluesselListe as $k): ?>
                    <?php if (!isset($objekt['eigenschaften'][$k])) { continue; } ?>
                    <?php [$beschriftung, $typ, $zusatz] = AnlagenService::FELDER[$objekt['art']][$k]; $wert = $objekt['eigenschaften'][$k]; ?>
                    <tr><th><?= View::e(TankService::beschriftung($beschriftung)) ?></th><td><?= View::e(is_float($wert) ? TankService::zahl($wert) : (string) $wert) ?><?= $typ !== 'auswahl' && $zusatz !== '' ? ' ' . View::e($zusatz) : '' ?></td></tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
    <h2>Einspeisung</h2>
    <table>
        <thead><tr><th>Eingang</th><th>Stecker</th><th>gespeist von</th></tr></thead>
        <tbody>
        <?php foreach ($eingaenge as $e): ?>
            <tr><td><?= View::e($e['bezeichnung']) ?></td><td><?= View::e($e['typ_name']) ?></td><td><?= View::e((string) ($e['leitung']['gegenstelle'] ?? '–')) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($eingaenge === []): ?><tr><td colspan="3">–</td></tr><?php endif; ?>
        </tbody>
    </table>

    <h2>Schutzorgane</h2>
    <table>
        <thead><tr><th>Kennzeichen / Art</th><th>hängt hinter</th><th>Notiz</th></tr></thead>
        <tbody>
        <?php $nachId = array_column($schutz, null, 'id'); ?>
        <?php foreach ($schutz as $s): ?>
            <tr>
                <td style="padding-left: <?= 0.4 + 1.2 * (int) $s['tiefe'] ?>rem"><?= View::e($s['text']) ?></td>
                <td><?= $s['vorgeschaltet_id'] !== null ? View::e((string) ($nachId[$s['vorgeschaltet_id']]['bezeichnung'] ?? '')) : 'Einspeisung' ?></td>
                <td><?= View::e((string) $s['notizen']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($schutz === []): ?><tr><td colspan="3">–</td></tr><?php endif; ?>
        </tbody>
    </table>

    <h2>Abgänge</h2>
    <table>
        <thead><tr><th>Abgang</th><th>Stecker</th><th>Schutz</th><th>Phase</th><th>Verbraucher / Kabel</th></tr></thead>
        <tbody>
        <?php foreach ($abgaenge as $a): ?>
            <tr>
                <td><?= View::e($a['bezeichnung']) ?></td>
                <td><?= View::e($a['typ_name']) ?></td>
                <td><?= View::e($a['schutz_text'] ?: '–') ?></td>
                <td><?= View::e($a['phase'] ?: ((int) $a['typ_phasen'] === 3 ? '3~' : '–')) ?></td>
                <td><?= View::e((string) ($a['leitung']['gegenstelle'] ?? '')) ?> <?= View::e((string) $a['notizen']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($abgaenge === []): ?><tr><td colspan="5">–</td></tr><?php endif; ?>
        </tbody>
    </table>

    <?php endif; ?>

    <?php if ($messpunkte !== []): ?>
        <h2>Messgeräte</h2>
        <table>
            <thead><tr><th>Messgerät</th><th>misst</th><th>Gerät · Kanal</th></tr></thead>
            <tbody>
            <?php foreach ($messpunkte as $m): ?>
                <tr><td><?= View::e($m['bezeichnung'] ?: '–') ?></td><td><?= View::e(MesspunktService::ROLLEN[$m['rolle']] ?? $m['rolle']) ?><?= $m['anschluss_name'] ? ', Abgang ' . View::e($m['anschluss_name']) : '' ?></td><td><?= View::e($m['geraet'] . ' · ' . $m['kanal']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if ((string) $objekt['notizen'] !== ''): ?>
        <h2>Notizen</h2>
        <p><?= nl2br(View::e((string) $objekt['notizen'])) ?></p>
    <?php endif; ?>
</div>
