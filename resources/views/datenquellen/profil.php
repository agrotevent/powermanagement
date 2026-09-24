<?php
use App\Support\Csrf;
use App\Support\View;

$neu = (int) $profil['id'] === 0;
$title = $neu ? 'Neues Registerprofil' : $profil['name'];
$subtitle = 'Registerprofil für Modbus TCP';
$json = static fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<?php if (!$neu): ?>
<div class="card">
    <div class="card-head"><h2>Werte</h2><span class="hint"><?= count($profil['register']) ?> Register</span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Name</th><th>Kanal</th><th>Phase</th><th>Feld</th><th class="num">Adresse</th><th>Typ</th><th class="num">Faktor</th><th class="num">Unit</th></tr></thead>
            <tbody>
            <?php foreach ($profil['register'] as $r): ?>
                <tr>
                    <td data-label="Name"><?= View::e((string) ($r['name'] ?? '')) ?></td>
                    <td data-label="Kanal" class="mono"><?= View::e((string) $r['kanal']) ?></td>
                    <td data-label="Phase"><?= View::e((string) ($r['phase'] ?? '') ?: 'Zustand') ?></td>
                    <td data-label="Feld" class="mono"><?= View::e((string) $r['feld']) ?></td>
                    <td data-label="Adresse" class="num"><?= ($r['adresse'] ?? null) === null ? '<span class="text-warn">fehlt</span>' : (int) $r['adresse'] ?></td>
                    <td data-label="Typ"><?= View::e((string) ($r['typ'] ?? 'u16')) ?></td>
                    <td data-label="Faktor" class="num"><?= View::e((string) ($r['faktor'] ?? 1)) ?></td>
                    <td data-label="Unit" class="num"><?= ($r['unit'] ?? null) === null ? 'Quelle' : (int) $r['unit'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($darf): ?>
<form method="post" action="/datenquellen/profile/speichern" class="card">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $profil['id'] ?>">
    <div class="card-head"><h2>Bearbeiten</h2></div>
    <div class="card-body">
        <?php if ((int) $profil['ist_mitgeliefert'] === 1): ?>
            <div class="notice">Ein mitgeliefertes Profil. Wer es ändert (etwa die ComAp-Adressen einträgt), macht es zu seinem – ein Update überschreibt es dann nicht mehr.</div>
        <?php endif; ?>
        <div class="field-row">
            <div class="field"><label for="p-name">Name</label><input id="p-name" name="name" required value="<?= View::e($profil['name']) ?>"></div>
            <div class="field"><label for="p-herst">Hersteller</label><input id="p-herst" name="hersteller" value="<?= View::e($profil['hersteller']) ?>"></div>
        </div>
        <div class="field"><label for="p-beschr">Beschreibung</label><textarea id="p-beschr" name="beschreibung" rows="2"><?= View::e((string) $profil['beschreibung']) ?></textarea></div>
        <div class="field"><label for="p-reg">Register (JSON)</label>
            <textarea id="p-reg" name="register_json" rows="18" class="mono" spellcheck="false"><?= View::e((string) $json($profil['register'])) ?></textarea>
            <div class="help">Je Eintrag: name, kanal, phase (L1/L2/L3/N/sum oder leer für Zustandswerte), feld (u, i, p, q, s, pf, f, e bzw. frei),
                adresse (0-basiert, null = noch offen), typ (u16, s16, u32, s32, float32), faktor, funktion (3 oder 4), unit (null = Unit-ID der Datenquelle),
                optional werte ({"0": "Aus", …}) und wort_tausch (true bei vertauschter Wortreihenfolge).</div></div>
        <div class="field"><label for="p-bef">Schaltbefehle (JSON)</label>
            <textarea id="p-bef" name="befehle_json" rows="10" class="mono" spellcheck="false"><?= View::e((string) $json($profil['befehle'])) ?></textarea>
            <div class="help">Je Befehl: schluessel, name, kanal, adresse, wert (Funktion 6) oder werte (Funktion 16), unit, art (ein/aus/modus/quittieren), aktiv.
                Nur aktive Befehle erscheinen als Knopf – und nur für Benutzer mit Schalt-Freigabe.</div></div>
    </div>
    <div class="card-footer btn-row">
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="/datenquellen/profile">Zurück</a>
    </div>
</form>
<?php if (!$neu): ?>
    <form method="post" action="/datenquellen/profile/loeschen" data-confirm="Profil löschen?">
        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $profil['id'] ?>">
        <button class="btn btn-sm btn-danger" type="submit">Profil löschen</button>
    </form>
<?php endif; ?>
<?php endif; ?>
