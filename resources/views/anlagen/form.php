<?php
use App\Services\AnlagenService;
use App\Services\SiteService;
use App\Support\Csrf;
use App\Support\View;

$neu = (int) $objekt['id'] === 0;
$art = (string) $objekt['art'];
$artInfo = AnlagenService::ARTEN[$art];
$vorlage = (int) $objekt['ist_vorlage'] === 1;
$fest = (int) ($objekt['fest'] ?? 0) === 1;
$title = $neu ? ($vorlage ? 'Neuer Standard-Verteiler' : ($fest && $art === 'anschluss' ? 'Neuer Feststromanschluss' : 'Neue ' . $artInfo['name'])) : $objekt['name'] . ' bearbeiten';
$subtitle = $vorlage ? 'Katalog: wird mit Anschlüssen und Sicherungen auf der Karte platziert' : $artInfo['name'];
$zahl = static fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
?>
<form method="post" action="/anlagen/speichern" class="card">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $objekt['id'] ?>">
    <input type="hidden" name="ist_vorlage" value="<?= $vorlage ? 1 : 0 ?>">
    <div class="card-head"><h2><?= View::e($title) ?></h2></div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <div class="field-row">
                    <div class="field">
                        <label for="art">Art</label>
                        <select id="art" name="art" <?= $neu ? '' : 'disabled' ?> onchange="if (this.form.dataset.neu) { location.search = '?art=' + this.value + '<?= $vorlage ? '&vorlage=1' : '' ?>'; }">
                            <?php foreach (AnlagenService::ARTEN as $k => $a): ?>
                                <option value="<?= $k ?>"<?= $art === $k ? ' selected' : '' ?>><?= View::e($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$neu): ?><input type="hidden" name="art" value="<?= View::e($art) ?>"><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <?php foreach (AnlagenService::STATUS as $k => $s): ?>
                                <option value="<?= $k ?>"<?= $objekt['status'] === $k ? ' selected' : '' ?>><?= View::e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <input type="hidden" name="fest" value="0">
                <div class="check fest-wahl">
                    <input type="checkbox" id="fest" name="fest" value="1"<?= $fest ? ' checked' : '' ?>>
                    <label for="fest"><strong>Fest installiert</strong> – <?= $vorlage
                        ? 'Anlagen aus dieser Vorlage sind Feststromanschlüsse der Location.'
                        : 'Feststromanschluss der Location (Hausanschluss, Energiesäule, Wandverteiler …). Wird beim Location-Abgleich übertragen; mobile Anlagen bleiben in dieser Installation.' ?></label>
                </div>
                <div class="field">
                    <label for="name">Name</label>
                    <input id="name" name="name" required value="<?= View::e($objekt['name']) ?>" placeholder="<?= $art === 'verteilung' ? 'z. B. Bühne links' : '' ?>">
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="kennung">Kennung / Inventarnummer</label>
                        <input id="kennung" name="kennung" value="<?= View::e($objekt['kennung']) ?>">
                    </div>
                    <div class="field">
                        <label for="seriennummer">Seriennummer</label>
                        <input id="seriennummer" name="seriennummer" value="<?= View::e($objekt['seriennummer']) ?>">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="hersteller">Hersteller</label>
                        <input id="hersteller" name="hersteller" value="<?= View::e($objekt['hersteller']) ?>">
                    </div>
                    <div class="field">
                        <label for="modell">Modell / Typ</label>
                        <input id="modell" name="modell" value="<?= View::e($objekt['modell']) ?>">
                    </div>
                </div>
                <?php if (!$vorlage): ?>
                    <div class="field">
                        <label for="floor_id">Standort / Etage</label>
                        <select id="floor_id" name="floor_id">
                            <option value="0">– ohne Standort –</option>
                            <?php foreach ($standorte as $s): ?>
                                <optgroup label="<?= View::e($s['name']) ?>">
                                    <?php foreach ($s['floors'] as $f): ?>
                                        <option value="<?= (int) $f['id'] ?>"<?= (int) $objekt['floor_id'] === (int) $f['id'] || ($objekt['floor_id'] === null && (int) $objekt['site_id'] === (int) $s['id'] && $f === $s['floors'][0]) ? ' selected' : '' ?>><?= View::e($f['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">Die Position im Plan setzt man auf der <a href="/karte">Karte</a>.</div>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <div class="field-row">
                    <div class="field">
                        <label for="nennleistung_kva">Nennleistung (kVA)</label>
                        <input id="nennleistung_kva" name="nennleistung_kva" inputmode="decimal" value="<?= View::e($zahl($objekt['nennleistung_kva'])) ?>">
                    </div>
                    <div class="field">
                        <label for="nennstrom_a">Nennstrom je Phase (A)</label>
                        <input id="nennstrom_a" name="nennstrom_a" inputmode="decimal" value="<?= View::e($zahl($objekt['nennstrom_a'])) ?>">
                        <div class="help">Leer: aus Leistung bzw. stärkstem Eingang.</div>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="spannung_v">Spannung (V)</label>
                        <input id="spannung_v" name="spannung_v" inputmode="numeric" value="<?= (int) $objekt['spannung_v'] ?>">
                    </div>
                    <div class="field">
                        <label for="phasen">Phasen</label>
                        <select id="phasen" name="phasen">
                            <option value="3"<?= (int) $objekt['phasen'] === 3 ? ' selected' : '' ?>>3 (Drehstrom)</option>
                            <option value="1"<?= (int) $objekt['phasen'] === 1 ? ' selected' : '' ?>>1 (Wechselstrom)</option>
                        </select>
                    </div>
                </div>
                <?php foreach (AnlagenService::FELDER[$art] as $schluessel => [$beschriftung, $typ, $zusatz]): ?>
                    <div class="field">
                        <label for="e_<?= $schluessel ?>"><?= View::e($beschriftung) ?><?= $typ === 'zahl' && $zusatz !== '' ? ' (' . View::e($zusatz) . ')' : '' ?></label>
                        <?php $wert = $objekt['eigenschaften'][$schluessel] ?? ''; ?>
                        <?php if ($typ === 'auswahl'): ?>
                            <select id="e_<?= $schluessel ?>" name="eigenschaften[<?= $schluessel ?>]">
                                <option value="">–</option>
                                <?php foreach ($zusatz as $option): ?>
                                    <option<?= (string) $wert === $option ? ' selected' : '' ?>><?= View::e($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input id="e_<?= $schluessel ?>" name="eigenschaften[<?= $schluessel ?>]" value="<?= View::e(is_float($wert) ? $zahl($wert) : (string) $wert) ?>"<?= $typ === 'zahl' ? ' inputmode="decimal"' : '' ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($neu && !$vorlage && $vorlagen !== []): ?>
                    <div class="field">
                        <label for="vorlage_id">Anschlüsse und Sicherungen übernehmen von</label>
                        <select id="vorlage_id" name="vorlage_id" onchange="const o = this.selectedOptions[0]; if (o.dataset.fest !== undefined) { this.form.querySelector('#fest').checked = o.dataset.fest === '1'; }">
                            <option value="0" data-fest="<?= $fest ? 1 : 0 ?>">Vorschlag für <?= View::e($artInfo['name']) ?></option>
                            <?php foreach ($vorlagen as $v): ?>
                                <option value="<?= (int) $v['id'] ?>" data-fest="<?= (int) $v['fest'] ?>"><?= View::e($v['name']) ?> (<?= View::e(AnlagenService::kurzform((int) $v['id'])) ?>)<?= (int) $v['fest'] === 1 ? ' – fest' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="check">
                    <input type="checkbox" id="steuerung_gesperrt" name="steuerung_gesperrt" value="1"<?= (int) $objekt['steuerung_gesperrt'] === 1 ? ' checked' : '' ?>>
                    <label for="steuerung_gesperrt">Steuerung sperren – an dieser Anlage darf niemand schalten (z. B. Einspeisung der Hauptbühne)</label>
                </div>
            </div>
        </div>
        <div class="field">
            <label for="notizen">Notizen</label>
            <textarea id="notizen" name="notizen" rows="3"><?= View::e((string) $objekt['notizen']) ?></textarea>
        </div>
    </div>
    <div class="card-footer btn-row">
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="<?= $neu ? ($vorlage ? '/anlagen/katalog' : '/anlagen') : '/anlagen/' . (int) $objekt['id'] ?>">Abbrechen</a>
    </div>
</form>
<?php if ($neu): ?><script>document.querySelector('form.card').dataset.neu = '1';</script><?php endif; ?>
