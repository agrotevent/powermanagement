<?php
/**
 * Die Angaben eines Prüfnachweises – im Formular „Prüfnachweis hinterlegen“
 * und beim Bearbeiten. Alles kommt aus dem Prüfprotokoll; das PDF selbst wird
 * nicht ausgewertet. Location nur aus den Freigaben des Kunden, Vorgang nur
 * seine, Inventar nur mit „Datenquellen ansehen“ (sonst fehlt die Auswahl).
 *
 * @var string $p Präfix der Feld-IDs (eindeutig je Formular)
 * @var array<string,mixed> $w Werte (Eingabe nach einem Fehler oder der Nachweis)
 * @var list<array{id:int,name:string}> $standorte Pruefnachweise::standorte()
 * @var list<array<string,mixed>> $vorgaenge Pruefnachweise::vorgaenge()
 * @var list<array<string,mixed>> $inventar Pruefnachweise::inventar()
 */
use App\Kunden\Pruefnachweise;
use App\Support\View;

$v = static fn (string $f): string => (string) ($w[$f] ?? '');
$datum = static fn (string $f): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v($f)) === 1 ? $v($f) : '';
$site = (int) $v('site_id');
$orte = $standorte;
// Eine eingetragene Location, die nicht mehr unter den Freigaben ist, bleibt wählbar.
if ($site > 0 && !in_array($site, array_column($orte, 'id'), true)) {
    $orte[] = ['id' => $site, 'name' => $v('standort_name') !== '' ? $v('standort_name') : 'Location #' . $site];
}
$art = static fn (string $a): string => match ($a) { 'order' => 'Auftrag', 'deliverynote' => 'Lieferschein', default => 'Vorgang' };
?>
<div class="field"><label for="<?= View::e($p) ?>-bez">Gerät / Bezeichnung</label>
    <input id="<?= View::e($p) ?>-bez" name="bezeichnung" required minlength="2" maxlength="255" value="<?= View::e($v('bezeichnung')) ?>"
        placeholder="z. B. Verteiler PD63-07 oder Sammelnachweis 32 Geräte"></div>
<div class="field-row">
    <div class="field"><label for="<?= View::e($p) ?>-inv">Inventarnummer</label>
        <input id="<?= View::e($p) ?>-inv" name="inventarnummer" maxlength="80" value="<?= View::e($v('inventarnummer')) ?>"></div>
    <div class="field"><label for="<?= View::e($p) ?>-ser">Seriennummer</label>
        <input id="<?= View::e($p) ?>-ser" name="seriennummer" maxlength="80" value="<?= View::e($v('seriennummer')) ?>"></div>
</div>
<div class="field-row">
    <div class="field"><label for="<?= View::e($p) ?>-am">Geprüft am (Pflicht)</label>
        <input id="<?= View::e($p) ?>-am" type="date" name="geprueft_am" required max="<?= View::e(Pruefnachweise::heute()) ?>" value="<?= View::e($datum('geprueft_am')) ?>"></div>
    <div class="field"><label for="<?= View::e($p) ?>-next">Nächste Prüfung</label>
        <input id="<?= View::e($p) ?>-next" type="date" name="naechste_pruefung" value="<?= View::e($datum('naechste_pruefung')) ?>"></div>
</div>
<div class="field-row">
    <div class="field"><label for="<?= View::e($p) ?>-norm">Norm</label>
        <input id="<?= View::e($p) ?>-norm" name="norm" maxlength="120" list="<?= View::e($p) ?>-normen" value="<?= View::e($v('norm')) ?>">
        <datalist id="<?= View::e($p) ?>-normen"><?php foreach (Pruefnachweise::NORMEN as $norm): ?><option value="<?= View::e($norm) ?>"></option><?php endforeach; ?></datalist></div>
    <div class="field"><label for="<?= View::e($p) ?>-erg">Ergebnis</label>
        <select id="<?= View::e($p) ?>-erg" name="ergebnis">
            <?php foreach (Pruefnachweise::ERGEBNISSE as $wert => $text): ?>
                <option value="<?= View::e($wert) ?>"<?= $v('ergebnis') === $wert ? ' selected' : '' ?>><?= View::e($wert === '' ? 'nicht angegeben (steht nicht im Protokoll)' : $text) ?></option>
            <?php endforeach; ?>
        </select></div>
</div>
<div class="field"><label for="<?= View::e($p) ?>-site">Location</label>
    <select id="<?= View::e($p) ?>-site" name="site_id">
        <option value="">– keine –</option>
        <?php foreach ($orte as $o): ?>
            <option value="<?= (int) $o['id'] ?>"<?= $site === (int) $o['id'] ? ' selected' : '' ?>><?= View::e((string) $o['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($standorte === []): ?><p class="help">Zur Auswahl stehen die Locations aus den Freigaben des Kunden – er hat noch keine.</p><?php endif; ?></div>
<?php if ($vorgaenge !== []): ?>
    <div class="field"><label for="<?= View::e($p) ?>-vorgang">Eventworx-Vorgang</label>
        <select id="<?= View::e($p) ?>-vorgang" name="vorgang_id">
            <option value="">– keiner –</option>
            <?php foreach ($vorgaenge as $vg): ?>
                <option value="<?= (int) $vg['id'] ?>"<?= (int) $v('vorgang_id') === (int) $vg['id'] ? ' selected' : '' ?>><?= View::e(trim($art((string) $vg['ewx_art']) . ' '
                    . (string) $vg['ewx_nummer'] . ((string) $vg['titel'] !== '' ? ' · ' . (string) $vg['titel'] : ''))) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="help">Ohne eigene Mietzeit gilt die des Vorgangs.</p></div>
<?php endif; ?>
<div class="field-row">
    <div class="field"><label for="<?= View::e($p) ?>-von">Mietzeit von</label>
        <input id="<?= View::e($p) ?>-von" type="date" name="einsatz_von" value="<?= View::e($datum('einsatz_von')) ?>"></div>
    <div class="field"><label for="<?= View::e($p) ?>-bis">Mietzeit bis</label>
        <input id="<?= View::e($p) ?>-bis" type="date" name="einsatz_bis" value="<?= View::e($datum('einsatz_bis')) ?>"></div>
</div>
<?php if ($inventar !== []): ?>
    <div class="field"><label for="<?= View::e($p) ?>-invstk">Inventarstück (optional)</label>
        <select id="<?= View::e($p) ?>-invstk" name="inventar_id">
            <option value="">– keins –</option>
            <?php foreach ($inventar as $i): ?>
                <option value="<?= (int) $i['id'] ?>"<?= (int) $v('inventar_id') === (int) $i['id'] ? ' selected' : '' ?>><?= View::e((string) $i['kennung']
                    . ((string) $i['name'] !== '' ? ' · ' . (string) $i['name'] : '')) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="help">Leere Inventar- und Seriennummer übernimmt der Nachweis aus dem Inventar.</p></div>
<?php endif; ?>
<div class="field"><label for="<?= View::e($p) ?>-hinweis">Hinweis für den Kunden</label>
    <textarea id="<?= View::e($p) ?>-hinweis" name="hinweis" maxlength="500" rows="2" placeholder="z. B. Gilt für alle Geräte der Lieferung vom 12.07."><?= View::e($v('hinweis')) ?></textarea></div>
