<?php
use App\Berichte\Felder;
use App\Berichte\Grenzwerte;
use App\Berichte\Pruefgeraete;
use App\Berichte\BerichtSchema;
use App\Support\View;

$m = $inhalt['messung'];
$erdBw = $bewertung['erdung'] ?? ['auto' => '', 'meldungen' => [], 'grenze' => null, 'bezug' => ''];
$datum = (string) $inhalt['kopf']['pruefdatum'];
$optionen = [];
$zusatz = [];
foreach ($geraete as $g) {
    $optionen[(string) $g['id']] = trim($g['fabrikat'] . ' ' . $g['typ']) . ($g['seriennummer'] !== '' ? ' · Nr. ' . $g['seriennummer'] : '');
    if (Pruefgeraete::abgelaufen($g, $datum)) {
        $zusatz[(string) $g['id']] = 'Kalibrierung abgelaufen';
    } elseif ((int) $g['aktiv'] !== 1) {
        $zusatz[(string) $g['id']] = 'stillgelegt';
    }
}
$zeilen = array_map(static fn (array $k): array => ['z' => (string) $k['z'], 'nr' => (string) $k['nr'], 'ort' => (string) $k['ort']], $inhalt['kreise']);
[$ek, $et] = Felder::bewertung((string) $erdBw['auto']);
?>
<section class="card bericht-abschnitt" id="abschnitt-messung" data-abschnitt="messung">
    <div class="card-head"><h2>Messung</h2><span class="hint">Werte vom Prüfgerät – die App belegt keinen Messwert vor</span></div>
    <div class="card-body">
        <h3>Prüfgeräte</h3>
        <div data-feld-ziel="messung.geraete" tabindex="-1">
            <?php if ($optionen === []): ?>
                <p class="small muted">Noch kein Prüfgerät hinterlegt. <?= View::can('berichte.manage') ? '<a href="/berichte/pruefgeraete?neu=1">Prüfgerät anlegen</a>' : '' ?></p>
            <?php else: ?>
                <?= Felder::chips('messung.geraete', $m['geraete'], $optionen, $zusatz) ?>
                <p class="help"><a href="/berichte/pruefgeraete">Prüfgeräte verwalten</a> · Messgeräte nach DIN EN 61557 (VDE 0413)</p>
            <?php endif; ?>
        </div>

        <h3 class="mt">Erdung und Isolation</h3>
        <div class="feld-raster">
            <?= Felder::auswahl('messung.riso_u', (string) $m['riso_u'], 'Messspannung R_iso', ['250' => '250 V', '500' => '500 V', '1000' => '1000 V']) ?>
            <?= Felder::messwert('messung.erdung', (array) $m['erdung'], 'Erdungswiderstand', 'Ω') ?>
            <?= Felder::text('messung.erdung.methode', (string) $m['erdung']['methode'], 'Messverfahren', ['max' => 120, 'platzhalter' => 'z. B. Zangenmessung']) ?>
            <?= Felder::auswahl('messung.erdung.basis', (string) $m['erdung']['basis'], 'Bewerten nach', [
                '' => 'automatisch (Netzform, Einspeisung)', 'generator' => 'Generator im TN-System', 'tt' => 'TT-System (R_A · IΔn ≤ U_L)',
                'eigen' => 'eigener Grenzwert', 'keine' => 'ohne Grenzwert']) ?>
            <?= Felder::text('messung.erdung.grenze', (string) $m['erdung']['grenze'], 'Eigener Grenzwert', ['zahl' => true, 'einheit' => 'Ω']) ?>
        </div>
        <div class="bewertung-zeile" data-bewertung-erdung>
            <span class="bewertung <?= $ek ?>" data-bewertung><?= View::e($et) ?></span>
            <span class="small muted" data-erdung-bezug><?= View::e(($erdBw['grenze'] !== null ? 'Grenzwert ≤ ' . Grenzwerte::komma((float) $erdBw['grenze']) . ' Ω · ' : '') . $erdBw['bezug']) ?></span>
            <ul class="meldungen" data-meldungen>
                <?php foreach ($erdBw['meldungen'] as $me): ?><li class="<?= View::e($me['stufe']) ?>"><?= View::e($me['text']) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <details class="mt"<?= (string) ($m['iso_bus']['w'] ?? '') !== '' ? ' open' : '' ?>>
            <summary>Isolationswiderstand der Busleitung</summary>
            <div class="feld-raster"><?= Felder::messwert('messung.iso_bus', (array) $m['iso_bus'], 'R_iso Busleitung', 'MΩ') ?></div>
        </details>

        <div class="kreise-kopf mt">
            <h3 class="mb0">Stromkreise <span class="muted" data-kreise-zahl>(<?= count($inhalt['kreise']) ?>)</span></h3>
            <div class="spacer"></div>
            <button class="btn btn-sm btn-primary" type="button" data-kreis-neu>+ Stromkreis</button>
        </div>
        <p class="small muted">Zs bzw. Ik, R_iso, R_PE und die RCD-Werte je Kreis. „&gt;“ und „&lt;“ am Feld heißen über bzw. unter dem Messbereich.
            Die Bewertung kommt mit jedem Speichern vom Server.</p>
        <div class="kreise" data-kreise data-feld-ziel="kreise" tabindex="-1">
            <?php foreach ($inhalt['kreise'] as $k): ?>
                <?= View::partial('berichte/_kreis', ['k' => $k, 'bw' => $bewertung['kreise'][(string) $k['z']] ?? null, 'zeilen' => $zeilen]) ?>
            <?php endforeach; ?>
        </div>
        <p class="muted small" data-kreise-leer<?= $inhalt['kreise'] !== [] ? ' hidden' : '' ?>>Noch kein Stromkreis. Bei einer reinen Sichtprüfung bitte unter „Bemerkungen“ begründen.</p>
        <template id="kreis-vorlage"><?= View::partial('berichte/_kreis', ['k' => ['z' => Felder::VORLAGE] + BerichtSchema::kreisLeer(), 'bw' => null, 'zeilen' => [], 'vorlage' => true]) ?></template>

        <?php if ($anlagen !== []): ?>
            <details class="mt kreise-ergaenzen">
                <summary>Zeilen für Abgänge ergänzen</summary>
                <p class="small muted">Legt für die Abgänge der gewählten Anlagen Zeilen an, die noch fehlen – vorhandene Zeilen und Eingaben bleiben, wie sie sind.</p>
                <div class="chips">
                    <?php foreach ($anlagen as $o): ?>
                        <label class="chip-wahl"><input type="checkbox" data-ergaenzen-anlage value="<?= (int) $o['id'] ?>"<?= $o['im_bericht'] ? ' checked' : '' ?>><span><?= View::e($o['name']) ?> <small><?= View::e($o['art_anzeige']) ?></small></span></label>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-sm" type="button" data-kreise-ergaenzen>Zeilen ergänzen</button>
                <span class="small" data-ergaenzen-meldung role="status"></span>
            </details>
        <?php endif; ?>
    </div>
</section>
