<?php
/**
 * Reiter Freigaben (nur mit „kunden.manage“; anlegen und entfernen nur, wer
 * alle Standorte sieht): welche Standorte der Kunde wann sieht und aus
 * welchem Zeitraum Messdaten. Immer mit Ende und Datenzeitraum. Überschneidet
 * sich der Datenzeitraum mit dem eines anderen Kunden am selben Standort,
 * wird erst nach einer bewussten Bestätigung gespeichert.
 *
 * Ohne Skript: „Datenzeitraum wie Gültigkeit“ ist vorausgewählt; eigene
 * Datenfelder gelten nur ohne das Häkchen.
 *
 * @var array<string,mixed> $kunde
 * @var list<array<string,mixed>> $freigaben KundenService::freigaben()
 * @var bool $darfFreigeben
 * @var list<array<string,mixed>> $standorte
 * @var array<string,mixed> $eingabe
 */
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $kunde['id'];
$e = ($eingabe['form'] ?? '') === 'freigabe' ? $eingabe : [];
$ueber = (array) ($e['ueberschneidungen'] ?? []);
$feld = static fn (string $name): string => (string) ($e[$name] ?? '');
$datum = static fn (?string $iso): string => Zeit::anzeige($iso, 'd.m.Y H:i');
$zustand = ['wirksam' => ['ok', 'wirksam'], 'kommend' => ['info', 'kommt noch'], 'abgelaufen' => ['', 'abgelaufen'], 'unwirksam' => ['bad', 'wirkt nicht']];
$wieGueltig = $e === [] || !empty($e['daten_wie_gueltig']);
?>
<div class="card">
    <div class="card-head"><h2>Freigaben</h2><span class="hint">nur Standorte, nur sehen</span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Standort</th><th>Veranstaltung</th><th>Sichtbar</th><th>Messdaten</th><th></th></tr></thead>
            <tbody>
            <?php if ($freigaben === []): ?>
                <tr><td colspan="5" class="muted">Keine Freigabe – der Kunde sieht keinen Standort.</td></tr>
            <?php endif; ?>
            <?php foreach ($freigaben as $f): ?>
                <?php [$klasse, $wort] = $zustand[$f['zustand']] ?? ['', (string) $f['zustand']]; ?>
                <tr>
                    <td data-label="Standort"><strong><?= View::e((string) ($f['site_name'] ?? '') !== '' ? (string) $f['site_name'] : (string) $f['ziel_art'] . ' #' . (int) $f['ziel_id']) ?></strong></td>
                    <td data-label="Veranstaltung"><?= View::e((string) $f['notiz'] !== '' ? (string) $f['notiz'] : '–') ?></td>
                    <td data-label="Sichtbar" class="small"><span class="badge <?= View::e($klasse) ?>"><?= View::e($wort) ?></span>
                        <div><?= View::e(($f['gueltig_ab'] !== null ? $datum($f['gueltig_ab']) : 'sofort') . ' – ' . $datum($f['gueltig_bis'])) ?></div></td>
                    <td data-label="Messdaten" class="small"><?= $f['daten_ab'] !== null && $f['daten_bis'] !== null
                        ? View::e($datum($f['daten_ab']) . ' – ' . $datum($f['daten_bis']))
                        : '<span class="muted">keine</span>' ?></td>
                    <td class="right">
                        <?php if ($darfFreigeben): ?>
                        <form method="post" action="/kunden/<?= $id ?>/freigabe/<?= (int) $f['id'] ?>/loeschen" data-confirm="Freigabe entfernen? Der Kunde sieht den Standort darüber sofort nicht mehr.">
                            <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Entfernen</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!$darfFreigeben): ?>
    <p class="notice mt">Standorte für Kunden freigeben und Freigaben entfernen darf nur, wer selbst alle Standorte sieht
        (Recht „Alle Standorte, Anlagen und Messdaten“).</p>
<?php else: ?>
<form method="post" action="/kunden/<?= $id ?>/freigabe" class="card mt kunden-formular" id="neue-freigabe">
    <?= Csrf::field() ?>
    <div class="card-head"><h2>Standort freigeben</h2></div>
    <div class="card-body">
        <?php if ($ueber !== []): ?>
            <div class="notice warn">
                <p><strong>Überschneidung:</strong> In diesem Zeitraum hat an diesem Standort schon ein anderer Kunde Messdaten
                    freigegeben – dieser Kunde sähe sie mit.</p>
                <ul class="liste-schlicht">
                    <?php foreach ($ueber as $u): ?>
                        <li><?= View::e((string) $u['kunde']) ?><?= (string) $u['veranstaltung'] !== '' ? ' · ' . View::e((string) $u['veranstaltung']) : '' ?>
                            · Daten <?= View::e($datum((string) $u['daten_ab']) . ' – ' . $datum((string) $u['daten_bis'])) ?></li>
                    <?php endforeach; ?>
                </ul>
                <label class="check"><input type="checkbox" name="ueberschneidung_ok" value="1"> <span>Überschneidung bewusst – trotzdem speichern</span></label>
            </div>
        <?php endif; ?>
        <div class="field"><label for="fr-site">Standort</label>
            <select id="fr-site" name="site_id" required>
                <option value="">– bitte wählen –</option>
                <?php foreach ($standorte as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (int) $feld('site_id') === (int) $s['id'] ? ' selected' : '' ?>><?= View::e((string) $s['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label for="fr-notiz">Veranstaltung (sieht der Kunde)</label>
            <input id="fr-notiz" name="notiz" maxlength="255" value="<?= View::e($feld('notiz')) ?>" placeholder="z. B. Sommerfestival 2026"></div>
        <h3>Sichtbar</h3>
        <div class="field-row">
            <div class="field"><label for="fr-ab">ab</label><input id="fr-ab" type="datetime-local" name="gueltig_ab" value="<?= View::e($feld('gueltig_ab')) ?>"></div>
            <div class="field"><label for="fr-bis">bis (Pflicht)</label><input id="fr-bis" type="datetime-local" name="gueltig_bis" required value="<?= View::e($feld('gueltig_bis')) ?>"></div>
        </div>
        <h3>Messdaten</h3>
        <label class="check"><input type="checkbox" name="daten_wie_gueltig" value="1"<?= $wieGueltig ? ' checked' : '' ?>> <span>aus genau diesem Zeitraum (braucht „ab“)</span></label>
        <div class="field-row">
            <div class="field"><label for="fr-dab">sonst: Daten ab</label><input id="fr-dab" type="datetime-local" name="daten_ab" value="<?= View::e($feld('daten_ab')) ?>"></div>
            <div class="field"><label for="fr-dbis">Daten bis</label><input id="fr-dbis" type="datetime-local" name="daten_bis" value="<?= View::e($feld('daten_bis')) ?>"></div>
        </div>
        <p class="help">Ein Gelände hat mehrere Veranstalter: die Freigabe endet immer, und der Kunde sieht nur Messdaten aus seinem
            Zeitraum – etwa vom Aufbau bis eine Woche nach der Veranstaltung sichtbar, Daten nur aus den Veranstaltungstagen.
            Nie bearbeiten, nie schalten.</p>
    </div>
    <div class="card-footer"><button class="btn btn-primary" type="submit">Freigabe anlegen</button></div>
</form>
<?php endif; ?>
