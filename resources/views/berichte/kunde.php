<?php
/**
 * Kundenmodus: der Kunde liest den eingefrorenen Bericht, bestätigt den
 * Erhalt und unterschreibt – am Gerät des Prüfers, ohne Navigation.
 * Danach „Gerät zurückgeben“; entsperrt wird mit dem Kennwort des Prüfers.
 * Ein Autosave gibt es hier nicht (bericht.js ist nicht geladen).
 */
use App\Berichte\Katalog;
use App\Support\Csrf;
use App\Support\View;

$b = $bericht;
$id = (int) $b['id'];
$title = (string) $b['nummer'];
$kopf = $inhalt['kopf'];
$texte = is_array($inhalt['texte'] ?? null) ? $inhalt['texte'] : [];
// Texte aus den festen Kopien, Reihenfolge aus dem Katalog („Bericht erhalten“ zuerst).
$bst = Katalog::inReihenfolge(is_array($inhalt['labels']['bestaetigungen'] ?? null) ? $inhalt['labels']['bestaetigungen']
    : array_map(static fn (array $x): string => $x['text'], Katalog::bestaetigungen()), Katalog::bestaetigungen());
$fertig = isset($unterschriften['kunde']);
$offen = $b['status'] === 'geprueft' && !$fertig;
// Zurück an den, der den Kundenmodus gestartet hat – nur sein Kennwort entsperrt
// (nicht unbedingt der unterschreibende Prüfer). Ohne vollen Namen kein
// Benutzername auf dem Bildschirm des Kunden.
$prueferName = trim((string) $prueferName);
$zurueckAn = $prueferName !== '' ? $prueferName : 'den Prüfer';
$kennwortVon = $prueferName !== '' ? 'Kennwort von ' . $prueferName : 'Kennwort des Prüfers';
$datum = static fn (string $d): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
?>
<div class="kunde" data-kunde="<?= $id ?>" data-inhalt-hash="<?= View::e((string) $b['inhalt_hash']) ?>">
    <?php if ($fertig): ?>
        <section class="card kunde-fertig">
            <div class="card-body">
                <h1>Vielen Dank!</h1>
                <p class="kunde-gross">Bitte geben Sie das Gerät an <strong><?= View::e($zurueckAn) ?></strong> zurück.</p>
                <p class="small muted">Der Bericht <?= View::e((string) $b['nummer']) ?> ist abgeschlossen. Sie erhalten ihn als PDF.</p>
                <?php if (!empty($imKundenbereich)): ?>
                    <p class="notice">Der Bericht steht ab sofort in Ihrem Kundenbereich bereit.</p>
                <?php endif; ?>
                <p><a class="btn" href="/berichte/<?= $id ?>/pdf" target="_blank" rel="noopener">Bericht als PDF ansehen</a></p>
            </div>
        </section>
    <?php else: ?>
        <h1 class="kunde-titel">Übergabebericht <?= View::e((string) $b['nummer']) ?></h1>
        <p class="kunde-einleitung"><?= View::e(implode(' · ', array_filter([(string) $kopf['veranstaltung'], (string) $kopf['anlage']['bezeichnung'],
            'Prüfdatum ' . $datum((string) $kopf['pruefdatum'])]))) ?></p>
        <?php if ($offen): ?>
            <p class="notice">Bitte lesen Sie den Bericht. Unten bestätigen Sie den Erhalt mit Ihrer Unterschrift.</p>
            <p><a class="btn" href="/berichte/<?= $id ?>/pdf" target="_blank" rel="noopener">Als PDF ansehen</a>
                <span class="small muted">Vorschau – das endgültige PDF entsteht mit Ihrer Bestätigung.</span></p>
        <?php else: ?>
            <p class="notice warn">Dieser Bericht ist nicht zur Unterschrift offen – bitte das Gerät an <?= View::e($zurueckAn) ?> zurückgeben.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$fertig): ?>
        <?= View::partial('berichte/_ansicht', ['bericht' => $b, 'inhalt' => $inhalt, 'bewertung' => $bewertung, 'unterschriften' => $unterschriften]) ?>
    <?php endif; ?>

    <?php if ($offen): ?>
        <section class="card kunde-bestaetigung" id="bestaetigung">
            <div class="card-head"><h2>Ihre Bestätigung</h2></div>
            <div class="card-body">
                <?php if (($texte['kunde'] ?? '') !== ''): ?><p class="kunde-hinweis"><?= View::e((string) $texte['kunde']) ?></p><?php endif; ?>
                <?php foreach ($bst as $schluessel => $text): ?>
                    <?php
                    $pflicht = (bool) (Katalog::bestaetigungen()[(string) $schluessel]['pflicht'] ?? false);
                    $label = match ((string) $schluessel) {
                        'eingewiesen' => (string) ($texte['einweisung'] ?? $text),
                        'uebernommen' => (string) $text . ((string) $kopf['zeitraum'] !== '' ? ' für ' . $kopf['zeitraum'] : ''),
                        default       => (string) $text,
                    };
                    ?>
                    <label class="check kunde-haken"><input type="checkbox" value="<?= View::e((string) $schluessel) ?>" data-bestaetigung<?= $pflicht ? ' data-pflicht aria-required="true"' : '' ?>>
                        <span><?= View::e($label) ?><?= $pflicht ? ' <strong>(Pflicht)</strong>' : '' ?></span></label>
                <?php endforeach; ?>
                <div class="field mt">
                    <label for="kunde-vorbehalte">Vorbehalte oder Mängel aus Ihrer Sicht (freiwillig)</label>
                    <textarea id="kunde-vorbehalte" rows="3" maxlength="2000" data-kunde-vorbehalte></textarea>
                </div>
                <?php if (($texte['datenschutz'] ?? '') !== ''): ?><p class="small muted"><?= View::e((string) $texte['datenschutz']) ?></p><?php endif; ?>
                <p class="kunde-meldung" data-kunde-meldung role="status" aria-live="polite"></p>
                <button class="btn btn-primary kunde-weiter" type="button" data-kunde-weiter>Weiter zur Unterschrift</button>
            </div>
        </section>
        <?= View::partial('berichte/_unterschrift-dialog', [
            'titel'   => 'Unterschrift des Kunden',
            'hinweis' => 'Abnahme-/Dokumentbestätigung – es wird nicht der prüftechnische Inhalt bestätigt. Gespeichert wird nur der Linienzug.',
            'knopf'   => 'Unterschreiben und abschließen',
            'felder'  => [
                ['name' => 'name', 'label' => 'Name in Druckbuchstaben', 'wert' => '', 'pflicht' => true, 'max' => 120],
                ['name' => 'funktion', 'label' => 'Funktion / Firma', 'wert' => '', 'pflicht' => false, 'max' => 120],
                ['name' => 'ort', 'label' => 'Ort', 'wert' => (string) $kopf['ort'], 'pflicht' => false, 'max' => 120],
            ],
        ]) ?>
    <?php endif; ?>

    <details class="card kunde-entsperren"<?= $fertig || !$offen ? ' open' : '' ?>>
        <summary class="card-head"><h2>Für den Prüfer: Gerät entsperren</h2></summary>
        <div class="card-body">
            <form method="post" action="/berichte/<?= $id ?>/kunde/beenden" class="inline-form">
                <?= Csrf::field() ?>
                <?php /* new-password: kein automatisches Ausfüllen des gespeicherten Kennworts – sonst entsperrte ein Tipp des Kunden das Gerät. */ ?>
                <input type="password" name="kennwort" required autocomplete="new-password" aria-label="<?= View::e($kennwortVon) ?>"
                       placeholder="<?= View::e($kennwortVon) ?>">
                <button class="btn" type="submit">Entsperren</button>
            </form>
            <p class="small muted mb0">Nach <?= \App\Berichte\Kundenmodus::HOECHSTENS_FEHL ?> falschen Versuchen wird das Entsperren für einige Minuten gebremst.</p>
        </div>
    </details>
</div>
