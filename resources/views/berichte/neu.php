<?php
use App\Services\AnlagenService;
use App\Services\AufbauService;
use App\Support\Csrf;
use App\Support\View;

$title = 'Neuer Übergabebericht';
$subtitle = $site !== null ? $site['name'] : 'Standort wählen';
?>
<?php if ($site === null): ?>
    <div class="grid grid-2">
        <form method="get" action="/berichte/neu" class="card">
            <div class="card-head"><h2>Für einen Standort</h2></div>
            <div class="card-body">
                <?php if ($standorte === []): ?>
                    <p class="muted small mb0">Auf keinem Standort hast du eine Freigabe mit „bearbeiten“ – vorbelegte Berichte legt an, wer dort ändern darf.</p>
                <?php else: ?>
                    <p class="small muted">Danach die Anlagen wählen: Generatoren, Anschlüsse und Verteiler mit ihren Abgängen, Schutzorganen und Kabeln
                        stehen dann schon im Bericht – Messwerte nie.</p>
                    <div class="field"><label for="n-site">Standort</label>
                        <select id="n-site" name="site" required>
                            <?php foreach ($standorte as $s): ?><option value="<?= (int) $s['id'] ?>"><?= View::e($s['name']) ?></option><?php endforeach; ?>
                        </select></div>
                    <button class="btn btn-primary" type="submit">Weiter</button>
                <?php endif; ?>
            </div>
        </form>
        <form method="post" action="/berichte/anlegen" class="card">
            <?= Csrf::field() ?>
            <input type="hidden" name="leer" value="1">
            <div class="card-head"><h2>Leerer Bericht</h2></div>
            <div class="card-body">
                <p class="small muted">Ohne Standort und ohne Vorbelegung – alles als Freitext, etwa für eine Anlage, die nicht in der App geplant ist.
                    Sehen kann ihn nur, wer ihn anlegt (und wer alle Standorte sieht).</p>
                <button class="btn" type="submit">Leeren Bericht anlegen</button>
            </div>
        </form>
    </div>
<?php else: ?>
    <form method="post" action="/berichte/anlegen" class="card">
        <?= Csrf::field() ?>
        <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
        <div class="card-head"><h2>Welche Anlagen prüft der Bericht?</h2><div class="spacer"></div><a class="btn btn-sm btn-ghost" href="/berichte/neu">anderer Standort</a></div>
        <div class="card-body">
            <?php if ($aufbauten !== []): ?>
                <div class="field"><label for="n-aufbau">Aufbau</label>
                    <select id="n-aufbau" name="aufbau_id">
                        <option value="">ohne Aufbau</option>
                        <?php foreach ($aufbauten as $a): ?>
                            <option value="<?= (int) $a['id'] ?>"<?= $aufbau !== null && (int) $aufbau['id'] === (int) $a['id'] ? ' selected' : '' ?>><?= View::e($a['name'] . ' · ' . (AufbauService::STATUS[$a['status']] ?? $a['status'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Mit Aufbau: Veranstaltung und Zeitraum kommen von dort, Grund der Prüfung „Aufbau (Erstprüfung)“.</div></div>
            <?php endif; ?>
            <?php if ($kacheln === []): ?>
                <p class="muted small">Auf diesem Standort ist noch keine Anlage geplant. Ein Bericht geht trotzdem – die Stromkreise werden dann von Hand eingetragen.</p>
            <?php else: ?>
                <p class="small muted">Je Abgang einer gewählten Anlage entsteht ein Stromkreis mit Steckverbinder, Überstromschutz und RCD aus der Planung.
                    <?= $anlage !== null ? 'Vorausgewählt: ' . View::e($anlage['name']) . ' und was daran hängt.' : ($aufbau !== null ? 'Vorausgewählt sind die mobilen Anlagen des Aufbaus.' : '') ?></p>
                <label class="check"><input type="checkbox" data-toggle-all="#n-anlagen"> <span>alle</span></label>
                <div class="port-grid breit bericht-anlagen" id="n-anlagen">
                    <?php foreach ($kacheln as $k): ?>
                        <?php $o = $k['objekt']; $art = AnlagenService::ARTEN[$o['art']] ?? ['kuerzel' => '?', 'farbe' => '#64748b']; ?>
                        <label class="port" style="--tiefe: <?= (int) $k['tiefe'] ?>">
                            <input type="checkbox" name="objekte[]" value="<?= (int) $o['id'] ?>"<?= $k['gewaehlt'] ? ' checked' : '' ?>>
                            <span class="pname"><span class="art-zeichen klein" style="--art-farbe: <?= View::e($art['farbe']) ?>"><?= View::e($art['kuerzel']) ?></span><?= View::e($o['name']) ?></span>
                            <span class="pmeta"><?= View::e($o['art_anzeige'] . ((int) $o['fest'] === 1 ? ' · fest' : '') . ($k['tiefe'] > 0 ? ' · Ebene ' . ($k['tiefe'] + 1) : '')) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-footer btn-row">
            <button class="btn btn-primary" type="submit">Bericht anlegen</button>
            <a class="btn btn-ghost" href="/berichte">Abbrechen</a>
        </div>
    </form>
<?php endif; ?>
