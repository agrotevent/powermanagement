<?php
use App\Berichte\Felder;
use App\Berichte\Katalog;
use App\Support\View;

$anlage = $inhalt['kopf']['anlage'];
$netz = $inhalt['netz'];
$grundlage = $inhalt['grundlage'];
$netzBw = $bewertung['netz'] ?? ['auto' => '', 'meldungen' => []];
?>
<section class="card bericht-abschnitt" id="abschnitt-anlage" data-abschnitt="anlage">
    <div class="card-head"><h2>Anlage, Netz, Prüfgrundlage</h2></div>
    <div class="card-body">
        <div class="feld-raster">
            <?= Felder::text('kopf.anlage.bezeichnung', $anlage['bezeichnung'], 'Bezeichnung der Anlage', ['max' => 255, 'klasse' => 'breit',
                'platzhalter' => 'z. B. Aggregat – Hersteller Modell, 100 kVA']) ?>
            <?= Felder::text('kopf.anlage.details', $anlage['details'], 'Details (Kennung, Seriennummer, Verteiler)', ['mehrzeilig' => true, 'zeilen' => 3, 'max' => 2000]) ?>
            <?= Felder::text('kopf.anlage.aufstellort', $anlage['aufstellort'], 'Aufstellort', ['mehrzeilig' => true, 'zeilen' => 3, 'max' => 1000]) ?>
        </div>

        <h3 class="mt">Netz</h3>
        <div class="feld-raster">
            <?= Felder::auswahl('netz.einspeisung', $netz['einspeisung'], 'Einspeisung', ['' => '– bitte wählen –'] + Katalog::einspeisungen()) ?>
            <?= Felder::text('netz.spannung', $netz['spannung'], 'Netzspannung', ['zahl' => true, 'einheit' => 'V']) ?>
            <?= Felder::text('netz.frequenz', $netz['frequenz'], 'Frequenz', ['zahl' => true, 'einheit' => 'Hz']) ?>
            <?= Felder::text('netz.erder', $netz['erder'], 'Erder', ['max' => 120, 'platzhalter' => 'z. B. Staberder']) ?>
        </div>
        <div class="field" data-feld-ziel="netz.form" tabindex="-1">
            <span class="form-label">Netzform</span>
            <?= Felder::dreiwahl('netz.form', $netz['form'], Katalog::netzformen(), ['label' => 'Netzform', 'klasse' => 'netzform']) ?>
            <?php if ($netzVorschlag !== null && $netz['form'] === ''): ?>
                <button type="button" class="chip vorschlag" data-setzen="netz.form" data-wert="<?= View::e($netzVorschlag['form']) ?>">
                    wie letzter Bericht dieser Anlage<?= $netzVorschlag['nummer'] !== '' ? ' (' . View::e($netzVorschlag['nummer']) . ')' : '' ?>: <?= View::e($netzVorschlag['form']) ?></button>
            <?php endif; ?>
            <div class="help">Keine Vorgabe – die Netzform wählt der Prüfer.</div>
        </div>
        <div class="feld-raster">
            <?= Felder::text('netz.u_ln', $netz['u_ln'], 'Gemessene Spannung L–N', ['zahl' => true, 'einheit' => 'V']) ?>
            <?= Felder::auswahl('netz.drehfeld', $netz['drehfeld'], 'Drehfeld an der Einspeisung', ['' => '–', 'rechts' => 'rechts', 'links' => 'links']) ?>
        </div>
        <?php [$nk, $nt] = Felder::bewertung((string) $netzBw['auto']); ?>
        <div class="bewertung-zeile" data-bewertung-netz<?= $netzBw['meldungen'] === [] ? ' hidden' : '' ?>>
            <span class="bewertung <?= $nk ?>" data-bewertung><?= View::e($nt) ?></span>
            <ul class="meldungen" data-meldungen>
                <?php foreach ($netzBw['meldungen'] as $m): ?><li class="<?= View::e($m['stufe']) ?>"><?= View::e($m['text']) ?></li><?php endforeach; ?>
            </ul>
        </div>

        <h3 class="mt">Prüfung nach</h3>
        <div data-feld-ziel="grundlage.normen" tabindex="-1">
            <?= Felder::chips('grundlage.normen', $grundlage['normen'], Katalog::normen()) ?>
        </div>
        <div class="feld-raster">
            <?= Felder::text('grundlage.sonstige', $grundlage['sonstige'], 'Weitere Grundlage', ['max' => 255]) ?>
            <?= Felder::auswahl('grundlage.grund', $grundlage['grund'], 'Grund der Prüfung', ['' => '– bitte wählen –'] + Katalog::gruende()) ?>
        </div>
    </div>
</section>
