<?php
use App\Berichte\BerichtService;
use App\Services\Eventworx;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$b = $bericht;
$id = (int) $b['id'];
$title = $b['nummer'] ?? ('Entwurf #' . $id);
$subtitle = 'Übergabebericht · ' . ($b['site_name'] ?? ($b['standort_name'] !== '' ? $b['standort_name'] : 'ohne Standort'));
$klasse = ['entwurf' => 'warn', 'geprueft' => 'info', 'abgeschlossen' => 'ok', 'ersetzt' => '', 'verworfen' => ''];
?>
<div class="toolbar-zeile">
    <span class="badge <?= $klasse[$b['status']] ?? '' ?>"><?= View::e(BerichtService::STATUS[$b['status']] ?? $b['status']) ?></span>
    <?php if ((int) $b['fassung'] > 1): ?><span class="badge">Fassung <?= (int) $b['fassung'] ?></span><?php endif; ?>
    <span class="small muted">
        <?= $b['abgeschlossen_am'] ? 'abgeschlossen ' . View::e(Zeit::anzeige((string) $b['abgeschlossen_am'])) : '' ?>
        <?= !$b['abgeschlossen_am'] && $b['eingefroren_am'] ? 'unterschrieben ' . View::e(Zeit::anzeige((string) $b['eingefroren_am'])) : '' ?>
        <?= $b['status'] === 'entwurf' ? 'Entwurf – ausfüllen darf, wer am Standort „bearbeiten“ hat.' : '' ?>
    </span>
    <?php if ((string) $b['ewx_nummer'] !== ''): ?>
        <span class="small">Eventworx <?= View::e(trim((Eventworx::ART_NAMEN[(string) $b['ewx_art']] ?? '') . ' ' . $b['ewx_nummer'])) ?></span>
    <?php endif; ?>
    <div class="spacer"></div>
    <a class="btn btn-sm btn-ghost" href="/berichte">Alle Berichte</a>
</div>

<?php if ($b['status'] === 'ersetzt' && $b['ersetzt_durch_id'] !== null): ?>
    <p class="notice warn">Dieser Bericht ist durch eine Korrektur ersetzt – <a href="/berichte/<?= (int) $b['ersetzt_durch_id'] ?>">neue Fassung ansehen</a>.</p>
<?php elseif ($b['status'] === 'verworfen'): ?>
    <p class="notice">Verworfen – die Nummer bleibt als erklärte Lücke in der Nummernfolge.</p>
<?php endif; ?>

<?= View::partial('berichte/_ansicht', ['inhalt' => $inhalt, 'bewertung' => $bewertung]) ?>

<?php if ($ereignisse !== []): ?>
    <details class="card">
        <summary class="card-head"><h2>Verlauf</h2><span class="hint"><?= count($ereignisse) ?> Einträge</span></summary>
        <div class="card-body tight">
            <ul class="liste-schlicht small">
                <?php foreach ($ereignisse as $e): ?>
                    <li><strong><?= View::e(str_replace('_', ' ', (string) $e['ereignis'])) ?></strong> · <?= View::e(Zeit::anzeige((string) $e['am'])) ?> · <?= View::e((string) $e['username']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </details>
<?php endif; ?>

<?php if ($darfLoeschen): ?>
    <form method="post" action="/berichte/<?= $id ?>/loeschen" data-confirm="Entwurf #<?= $id ?> löschen?">
        <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Entwurf löschen</button></form>
<?php elseif ($darfVerwerfen): ?>
    <form method="post" action="/berichte/<?= $id ?>/verwerfen" class="inline-form" data-confirm="<?= View::e((string) $b['nummer']) ?> verwerfen?">
        <?= Csrf::field() ?><input name="grund" required minlength="3" maxlength="500" placeholder="Grund" aria-label="Grund">
        <button class="btn btn-sm btn-ghost" type="submit">Verwerfen</button></form>
<?php endif; ?>
