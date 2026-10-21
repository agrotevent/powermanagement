<?php
/**
 * Ein Änderungsvorschlag: woher, was er je Etage ändern würde, annehmen
 * (gewählte Etagen und Teile) oder ablehnen.
 *
 * Alles von der Gegenseite (Absender, Notiz, Namen dort, Namen von Anlagen
 * und Kabeln) geht durch View::e(). Die Plandatei nur als Download.
 *
 * @var array<string,mixed> $v VorschlagService::find()
 * @var array<string,mixed>|null $unterschiede VorschlagService::unterschiede() – nur offen
 * @var array{grundriss:bool,feststrom:bool,stecker:bool,ablehnen:bool} $darf
 */
use App\Services\VorschlagService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $v['id'];
$status = (string) $v['status'];
[$zText, $zKlasse] = VorschlagService::ZUSTAENDE[$status] ?? [$status, ''];
$entscheidung = (array) $v['entscheidung'];
$direkt = ($entscheidung['direkt'] ?? false) === true;
$ort = $v['site_name'] !== null ? (string) $v['site_name'] : (string) $v['site_key'];
$title = 'Vorschlag Nr. ' . $id;
$subtitle = $ort . ' · von ' . (string) $v['absender'];
$planText = [
    'neu'    => ['neue Plandatei', 'info'],
    'seite'  => ['andere Seite derselben Datei', 'info'],
    'fehlt'  => ['Plan dort geändert, Datei fehlt – der Plan hier bleibt', 'warn'],
    'gleich' => ['unverändert', ''],
    'ohne'   => ['kein Plan im Vorschlag – der Plan hier bleibt', ''],
];
$namen = static function (array $liste, string $zeichen, string $klasse = ''): string {
    if ($liste === []) {
        return '';
    }
    $mehr = count($liste) > 12 ? ' <span class="muted">… und ' . (count($liste) - 12) . ' weitere</span>' : '';
    return '<li' . ($klasse !== '' ? ' class="' . $klasse . '"' : '') . '>' . $zeichen . ' '
        . implode(', ', array_map(static fn (string $n): string => View::e($n), array_slice($liste, 0, 12))) . $mehr . '</li>';
};
?>
<p><a href="/vorschlaege">← alle Vorschläge</a></p>

