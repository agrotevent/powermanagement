<?php
use App\Services\AufbauService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Aufbau & Abgleich';
$subtitle = 'Vom Plan zum echten Aufbau – und hinterher: Was kam wie geplant, was nicht?';
$klasse = ['aufbau' => 'warn', 'betrieb' => 'ok', 'beendet' => ''];
?>
<?php if ($standorte !== []): ?>
    <form method="post" action="/aufbau/starten" class="card">
        <?= Csrf::field() ?>
        <div class="card-head"><h2>Aufbau starten</h2></div>
        <div class="card-body">
            <p class="small muted">Hält den Plan des Standorts fest – mobile Anlagen, Kabel, Kabelbrücken und die Materialliste. Vor Ort wird dann jede
                Position in der Karte oder in der Liste bestätigt, als geändert markiert oder als entfallen. Der Abgleich zeigt hinterher, was
                anders kam, und was die gemessene Last für die nächste Planung heißt.</p>
            <div class="field-row">
                <div class="field"><label for="site_id">Standort</label>
                    <select id="site_id" name="site_id" required>
                        <?php foreach ($standorte as $s): ?><option value="<?= (int) $s['id'] ?>"><?= View::e($s['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="field"><label for="name">Veranstaltung</label><input id="name" name="name" placeholder="z. B. Sommerfestival 2026"></div>
            </div>
            <button class="btn btn-primary" type="submit">Aufbau starten</button>
        </div>
    </form>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Aufbauten</h2></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Veranstaltung</th><th>Standort</th><th>Stand</th><th>Fortschritt</th><th>Zeitraum</th><th></th></tr></thead>
            <tbody>
            <?php if ($liste === []): ?><tr><td colspan="6" class="muted">Noch kein Aufbau. Wenn die Planung eines Standorts fertig ist: oben starten.</td></tr><?php endif; ?>
            <?php foreach ($liste as $a): ?>
                <?php $z = $fortschritt[(int) $a['id']] ?? null; ?>
                <tr>
                    <td data-label="Veranstaltung"><a href="/aufbau/<?= (int) $a['id'] ?>"><strong><?= View::e($a['name']) ?></strong></a></td>
                    <td data-label="Standort"><?= View::e((string) $a['site_name']) ?></td>
                    <td data-label="Stand"><span class="badge <?= $klasse[$a['status']] ?? '' ?>"><?= View::e(AufbauService::STATUS[$a['status']] ?? $a['status']) ?></span></td>
                    <td data-label="Fortschritt" class="small">
                        <?php if ($z !== null): ?>
                            <?php $gesamt = array_sum($z) - $z['zusaetzlich']; $erledigt = $gesamt - $z['offen']; ?>
                            <?= $erledigt ?> von <?= $gesamt ?> erledigt<?= $z['geaendert'] + $z['entfallen'] > 0 ? ' · ' . ($z['geaendert'] + $z['entfallen']) . ' anders' : '' ?>
                        <?php else: ?>–<?php endif; ?>
                    </td>
                    <td data-label="Zeitraum" class="small"><?= View::e(Zeit::anzeige((string) $a['gestartet_am'], 'd.m.Y')) ?>
                        <?= $a['beendet_am'] ? ' – ' . View::e(Zeit::anzeige((string) $a['beendet_am'], 'd.m.Y')) : '' ?></td>
                    <td class="right"><a class="btn btn-sm" href="/aufbau/<?= (int) $a['id'] ?>/abgleich">Abgleich</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
