<?php
/**
 * Schaltknöpfe. Jeder Befehl fragt vorher nach und geht über
 * /steuerung/schalten – dort wird die Freigabe erneut geprüft.
 *
 * @var list<array<string,mixed>> $schaltbar aus LiveService::schaltbar()
 */
use App\Support\View;
?>
<div class="schalt-liste">
    <?php foreach ($schaltbar as $s): ?>
        <div class="schalt-zeile" data-messpunkt="<?= (int) $s['messpunkt'] ?>">
            <div class="schalt-titel">
                <strong><?= View::e($s['titel']) ?></strong>
                <span class="small muted"><?= View::e($s['quelle']) ?><?= $s['kanal'] !== '' ? ' · ' . View::e($s['kanal']) : '' ?></span>
            </div>
            <div class="btn-row">
                <?php foreach ($s['befehle'] as $b): ?>
                    <button type="button"
                            class="btn btn-sm <?= in_array($b['art'], ['aus'], true) ? 'btn-danger' : ($b['art'] === 'ein' ? 'btn-primary' : '') ?>"
                            data-schalten
                            data-quelle="<?= (int) $s['quelle_id'] ?>"
                            data-befehl="<?= View::e($b['schluessel']) ?>"
                            data-kanal="<?= View::e($s['kanal'] !== '' ? $s['kanal'] : $b['kanal']) ?>"
                            data-objekt="<?= (int) $s['objekt_id'] ?>"
                            data-frage="<?= View::e($s['titel'] . ': „' . $b['name'] . '“ wirklich ausführen?') ?>">
                        <?= View::e($b['name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
