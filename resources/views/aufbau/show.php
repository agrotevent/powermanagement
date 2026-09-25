<?php
use App\Services\AufbauService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = $aufbau['name'];
$subtitle = 'Aufbau · ' . $aufbau['site_name'];
$z = $uebersicht['zaehler'];
$gesamt = array_sum($z) - $z['zusaetzlich'];
$erledigt = $gesamt - $z['offen'];
$klasse = ['offen' => '', 'bestaetigt' => 'ok', 'geaendert' => 'warn', 'entfallen' => 'bad', 'zusaetzlich' => 'info'];
$laeuft = $aufbau['status'] !== 'beendet';
$eintraege = array_values(array_filter($uebersicht['eintraege'], static fn ($e) => $filter === '' || $e['status'] === $filter));
$meter = static fn ($v) => $v === null ? '–' : number_format((float) $v, 1, ',', '.') . ' m';
?>
<div class="toolbar-zeile">
    <span class="badge <?= ['aufbau' => 'warn', 'betrieb' => 'ok', 'beendet' => ''][$aufbau['status']] ?? '' ?>"><?= View::e(AufbauService::STATUS[$aufbau['status']]) ?></span>
    <span class="small muted">gestartet <?= View::e(Zeit::anzeige((string) $aufbau['gestartet_am'])) ?> von <?= View::e((string) $aufbau['gestartet_von']) ?>
        <?= $aufbau['betrieb_ab'] ? ' · Betrieb ab ' . View::e(Zeit::anzeige((string) $aufbau['betrieb_ab'])) : '' ?>
        <?= $aufbau['beendet_am'] ? ' · beendet ' . View::e(Zeit::anzeige((string) $aufbau['beendet_am'])) : '' ?></span>
    <div class="spacer"></div>
    <div class="btn-row">
        <?php if ($laeuft && $etage !== null): ?><a class="btn btn-sm btn-primary" href="/karte?etage=<?= (int) $etage ?>&amp;modus=aufbau">In der Karte bestätigen</a><?php endif; ?>
        <a class="btn btn-sm" href="/aufbau/<?= (int) $aufbau['id'] ?>/abgleich">Abgleich</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="aufbau-fortschritt" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $gesamt ?>" aria-valuenow="<?= $erledigt ?>">
            <?php foreach (['bestaetigt', 'geaendert', 'entfallen'] as $k): ?>
                <span class="teil <?= $k ?>" style="width: <?= $gesamt > 0 ? round($z[$k] / $gesamt * 100, 1) : 0 ?>%"></span>
            <?php endforeach; ?>
        </div>
        <p class="small mb0"><strong><?= $erledigt ?> von <?= $gesamt ?></strong> Positionen erledigt –
            <?= $z['bestaetigt'] ?> wie geplant, <?= $z['geaendert'] ?> geändert, <?= $z['entfallen'] ?> entfallen<?= $z['zusaetzlich'] > 0 ? ', ' . $z['zusaetzlich'] . ' zusätzlich' : '' ?>.</p>
        <?php if ($darf && $laeuft): ?>
            <div class="btn-row mt">
                <?php if ($aufbau['status'] === 'aufbau'): ?>
                    <form method="post" action="/aufbau/<?= (int) $aufbau['id'] ?>/fertig" data-confirm="Aufbau fertig melden? Der Ist-Stand wird festgehalten, ab jetzt läuft der Betrieb.">
                        <?= Csrf::field() ?><button class="btn btn-sm btn-primary" type="submit">Aufbau fertig</button></form>
                <?php endif; ?>
                <form method="post" action="/aufbau/<?= (int) $aufbau['id'] ?>/beenden" class="inline-form" data-confirm="Veranstaltung beenden?">
                    <?= Csrf::field() ?>
                    <label class="check mb0"><input type="checkbox" name="abbauen" value="1" checked> <span>Inventar abbauen (Shellys messen dann nicht mehr hier)</span></label>
                    <button class="btn btn-sm" type="submit">Veranstaltung beenden</button></form>
            </div>
        <?php endif; ?>
    </div>
</div>

<nav class="tabs" aria-label="Filter">
    <?php foreach (['' => 'Alle', 'offen' => 'Offen', 'geaendert' => 'Geändert', 'entfallen' => 'Entfallen', 'zusaetzlich' => 'Zusätzlich'] as $k => $n): ?>
        <a class="tab<?= $filter === $k ? ' active' : '' ?>" href="?zeigen=<?= $k ?>"><?= $n ?><?= $k !== '' ? ' (' . $z[$k] . ')' : '' ?></a>
    <?php endforeach; ?>
</nav>

