<?php
/**
 * Unterschreiben (geraetepruefung.js): Prüfart und „Verantwortung
 * übernehmen“ für den ganzen Durchgang, der Knopf und der Vollbild-Dialog
 * (berichte/_unterschrift-dialog, unterschrift.js). Ohne `ids` nimmt der
 * Knopf die angehakten Prüfungen der Liste (input[data-gp-auswahl]); große
 * Auswahlen gehen in Stücken zu 25 mit derselben Unterschrift hinaus.
 *
 * @var array{darf:bool,recht:bool,name:string,qualifikation:string,ort:string} $u
 * @var string $ids Prüfungen des Knopfs (kommagetrennt) – leer: die Auswahl
 * @var string $knopf Beschriftung
 */
use App\Geraetepruefung\Messarten;
use App\Support\View;

$ids = (string) ($ids ?? '');
?>
<?php if ($u['darf']): ?>
    <div class="unterschreiben-zeile" data-gp-bereich>
        <div class="field-row">
            <div class="field">
                <label for="gp-pruefart">Prüfart</label>
                <select id="gp-pruefart" data-gp-feld="pruefart">
                    <?php foreach (Messarten::PRUEFARTEN as $wert => $text): ?>
                        <option value="<?= View::e($wert) ?>"><?= View::e($text) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="help">bestimmt die Norm im Bericht (DIN EN 50699 bzw. 50678), sofern der Prüfablauf keine eigene hat</div>
            </div>
            <div class="field">
                <label class="check"><input type="checkbox" data-gp-feld="uebernehmen" value="1">
                    <span>Verantwortung übernehmen, auch wo laut Prüfgerät jemand anderes geprüft hat</span></label>
                <div class="help">Der Bericht nennt dann den Prüfer laut Gerät und dich als verantwortlich.</div>
            </div>
        </div>
        <div class="btn-row">
            <button class="btn btn-primary" type="button" data-gp-unterschreiben<?= $ids !== '' ? ' data-gp-ids="' . View::e($ids) . '"' : '' ?>><?= View::e($knopf) ?></button>
            <span class="small muted" data-gp-stand role="status" aria-live="polite"><?= $ids === '' ? 'Prüfungen in der Liste anhaken, dann unterschreiben.' : '' ?></span>
        </div>
        <ul class="small text-bad" data-gp-fehler hidden></ul>
        <?= View::partial('berichte/_unterschrift-dialog', [
            'titel'   => 'Prüfberichte unterschreiben',
            'hinweis' => 'Mit der Unterschrift bestätigst du die Prüfung nach der angegebenen Norm. Jeder Bericht wird eingefroren und bekommt seine Nummer; '
                . 'Änderungen gehen danach nur als Korrektur (neue Fassung). Messwerte und Bewertung kommen unverändert aus dem Prüfgerät.',
            'knopf'   => 'Unterschreiben und einfrieren',
            'felder'  => [
                ['name' => 'name', 'label' => 'Name in Druckbuchstaben', 'wert' => $u['name'], 'pflicht' => true, 'max' => 120, 'hilfe' => $u['qualifikation']],
                ['name' => 'ort', 'label' => 'Ort', 'wert' => $u['ort'], 'pflicht' => true, 'max' => 120],
            ],
        ]) ?>
    </div>
<?php elseif ($u['recht']): ?>
    <p class="small text-warn">Unterschreiben geht erst mit einer hinterlegten Prüfer-Qualifikation (Benutzerverwaltung).</p>
<?php endif; ?>
