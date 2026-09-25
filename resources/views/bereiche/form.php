<?php
use App\Services\AnlagenService;
use App\Support\Csrf;
use App\Support\View;

$neu = (int) $bereich['id'] === 0;
$title = $neu ? 'Neuer Bereich' : $bereich['name'] . ' bearbeiten';
$subtitle = 'Welche Anlagen und Abgänge zusammengerechnet werden';
$zahl = static fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
$rang = ['anschluss' => 0, 'generator' => 1, 'wechselrichter' => 2, 'verteilung' => 3];
usort($objekte, static fn ($a, $b) => [$rang[$a['art']] ?? 9, $a['name']] <=> [$rang[$b['art']] ?? 9, $b['name']]);
?>
<form method="post" action="/bereiche/speichern" class="card">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $bereich['id'] ?>">
    <div class="card-head"><h2><?= View::e($title) ?></h2></div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <div class="field">
                    <label for="site_id">Standort</label>
                    <?php if ($neu): ?>
                        <select id="site_id" name="site_id" onchange="location.search = '?standort=' + this.value;">
                            <?php foreach ($standorte as $s): ?>
                                <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === (int) $bereich['site_id'] ? ' selected' : '' ?>><?= View::e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="site_id" value="<?= (int) $bereich['site_id'] ?>">
                        <div><?= View::e((string) ($bereich['site_name'] ?? '')) ?></div>
                    <?php endif; ?>
                </div>
                <div class="field"><label for="name">Name</label>
                    <input id="name" name="name" required value="<?= View::e($bereich['name']) ?>" placeholder="z. B. Foodtruck-Meile"></div>
                <div class="field-row">
                    <div class="field"><label for="grenze_kw">Grenze Leistung (kW)</label>
                        <input id="grenze_kw" name="grenze_kw" inputmode="decimal" value="<?= View::e($zahl($bereich['grenze_kw'])) ?>" placeholder="optional"></div>
                    <div class="field"><label for="grenze_a">Grenze Strom je Phase (A)</label>
                        <input id="grenze_a" name="grenze_a" inputmode="decimal" value="<?= View::e($zahl($bereich['grenze_a'])) ?>" placeholder="optional"></div>
                </div>
                <div class="help">Gegen die Grenze rechnet die Auslastung des Bereichs (etwa die zugesagte Anschlussleistung der Meile).</div>
                <div class="field"><label for="farbe">Farbe</label><input id="farbe" name="farbe" type="color" value="<?= View::e($bereich['farbe'] ?: '#0ea5e9') ?>"></div>
                <div class="field"><label for="notizen">Notizen</label><textarea id="notizen" name="notizen" rows="3"><?= View::e((string) $bereich['notizen']) ?></textarea></div>
            </div>
            <div>
                <h3>Mitglieder</h3>
                <p class="help">Ganze Anlagen zählen mit ihrer Einspeisung (bzw. der Summe ihrer gemessenen Abgänge). Einzelne Abgänge zählen mit
                    ihrem Messgerät – etwa wenn am Aggregat außer der Meile noch anderes hängt. Doppelt Gewähltes zählt einmal.</p>
                <?php if ($objekte === []): ?><p class="muted">Auf diesem Standort gibt es noch keine Anlagen.</p><?php endif; ?>
                <div class="bereich-wahl">
                    <?php foreach ($objekte as $o): ?>
                        <?php $art = AnlagenService::ARTEN[$o['art']]; ?>
                        <div class="bereich-wahl-anlage">
                            <label class="check"><input type="checkbox" name="mitglieder[]" value="o:<?= (int) $o['id'] ?>"<?= isset($gewaehlt['o:' . (int) $o['id']]) ? ' checked' : '' ?>>
                                <span><span class="art-zeichen klein" style="--art-farbe: <?= View::e($art['farbe']) ?>"><?= View::e($art['kuerzel']) ?></span>
                                <strong><?= View::e($o['name']) ?></strong> <span class="small muted"><?= View::e($o['art_anzeige']) ?><?= $o['kennung'] !== '' ? ' · ' . View::e($o['kennung']) : '' ?></span></span></label>
                            <?php foreach ($abgaenge[(int) $o['id']] ?? [] as $m): ?>
                                <label class="check eingerueckt"><input type="checkbox" name="mitglieder[]" value="m:<?= (int) $m['id'] ?>"<?= isset($gewaehlt['m:' . (int) $m['id']]) ? ' checked' : '' ?>>
                                    <span>nur Abgang <strong><?= View::e($m['anschluss_name']) ?></strong> · <?= View::e($m['typ_name']) ?><?= $m['anschluss_phase'] !== '' ? ' · ' . View::e($m['anschluss_phase']) : '' ?>
                                        <span class="small muted"><?= View::e($m['bezeichnung'] !== '' ? $m['bezeichnung'] : $m['geraet'] . ' ' . $m['kanal']) ?></span></span></label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer btn-row">
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="<?= $neu ? '/bereiche' : '/bereiche/' . (int) $bereich['id'] ?>">Abbrechen</a>
    </div>
</form>
