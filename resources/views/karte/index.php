<?php
use App\Services\AnlagenService;
use App\Services\LeitungService;
use App\Support\View;

$title = 'Karte';
$subtitle = $etage !== null ? $etage['site_name'] . ' · ' . $etage['name'] : 'Grundriss mit Anlagen und Kabelwegen';
$darfSetzen = $darfAnlegen;
?>
<?php if ($etage === null): ?>
    <div class="card"><div class="card-body">
        <p>Es gibt noch keinen Standort mit Etage.</p>
        <p class="muted small">Standorte legt man unter <a href="/standorte">Standorte & Etagen</a> an oder übernimmt sie aus dem
            gemeinsamen Repository von MasterAdmin (<a href="/location">Location-Abgleich</a>).</p>
    </div></div>
    <?php return; ?>
<?php endif; ?>

<div class="karte-kopf">
    <form method="get" action="/karte" class="inline-form">
        <label class="sr-only" for="etage-wahl">Etage</label>
        <select id="etage-wahl" name="etage" onchange="this.form.submit()">
            <?php foreach ($standorte as $s): ?>
                <optgroup label="<?= View::e($s['name']) ?>">
                    <?php foreach ($s['floors'] as $f): ?>
                        <option value="<?= (int) $f['id'] ?>"<?= (int) $f['id'] === (int) $etage['id'] ? ' selected' : '' ?>><?= View::e($f['name']) ?><?= $f['plan_file'] === '' ? ' (ohne Plan)' : '' ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
    </form>
    <div class="karte-werkzeuge" role="toolbar" aria-label="Werkzeuge">
        <button type="button" class="btn btn-sm aktiv" data-modus="ansehen" title="Anlagen und Kabel antippen">Ansehen</button>
        <?php if ($darfSetzen || $darfLeitung): ?>
            <button type="button" class="btn btn-sm" data-modus="verschieben" title="Anlagen und Kabelverläufe verschieben">Verschieben</button>
        <?php endif; ?>
        <?php if ($darfSetzen): ?>
            <button type="button" class="btn btn-sm" data-modus="setzen" title="Feststromanschluss, Standard-Verteiler oder neue Anlage in den Plan setzen">+ Anlage</button>
        <?php endif; ?>
        <?php if ($darfLeitung): ?>
            <button type="button" class="btn btn-sm" data-modus="kabel" title="Kabel von Anlage zu Anlage ziehen">Kabel</button>
            <button type="button" class="btn btn-sm" data-modus="kabelweg" title="Kabeltrasse ohne feste Enden">Kabelweg</button>
            <button type="button" class="btn btn-sm" data-modus="bruecke" title="Kabelbrücke über einen Weg legen">Kabelbrücke</button>
        <?php endif; ?>
        <?php if ($darfMassstab): ?>
            <button type="button" class="btn btn-sm" data-modus="massstab" title="Zwei Punkte mit bekanntem Abstand setzen">Maßstab</button>
            <button type="button" class="btn btn-sm" data-modus="geo" title="Plan mit Breite/Länge verbinden: zwei Passpunkte">Georeferenz</button>
        <?php endif; ?>
        <?php if ($aufbau !== null && ($darfSetzen || $darfLeitung)): ?>
            <button type="button" class="btn btn-sm" data-modus="aufbau" title="Aufbau „<?= View::e($aufbau['name']) ?>“: Anlagen und Kabel bestätigen">Aufbau ✓</button>
        <?php endif; ?>
        <?php if ($geo !== null): ?>
            <button type="button" class="btn btn-sm" data-aktion="position" aria-pressed="false" title="Eigene GPS-Position im Plan zeigen">Hier bin ich</button>
        <?php endif; ?>
        <a class="btn btn-sm" href="/karte/<?= (int) $etage['id'] ?>/material" target="_blank" rel="noopener" title="Material für den Aufbau dieser Etage: Anlagen, Kabelstücke, Kabelbrücken">Material</a>
        <button type="button" class="btn btn-sm" data-aktion="vollbild" title="Karte über die ganze Fläche">Vollbild</button>
    </div>
</div>

