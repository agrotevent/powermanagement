<?php
/**
 * Vollbild-Dialog zum Unterschreiben (unterschrift.js): Fläche 3:1,
 * „Letzten Strich zurück“, „Löschen“ und darunter die Angaben zur Person.
 * Gespeichert wird nur der Linienzug – keine Zeit, kein Druck.
 *
 * @var string $titel
 * @var string $hinweis
 * @var string $knopf
 * @var list<array{name:string,label:string,wert:string,pflicht:bool,max:int,hilfe?:string}> $felder
 */
use App\Support\View;
?>
<dialog class="unterschrift-dialog" data-unterschrift-dialog aria-labelledby="unterschrift-titel">
    <div class="unterschrift-dialog-kopf">
        <h2 id="unterschrift-titel"><?= View::e($titel) ?></h2>
        <button class="btn btn-sm btn-ghost" type="button" data-dialog-zu>Abbrechen</button>
    </div>
    <div class="unterschrift-dialog-inhalt">
        <p class="quer-hinweis">Tipp: Gerät quer halten – dann ist mehr Platz zum Unterschreiben.</p>
        <?php if ($hinweis !== ''): ?><p class="small muted unterschrift-hinweis"><?= View::e($hinweis) ?></p><?php endif; ?>
        <div class="unterschrift-flaeche" data-unterschrift-flaeche>
            <canvas role="img" aria-label="Unterschriftsfeld – mit Finger oder Stift unterschreiben"></canvas>
            <span class="unterschrift-linie" aria-hidden="true"></span>
            <span class="unterschrift-leer" data-unterschrift-leer aria-hidden="true">hier unterschreiben</span>
        </div>
        <div class="btn-row unterschrift-knoepfe">
            <button class="btn btn-sm" type="button" data-unterschrift-zurueck disabled>Letzten Strich zurück</button>
            <button class="btn btn-sm" type="button" data-unterschrift-loeschen disabled>Löschen</button>
        </div>
        <div class="feld-raster unterschrift-felder">
            <?php foreach ($felder as $f): ?>
                <div class="field">
                    <label for="unterschrift-<?= View::e($f['name']) ?>"><?= View::e($f['label']) ?><?= $f['pflicht'] ? ' *' : '' ?></label>
                    <input id="unterschrift-<?= View::e($f['name']) ?>" type="text" data-unterschrift-feld="<?= View::e($f['name']) ?>"
                           value="<?= View::e($f['wert']) ?>" maxlength="<?= (int) $f['max'] ?>" autocomplete="off" autocapitalize="words"
                           enterkeyhint="done"<?= $f['pflicht'] ? ' required minlength="2"' : '' ?>>
                    <?php if (($f['hilfe'] ?? '') !== ''): ?><div class="help"><?= View::e($f['hilfe']) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="unterschrift-meldung" data-unterschrift-meldung role="status" aria-live="polite"></p>
    </div>
    <div class="unterschrift-dialog-fuss btn-row">
        <button class="btn btn-primary" type="button" data-unterschrift-senden disabled><?= View::e($knopf) ?></button>
        <span class="small muted" data-unterschrift-stand>Bitte im Feld unterschreiben.</span>
    </div>
</dialog>