<div class="card">
    <div class="card-body">
        <dl class="zugangsdaten">
            <dt>Location</dt>
            <dd><?= $v['site_name'] !== null ? '<strong>' . View::e((string) $v['site_name']) . '</strong>' : '<span class="badge warn">Standort gelöscht</span>' ?>
                <span class="small muted mono"><?= View::e((string) $v['site_key']) ?></span></dd>
            <dt>Von</dt>
            <dd><?= View::e((string) $v['absender']) ?>
                <?php if ((string) $v['token_name'] !== ''): ?><span class="small muted">über den Zugang „<?= View::e((string) $v['token_name']) ?>“</span><?php endif; ?></dd>
            <dt>Eingang</dt>
            <dd class="small"><?= View::e(Zeit::anzeige((string) $v['created_at'])) ?><?= (int) $v['bytes'] > 0 ? ' · ' . View::e(number_format((int) $v['bytes'] / 1048576, 1, ',', '.')) . ' MiB Dateien' : '' ?></dd>
            <dt>Zustand</dt>
            <dd><span class="badge <?= View::e($zKlasse) ?>"><?= View::e($direkt ? 'direkt übernommen' : $zText) ?></span>
                <?php if ($status !== VorschlagService::OFFEN): ?>
                    <span class="small muted"><?= View::e(Zeit::anzeige((string) $v['entschieden_at'])) ?> · <?= View::e((string) $v['entschieden_von']) ?></span>
                <?php endif; ?></dd>
            <?php if ($status === VorschlagService::ANGENOMMEN): ?>
                <dt>Übernommen</dt>
                <dd class="small"><?= View::e(implode(', ', array_map('strval', (array) ($entscheidung['etagen'] ?? [])))) ?>
                    · <?= View::e(implode(', ', array_map(static fn ($t): string => VorschlagService::TEILE[(string) $t] ?? (string) $t, (array) ($entscheidung['teile'] ?? [])))) ?></dd>
            <?php elseif ($status === VorschlagService::ABGELEHNT && (string) ($entscheidung['grund'] ?? '') !== ''): ?>
                <dt>Grund</dt>
                <dd class="small vorschlag-notiz"><?= View::e((string) $entscheidung['grund']) ?></dd>
            <?php elseif ($status === VorschlagService::ERSETZT && (int) ($entscheidung['ersetzt_durch'] ?? 0) > 0): ?>
                <dt>Ersetzt durch</dt>
                <dd><a href="/vorschlaege/<?= (int) $entscheidung['ersetzt_durch'] ?>">Nr. <?= (int) $entscheidung['ersetzt_durch'] ?></a></dd>
            <?php endif; ?>
        </dl>
        <?php if ((string) ($v['notiz'] ?? '') !== ''): ?>
            <div class="notice vorschlag-notiz"><?= View::e((string) $v['notiz']) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($unterschiede !== null): ?>
    <?php foreach ($unterschiede['hinweise'] as $h): ?>
        <div class="notice warn"><?= View::e($h) ?></div>
    <?php endforeach; ?>

    <?php if ($unterschiede['standort']): ?>
    <form method="post" action="/vorschlaege/<?= $id ?>/annehmen"
          data-confirm="<?= View::e('Die angehakten Etagen von „' . $ort . '“ bekommen den Stand aus dem Vorschlag. Namen, Schaltsperren und Messpunkte hier bleiben, gelöscht wird nichts. Annehmen?') ?>">
        <?= Csrf::field() ?>
        <div class="card">
            <div class="card-head"><h2>Etagen</h2>
                <span class="hint">Angehakt sind die, an denen sich etwas ändern würde.</span></div>
            <div class="table-wrap">
                <table class="tabelle-karten vorschlag-etagen">
                    <thead><tr><th>Übernehmen</th><th>Etage</th><th>Grundriss</th><th>Maßstab · Notizen · Georeferenz</th><?= $unterschiede['feststrom'] ? '<th>Feststrom</th>' : '' ?></tr></thead>
                    <tbody>
                    <?php foreach ($unterschiede['etagen'] as $e): ?>
                        <?php [$pText, $pKlasse] = $planText[$e['plan']] ?? [$e['plan'], '']; ?>
                        <tr>
                            <td data-label="Übernehmen">
                                <label class="check"><input type="checkbox" name="etagen[]" value="<?= View::e((string) $e['schluessel']) ?>"
                                    <?= $e['geaendert'] && $e['gibt_es'] ? ' checked' : '' ?><?= $e['gibt_es'] ? '' : ' disabled' ?>
                                    aria-label="Etage <?= View::e((string) $e['name']) ?> übernehmen"> <span class="small"><?= $e['geaendert'] ? 'geändert' : 'unverändert' ?></span></label>
                            </td>
                            <td data-label="Etage"><strong><?= View::e((string) $e['name']) ?></strong>
                                <?php foreach ($e['info'] as $i): ?><div class="small muted"><?= View::e($i) ?></div><?php endforeach; ?></td>
                            <td data-label="Grundriss"><span class="badge <?= View::e($pKlasse) ?>"><?= View::e($pText) ?></span>
                                <?php if ($e['plan_datei'] !== null): ?>
                                    <div class="small"><a href="/vorschlaege/<?= $id ?>/datei?pfad=<?= View::e(rawurlencode((string) $e['plan_datei'])) ?>">Datei herunterladen</a></div>
                                <?php endif; ?></td>
                            <td data-label="Maßstab · Notizen · Georeferenz" class="small">
                                <?php $etwas = false; ?>
                                <?php if ($e['massstab']): $etwas = true; ?><span class="badge info">Maßstab geändert</span><?php endif; ?>
                                <?php if ($e['massstab_text']): $etwas = true; ?><span class="badge info">Maßstab-Text geändert</span><?php endif; ?>
                                <?php if ($e['notizen']): $etwas = true; ?><span class="badge info">Notizen geändert</span><?php endif; ?>
                                <?php if ($e['georeferenz'] === 'geaendert'): $etwas = true; ?><span class="badge info">Georeferenz geändert</span><?php endif; ?>
                                <?php if (!$etwas): ?><span class="muted">unverändert</span><?php endif; ?>
                            </td>
                            <?php if ($unterschiede['feststrom']): ?>
                                <td data-label="Feststrom" class="small">
                                    <?php
                                    $liste = '';
                                    foreach (['anlagen' => 'Anlagen', 'leitungen' => 'Kabel'] as $art => $wort) {
                                        $d = $e[$art];
                                        if ($d === null) {
                                            continue;
                                        }
                                        $zeilen = $namen($d['neu'], '+') . $namen($d['geaendert'], '~') . $namen($d['fehlt'], '−', 'muted');
                                        if ($zeilen !== '') {
                                            $liste .= '<div class="vorschlag-art">' . $wort . '</div><ul class="vorschlag-namen">' . $zeilen . '</ul>';
                                        }
                                    }
                                    ?>
                                    <?= $liste !== '' ? $liste : '<span class="muted">unverändert</span>' ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($unterschiede['feststrom']): ?>
                <div class="card-body small muted">+ neu · ~ geändert · − fehlt im Vorschlag (bleibt hier – gelöscht wird nichts)</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head"><h3>Annehmen</h3></div>
            <div class="card-body">
                <label class="check"><input type="checkbox" name="teile[]" value="grundriss" checked<?= $darf['grundriss'] ? '' : ' disabled' ?>>
                    <span><?= View::e(VorschlagService::TEILE['grundriss']) ?></span></label>
                <?php if ($unterschiede['feststrom']): ?>
                    <label class="check"><input type="checkbox" name="teile[]" value="feststrom"<?= $darf['feststrom'] ? ' checked' : ' disabled' ?>>
                        <span><?= View::e(VorschlagService::TEILE['feststrom']) ?><?= $darf['feststrom'] ? '' : ' <span class="muted">(braucht „Anlagen ändern“ und „Kabel einzeichnen“)</span>' ?></span></label>
                <?php endif; ?>
                <?php if ($unterschiede['stecker_neu'] !== []): ?>
                    <div class="notice<?= $darf['stecker'] ? '' : ' warn' ?>">Neue Steckertypen: <?= View::e(implode(', ', $unterschiede['stecker_neu'])) ?>
                        – <?= $darf['stecker'] ? 'sie werden beim Annehmen des Feststroms im Katalog angelegt.' : 'anlegen darf sie nur, wer die Steckverbinder pflegt; ohne das Recht geht Feststrom nicht.' ?></div>
                <?php endif; ?>
                <?php if (!$darf['grundriss']): ?>
                    <p class="small muted mb0">Annehmen braucht „Standorte, Etagen und Grundrisse pflegen“ und eine Freigabe mit „bearbeiten“ an diesem Standort.</p>
                <?php endif; ?>
            </div>
            <div class="card-footer btn-row">
                <button class="btn btn-primary" type="submit"<?= $darf['grundriss'] ? '' : ' disabled' ?>>Annehmen</button>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($darf['ablehnen']): ?>
        <form method="post" action="/vorschlaege/<?= $id ?>/ablehnen" class="card" data-confirm="Vorschlag ablehnen? Hier ändert sich nichts.">
            <?= Csrf::field() ?>
            <div class="card-head"><h3>Ablehnen</h3></div>
            <div class="card-body">
                <div class="field">
                    <label for="vorschlag-grund">Grund (optional, höchstens 500 Zeichen)</label>
                    <textarea id="vorschlag-grund" name="grund" rows="2" maxlength="500"></textarea>
                </div>
            </div>
            <div class="card-footer btn-row"><button class="btn" type="submit">Ablehnen</button></div>
        </form>
    <?php endif; ?>
<?php endif; ?>