<div class="karte-raster">
    <div class="card karte-karte">
        <div class="plan-wrap">
            <?php if ($plan === null): ?>
                <div class="notice warn karte-ohne-plan">Für diese Etage ist kein Grundriss hinterlegt. Anlagen lassen sich trotzdem setzen – auf einer leeren Fläche.
                    <?php if (View::can('plans.manage')): ?><a href="/standorte">Grundriss hochladen</a><?php endif; ?></div>
            <?php endif; ?>
            <div id="karte" class="plan-karte strom-karte"
                 data-etage="<?= (int) $etage['id'] ?>"
                 data-start-modus="<?= View::e($modus) ?>"
                 data-fokus="<?= (int) $fokus ?>"
                 data-plan-art="<?= View::e((string) ($plan['art'] ?? 'leer')) ?>"
                 data-plan-url="<?= View::e((string) ($plan['url'] ?? '')) ?>"
                 data-plan-breite="<?= (int) ($plan['breite'] ?? 1600) ?>"
                 data-plan-hoehe="<?= (int) ($plan['hoehe'] ?? 1000) ?>"
                 data-plan-seite="<?= (int) ($plan['seite'] ?? 1) ?>"
                 data-plan-max-zoom="<?= (int) ($plan['max_zoom'] ?? 3) ?>"
                 data-plan-kachel-url="<?= View::e((string) ($plan['kachel_url'] ?? '')) ?>"
                 data-plan-kachel="<?= (int) ($plan['kachel'] ?? 256) ?>"
                 data-plan-zusatz="<?= (int) ($plan['zusatz'] ?? 2) ?>"></div>
            <div class="karte-status" id="karte-status" aria-live="polite"></div>
        </div>
        <div class="karte-fuss">
            <div class="karte-ebenen">
                <label class="check"><input type="checkbox" data-ebene="fest" checked> Feststromanschlüsse &amp; feste Kabel</label>
                <label class="check"><input type="checkbox" data-ebene="mobil" checked> Mobile Anlagen</label>
                <label class="check"><input type="checkbox" data-ebene="kabel" checked> Mobile Kabel</label>
                <label class="check"><input type="checkbox" data-ebene="kabelweg" checked> Kabelwege</label>
                <label class="check"><input type="checkbox" data-ebene="kabelbruecke" checked> Kabelbrücken</label>
                <label class="check"><input type="checkbox" data-ebene="beschriftung" checked> Namen</label>
                <label class="check"><input type="checkbox" data-ebene="werte" checked> Livewerte</label>
                <label class="check"><input type="checkbox" data-ebene="fremd"> Racks &amp; Dosen (MasterAdmin)</label>
            </div>
            <div class="karte-legende small">
                <span class="legende-punkt stufe-ok"></span> bis <?= (int) \App\Services\LiveService::grenzeWarnung() ?> %
                <span class="legende-punkt stufe-warn"></span> ab <?= (int) \App\Services\LiveService::grenzeWarnung() ?> %
                <span class="legende-punkt stufe-bad"></span> ab <?= (int) \App\Services\LiveService::grenzeAlarm() ?> %
                <span class="legende-punkt stufe-stumm"></span> stumm
                <span class="legende-punkt stufe-keine"></span> ohne Messung
                <span class="legende-form fest" aria-hidden="true"></span> fest
                <span class="legende-form mobil" aria-hidden="true"></span> mobil
                <span class="legende-linie fest" aria-hidden="true"></span> fest verlegt
                <?php if ($massstab !== null && ($massstab['quelle'] ?? '') !== 'georeferenz'): ?><span class="muted">· Maßstab gesetzt (<?= View::e(number_format((float) $massstab['meter'], 0, ',', '.')) ?> m)</span><?php endif; ?>
                <?php if ($geo !== null): ?><span class="muted">· georeferenziert<?= ($massstab['quelle'] ?? '') === 'georeferenz' ? ' (Längen daraus)' : '' ?> ·
                    <a href="/karte/<?= (int) $etage['id'] ?>/geojson">GeoJSON</a></span><?php endif; ?>
            </div>
        </div>
    </div>

    <aside class="card karte-seite" id="karte-seite" aria-live="polite">
        <div class="card-body" id="seite-inhalt">
            <h2>Anlagen und Kabel</h2>
            <p class="small muted">Eine Anlage oder ein Kabel antippen, um Details, Livewerte, Anschlüsse und Messgeräte zu sehen.
                Messgeräte lassen sich von hier aus in die Auswertung übernehmen.</p>
            <?php if ($ungesetzt !== [] && $darfSetzen): ?>
                <h3 class="mt">Noch nicht im Plan</h3>
                <ul class="liste-schlicht">
                    <?php foreach ($ungesetzt as $o): ?>
                        <li><button type="button" class="btn btn-sm btn-ghost" data-setzen-vorhanden="<?= (int) $o['id'] ?>"><?= View::e(AnlagenService::ARTEN[$o['art']]['kuerzel'] . ' · ' . $o['name'] . ((int) $o['fest'] === 1 ? ' (fest)' : '')) ?></button></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php /* Vorlagen für die Werkzeuge – als JSON, damit karte.js keine zweite Quelle braucht. */ ?>
<script type="application/json" id="karte-daten"><?= json_encode([
    'arten'      => array_map(static fn ($a) => ['name' => $a['name'], 'kuerzel' => $a['kuerzel'], 'farbe' => $a['farbe']], AnlagenService::ARTEN),
    'vorlagen'   => array_map(static fn ($v) => ['id' => (int) $v['id'], 'name' => $v['name'], 'art' => $v['art'], 'fest' => (int) $v['fest'] === 1,
        'kurz' => AnlagenService::kurzform((int) $v['id'])], $vorlagen),
    'kabeltypen' => LeitungService::KABELTYPEN,
    'querschnitte' => LeitungService::QUERSCHNITTE,
    'bruecken'   => LeitungService::BRUECKEN,
    'darf'       => ['setzen' => $darfSetzen, 'leitung' => $darfLeitung, 'massstab' => $darfMassstab, 'statistik' => View::can('statistik.view'),
        'inventar' => View::can('datenquellen.view')],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
