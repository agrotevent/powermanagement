<?php
use App\Services\AnlagenService;
use App\Services\BenutzerService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Freigaben: ' . $subjekt['titel'];
$kundenzugang = !empty($kundenzugang);
$subtitle = $art === 'role' ? 'für alle Benutzer mit dieser Rolle' : 'für diesen Benutzer';
$zeit = static function (?string $ab, ?string $bis): string {
    if (!$ab && !$bis) {
        return 'ohne Grenze';
    }
    return trim(($ab ? 'ab ' . Zeit::anzeige($ab) : '') . ' ' . ($bis ? 'bis ' . Zeit::anzeige($bis) : ''));
};
?>
<?php if ($kundenzugang): ?>
<div class="notice">Das ist ein Kundenzugang. Er sieht nur, was seinem Kunden freigegeben ist (nur Standorte, mit Ende und
    Datenzeitraum) – Freigaben am Konto oder an Rollen zählen für ihn nicht. Freigaben für Kunden pflegt die Kundenverwaltung.</div>
<?php endif; ?>
<div class="grid grid-sidebar">
    <div class="card">
        <div class="card-head"><h2>Freigaben</h2><span class="hint">mehrere Freigaben ergänzen sich</span></div>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Für</th><th>Darf</th><th>Gültig</th><th>Datenzeitraum</th><th></th></tr></thead>
                <tbody>
                <?php if ($freigaben === []): ?><tr><td colspan="5" class="muted">Keine Freigabe – ohne „alle Standorte“-Recht sieht <?= $art === 'role' ? 'die Rolle' : 'der Benutzer' ?> damit keine Anlage.</td></tr><?php endif; ?>
                <?php foreach ($freigaben as $f): ?>
                    <tr>
                        <td data-label="Für"><strong><?= View::e($f['ziel_name']) ?></strong><?= $f['notiz'] !== '' ? '<div class="small muted">' . View::e($f['notiz']) . '</div>' : '' ?></td>
                        <td data-label="Darf" class="small">sehen<?= (int) $f['darf_bearbeiten'] === 1 ? ', bearbeiten' : '' ?><?= (int) $f['darf_steuern'] === 1 ? ', <strong>schalten</strong>' : '' ?></td>
                        <td data-label="Gültig" class="small"><span class="freigabe-zustand-<?= View::e($f['zustand']) ?>"><?= View::e($f['zustand'] === 'aktiv' ? 'wirksam' : ($f['zustand'] === 'kommt' ? 'noch nicht' : 'abgelaufen')) ?></span>
                            <div><?= View::e($zeit($f['gueltig_ab'], $f['gueltig_bis'])) ?></div></td>
                        <td data-label="Datenzeitraum" class="small"><?= View::e($zeit($f['daten_ab'], $f['daten_bis'])) ?></td>
                        <td class="right">
                            <?php if (!$kundenzugang): ?>
                            <form method="post" action="/system/freigaben/loeschen" data-confirm="Freigabe entfernen?"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Entfernen</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!$kundenzugang): ?>
    <form method="post" action="/system/freigaben/speichern" class="card" id="freigabe-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="subject_type" value="<?= View::e($art) ?>">
        <input type="hidden" name="subject_id" value="<?= (int) $subjekt['id'] ?>">
        <div class="card-head"><h2>Neue Freigabe</h2></div>
        <div class="card-body">
            <div class="field"><label for="f-ziel">Gilt für</label>
                <select id="f-ziel" name="ziel_art">
                    <?php foreach (BenutzerService::ZIELE as $k => $n): ?><option value="<?= $k ?>"<?= $k === 'site' ? ' selected' : '' ?>><?= View::e($n) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field" data-ziel="site"><label for="f-site">Standort</label>
                <select id="f-site" name="ziel_id_site"><?php foreach ($standorte as $s): ?><option value="<?= (int) $s['id'] ?>"><?= View::e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field" data-ziel="objekt" hidden><label for="f-objekt">Anlage</label>
                <select id="f-objekt" name="ziel_id_objekt"><?php foreach ($objekte as $o): ?><option value="<?= (int) $o['id'] ?>"><?= View::e(AnlagenService::ARTEN[$o['art']]['kuerzel'] . ' · ' . $o['name'] . ($o['site_name'] ? ' (' . $o['site_name'] . ')' : '')) ?></option><?php endforeach; ?></select></div>
            <div data-ziel="geraet" hidden>
                <div class="field"><label for="f-geraet">Gerät</label>
                    <select id="f-geraet" name="ziel_id_geraet"><?php foreach ($geraete as $g): ?><option value="<?= (int) $g['id'] ?>"><?= View::e($g['name'] . ' (' . $g['geraet'] . ')') ?></option><?php endforeach; ?></select>
                    <?php if ($geraete === []): ?><div class="help">Noch keine Geräte eingetragen – unter Datenquellen anlegen (z. B. einen Shelly „schaltbar machen“).</div><?php endif; ?></div>
                <div class="field"><label for="f-kanal">nur Kanal (leer: alle)</label><input id="f-kanal" name="ziel_kanal" placeholder="z. B. switch:2"></div>
            </div>
            <label class="check"><input type="checkbox" name="darf_bearbeiten" value="1"> darf Anlagen bearbeiten (mit dem Recht „Anlagen ändern“)</label>
            <label class="check"><input type="checkbox" name="darf_steuern" value="1"> darf schalten (mit dem Recht „Geräte schalten“)</label>
            <h3 class="mt">Gültigkeit der Freigabe</h3>
            <div class="field-row">
                <div class="field"><label for="f-ab">ab</label><input id="f-ab" type="datetime-local" name="gueltig_ab"></div>
                <div class="field"><label for="f-bis">bis</label><input id="f-bis" type="datetime-local" name="gueltig_bis"></div>
            </div>
            <h3>Sichtbare Messdaten</h3>
            <label class="check"><input type="checkbox" name="daten_wie_gueltig" value="1" checked data-daten-wie> genau der Gültigkeitszeitraum</label>
            <div class="field-row" data-daten-frei hidden>
                <div class="field"><label for="f-dab">Daten ab</label><input id="f-dab" type="datetime-local" name="daten_ab"></div>
                <div class="field"><label for="f-dbis">Daten bis</label><input id="f-dbis" type="datetime-local" name="daten_bis"></div>
            </div>
            <p class="help">Beispiel Veranstalter: gültig vom Aufbau bis eine Woche nach der Veranstaltung, Daten nur aus den Veranstaltungstagen.
                Leere Felder heißen „ohne Grenze“.</p>
            <div class="field"><label for="f-notiz">Notiz</label><input id="f-notiz" name="notiz" placeholder="z. B. Festival 2026"></div>
            <input type="hidden" name="ziel_id" value="">
            <button class="btn btn-primary" type="submit">Freigabe anlegen</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php if (!$kundenzugang): ?>
<script nonce="<?= \App\Support\View::e(\App\Support\View::nonce()) ?>">
(function () {
    var form = document.getElementById('freigabe-form');
    var ziel = form.querySelector('[name=ziel_art]');
    var zeigen = function () {
        form.querySelectorAll('[data-ziel]').forEach(function (el) { el.hidden = el.dataset.ziel !== ziel.value; });
    };
    ziel.addEventListener('change', zeigen);
    zeigen();
    var wie = form.querySelector('[data-daten-wie]');
    wie.addEventListener('change', function () { form.querySelector('[data-daten-frei]').hidden = wie.checked; });
    form.addEventListener('submit', function () {
        var quelle = form.querySelector('[name=ziel_id_' + ziel.value + ']');
        form.querySelector('[name=ziel_id]').value = quelle ? quelle.value : '';
    });
})();
</script>
<?php endif; ?>
