<?php
use App\Support\Csrf;
use App\Support\View;

$id = (int) $bericht['id'];
$bestaetigt = array_values(array_filter((array) ($inhalt['abschluss']['warnungen_bestaetigt'] ?? []), 'is_string'));
?>
<section class="card bericht-abschnitt" id="abschnitt-abschluss" data-abschnitt="abschluss">
    <div class="card-head"><h2>Prüfen und unterschreiben</h2></div>
    <div class="card-body">
        <?php if ($firmaFehlt): ?>
            <p class="notice warn" data-feld-ziel="firma" tabindex="-1">Die Firmendaten (Auftragnehmer im Kopf und Fuß des Berichts) fehlen noch –
                <?= View::can('settings.manage') ? '<a href="/system/einstellungen#firma">in den Einstellungen eintragen</a>' : 'bitte die Administration, sie in den Einstellungen einzutragen' ?>.</p>
        <?php endif; ?>
        <p class="small muted">„Prüfen“ zeigt, was vor der Unterschrift noch fehlt – mit Sprung zum Feld. Warnungen bestätigt der Prüfer mit einem Haken.
            Nach der Unterschrift ist der Bericht eingefroren und bekommt seine Nummer; geändert wird er dann nur noch als Korrektur.</p>
        <button class="btn<?= $darfUnterschreiben ? '' : ' btn-primary' ?>" type="button" data-pruefen>Prüfen</button>
        <div class="pruef-ergebnis" data-pruef-ergebnis hidden>
            <p class="pruef-zusammenfassung" data-pruef-text role="status"></p>
            <ul class="pruef-fehler" data-pruef-fehler></ul>
        </div>
        <div class="pruef-warnungen" data-pruef-warnungen>
            <?php foreach ($bestaetigt as $code): ?>
                <label class="check" hidden><input type="checkbox" data-feld="abschluss.warnungen_bestaetigt" data-liste value="<?= View::e($code) ?>" checked> <span><?= View::e($code) ?></span></label>
            <?php endforeach; ?>
        </div>
        <?php if ($darfUnterschreiben): ?>
            <div class="unterschreiben-zeile">
                <button class="btn btn-primary" type="button" data-unterschrift-auf>Als Prüfer unterschreiben</button>
                <span class="small muted">Speichert und prüft vorher noch einmal. Danach ist der Bericht eingefroren<?= $bericht['nummer'] === null ? ' und bekommt seine Nummer' : '' ?>.</span>
            </div>
            <?= View::partial('berichte/_unterschrift-dialog', [
                'titel'   => 'Als Prüfer unterschreiben',
                'hinweis' => 'Mit der Unterschrift bestätigst du die Prüfung nach den angegebenen Normen. Der Inhalt wird eingefroren; Änderungen gehen danach nur als Korrektur.',
                'knopf'   => 'Unterschreiben und einfrieren',
                'felder'  => [
                    ['name' => 'name', 'label' => 'Name in Druckbuchstaben', 'wert' => (string) $pruefer['name'], 'pflicht' => true, 'max' => 120,
                        'hilfe' => (string) $pruefer['qualifikation']],
                    ['name' => 'ort', 'label' => 'Ort', 'wert' => (string) $inhalt['kopf']['ort'], 'pflicht' => true, 'max' => 120],
                ],
            ]) ?>
        <?php elseif ($pruefer['recht'] && $pruefer['qualifikation'] === ''): ?>
            <p class="small text-warn mt">Unterschreiben geht erst mit einer hinterlegten Prüfer-Qualifikation (Benutzerverwaltung).</p>
        <?php else: ?>
            <p class="small muted mt">Unterschreiben wird jemand mit „als Prüfer unterschreiben“, Qualifikation und „bearbeiten“ für den Standort.</p>
        <?php endif; ?>
    </div>
    <div class="card-footer btn-row">
        <?php if ($bericht['nummer'] === null): ?>
            <form method="post" action="/berichte/<?= $id ?>/loeschen" data-confirm="Entwurf #<?= $id ?> löschen? Alle Eingaben gehen verloren.">
                <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Entwurf löschen</button></form>
        <?php elseif ($darfVerwerfen): ?>
            <form method="post" action="/berichte/<?= $id ?>/verwerfen" class="inline-form" data-confirm="<?= View::e($bericht['nummer']) ?> verwerfen? Die Nummer bleibt als Lücke erklärt.">
                <?= Csrf::field() ?>
                <input name="grund" required minlength="3" maxlength="500" placeholder="Grund" aria-label="Grund für das Verwerfen">
                <button class="btn btn-sm btn-ghost" type="submit">Verwerfen</button></form>
        <?php endif; ?>
    </div>
</section>