<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten aufbau-liste">
            <thead><tr><th>Position</th><th>Stand</th><th class="num">Länge Plan / echt</th><th>Notiz</th><th></th></tr></thead>
            <tbody>
            <?php if ($eintraege === []): ?><tr><td colspan="5" class="muted">Nichts in dieser Ansicht.</td></tr><?php endif; ?>
            <?php foreach ($eintraege as $e): ?>
                <?php $kabel = $e['art'] === 'leitung'; $darfHier = $laeuft && ($kabel ? $darfKabel : $darf); ?>
                <tr class="aufbau-<?= $e['status'] ?>">
                    <td data-label="Position"><?php if (!$kabel): ?><a href="/anlagen/<?= (int) $e['id'] ?>"><strong><?= View::e($e['name']) ?></strong></a><?php else: ?><strong><?= View::e($e['name']) ?></strong><?php endif; ?>
                        <div class="small muted"><?= $kabel ? 'Kabel' : 'Anlage' ?><?= $e['text'] !== '' ? ' · ' . View::e($e['text']) : '' ?></div></td>
                    <td data-label="Stand"><span class="badge <?= $klasse[$e['status']] ?>"><?= View::e(AufbauService::POSTEN[$e['status']]) ?></span></td>
                    <td data-label="Länge" class="num small"><?= $kabel ? View::e($meter($e['laenge_plan']) . ' / ' . $meter($e['laenge_ist'])) : '' ?></td>
                    <td data-label="Notiz" class="small"><?= View::e($e['notiz']) ?></td>
                    <td class="right">
                        <?php if ($darfHier && $e['status'] !== 'zusaetzlich'): ?>
                            <div class="aufbau-knoepfe">
                                <?php foreach (['bestaetigt' => '✓ wie geplant', 'entfallen' => 'entfällt'] as $st => $text): ?>
                                    <?php if ($e['status'] !== $st): ?>
                                        <form method="post" action="/aufbau/<?= (int) $aufbau['id'] ?>/posten"><?= Csrf::field() ?>
                                            <input type="hidden" name="art" value="<?= $e['art'] ?>"><input type="hidden" name="ref_id" value="<?= (int) $e['id'] ?>">
                                            <input type="hidden" name="status" value="<?= $st ?>"><input type="hidden" name="zeigen" value="<?= View::e($filter) ?>">
                                            <button class="btn btn-sm<?= $st === 'bestaetigt' ? ' btn-primary' : ' btn-ghost' ?>" type="submit"><?= $text ?></button></form>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <details class="aufbau-aendern"><summary class="btn btn-sm">geändert …</summary>
                                    <form method="post" action="/aufbau/<?= (int) $aufbau['id'] ?>/posten"><?= Csrf::field() ?>
                                        <input type="hidden" name="art" value="<?= $e['art'] ?>"><input type="hidden" name="ref_id" value="<?= (int) $e['id'] ?>">
                                        <input type="hidden" name="status" value="geaendert"><input type="hidden" name="zeigen" value="<?= View::e($filter) ?>">
                                        <?php if ($kabel): ?><div class="field"><label>echte Länge (m)</label><input name="laenge_ist" inputmode="decimal" value="<?= View::e($e['laenge_ist'] !== null ? str_replace('.', ',', (string) $e['laenge_ist']) : '') ?>"></div><?php endif; ?>
                                        <div class="field"><label>Was ist anders?</label><input name="notiz" value="<?= View::e($e['notiz']) ?>" placeholder="<?= $kabel ? 'z. B. um die Bühne herum verlegt' : 'z. B. 10 m weiter links, PD 63 statt PD 32' ?>"></div>
                                        <button class="btn btn-sm btn-primary" type="submit">Speichern</button></form>
                                </details>
                                <?php if ($e['status'] !== 'offen'): ?>
                                    <form method="post" action="/aufbau/<?= (int) $aufbau['id'] ?>/posten"><?= Csrf::field() ?>
                                        <input type="hidden" name="art" value="<?= $e['art'] ?>"><input type="hidden" name="ref_id" value="<?= (int) $e['id'] ?>">
                                        <input type="hidden" name="status" value="offen"><input type="hidden" name="zeigen" value="<?= View::e($filter) ?>">
                                        <button class="btn btn-sm btn-ghost" type="submit" title="zurücknehmen">↺</button></form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($darf): ?>
    <form method="post" action="/aufbau/<?= (int) $aufbau['id'] ?>/loeschen" data-confirm="Aufbau „<?= View::e($aufbau['name']) ?>“ samt Abgleich löschen? Anlagen und Kabel bleiben.">
        <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Aufbau löschen</button></form>
<?php endif; ?>
