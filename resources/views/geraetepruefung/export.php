<?php
/*
 * Datei für das Prüfgerät schreiben: Besitzer wählen (eigener Bestand,
 * Kunden), Dateiname, Download. Erwartet $vorlage (BenningVorlage::aktiv
 * oder null), $besitzer (BenningVorlage::besitzer), $exporte (die letzten),
 * $name (Vorgabe des Dateinamens).
 *
 * Kein data-laeuft: Die Antwort ist die Datei selbst – der Browser lädt sie.
 */
use App\Geraetepruefung\BenningVorlage;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Geräteprüfung: Export';
$subtitle = 'Kunden und Prüflinge als Datenbank für das BENNING ST 755+';
$mitPrueflingen = array_values(array_filter($besitzer, static fn (array $b): bool => $b['prueflinge'] > 0 || $b['wert'] === 'eigen'));
$ohne = array_values(array_filter($besitzer, static fn (array $b): bool => $b['prueflinge'] === 0 && $b['wert'] !== 'eigen'));
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="/geraetepruefung">← Geräteprüfung</a>
    <a class="btn btn-sm btn-ghost" href="/geraetepruefung/import">Import</a>
    <a class="btn btn-sm btn-ghost" href="/geraetepruefung/pruefablaeufe">Prüfabläufe</a>
</div>

<?php if ($vorlage === null): ?>
    <div class="notice warn">Es gibt noch keine Vorlage. Erst eine Datenbank des Prüfgeräts <a href="/geraetepruefung/import">importieren</a> und
        dabei „Einstellungen des Geräts als Vorlage übernehmen“ anhaken – aus ihr entsteht die Datei für das Prüfgerät.</div>
<?php else: ?>
    <form method="post" action="/geraetepruefung/export" class="card" id="gp-export">
        <?= Csrf::field() ?>
        <div class="card-head"><h2>Wer kommt in die Datei?</h2>
            <div class="btn-row">
                <button class="btn btn-sm btn-ghost" type="button" data-auswahl-an="#gp-export input[name='besitzer[]']">alle</button>
                <button class="btn btn-sm btn-ghost" type="button" data-auswahl-aus="#gp-export input[name='besitzer[]']">keine</button>
            </div>
        </div>
        <div class="table-wrap">
            <table class="tabelle-karten import-tabelle">
                <thead><tr><th>Auswahl</th><th>Besitzer</th><th>Kundennummer im Gerät</th><th class="num">Prüflinge</th></tr></thead>
                <tbody>
                <?php foreach ([...$mitPrueflingen, ...$ohne] as $b): ?>
                    <tr>
                        <td class="import-haken"><label class="check"><input type="checkbox" name="besitzer[]" value="<?= View::e($b['wert']) ?>"<?= $b['prueflinge'] > 0 ? ' checked' : '' ?>> mitnehmen</label></td>
                        <td data-label="Besitzer"><strong><?= View::e($b['name']) ?></strong><?= $b['wert'] === 'eigen' ? ' <span class="badge">eigener Bestand</span>' : '' ?></td>
                        <td data-label="Kundennummer im Gerät" class="mono small"><?= View::e($b['kundennummer']) ?></td>
                        <td data-label="Prüflinge" class="num"><?= (int) $b['prueflinge'] ?>
                            <?php if ($b['ohne_ablauf'] > 0): ?><div class="small text-warn"><?= (int) $b['ohne_ablauf'] ?> ohne gültigen Prüfablauf – bleiben weg</div><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body">
            <div class="field">
                <label for="gp-name">Dateiname</label>
                <input id="gp-name" name="name" maxlength="80" value="<?= View::e($name) ?>">
                <div class="help">Endet auf <code>.db</code>. Die Datei kommt auf die SD-Karte des Geräts; nach der Prüfung wird sie wieder importiert.</div>
            </div>
        </div>
        <div class="card-footer btn-row">
            <button class="btn btn-primary" type="submit">Datei schreiben</button>
            <span class="small muted">Vorlage vom <?= View::e(Zeit::anzeige((string) $vorlage['created_at'], 'd.m.Y')) ?><?= $vorlage['messgeraet_sn'] !== '' ? ' (Gerät ' . View::e($vorlage['messgeraet_sn']) . ')' : '' ?></span>
        </div>
    </form>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Letzte Exporte</h2></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Wann</th><th class="num">Besitzer</th><th class="num">Prüflinge</th><th>Wer</th></tr></thead>
            <tbody>
            <?php if ($exporte === []): ?><tr><td colspan="4" class="muted">Noch kein Export.</td></tr><?php endif; ?>
            <?php foreach ($exporte as $e): ?>
                <tr>
                    <td data-label="Wann"><?= View::e(Zeit::anzeige((string) $e['created_at'])) ?></td>
                    <td data-label="Besitzer" class="num"><?= (int) $e['kunden'] ?></td>
                    <td data-label="Prüflinge" class="num"><?= (int) $e['prueflinge'] ?></td>
                    <td data-label="Wer" class="small"><?= View::e((string) ($e['username'] ?? '–')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Was in der Datei steht</h2></div>
    <div class="card-body small">
        <p>Die Einstellungen des Geräts aus der Vorlage bleiben Byte für Byte (Prüfabläufe, Grenzwerte, Fragen, Systemdaten). Dazu
            kommen die gewählten Kunden – der eigene Bestand mit der Kundennummer <code><?= View::e(BenningVorlage::EIGEN) ?></code>, ein Kunde
            ohne Kundennummer mit <code>PM-K…</code> – und ihre Prüflinge mit Prüfablauf, Schutzklasse, Leitung, Intervall und
            letzter Prüfung. Prüfungen kommen nicht mit; das Gerät prüft neu.</p>
        <p class="mb0">Daran erkennt der nächste Import Kunden und Prüflinge wieder. Ein Prüfling ohne Prüfablauf (oder mit einem, den die
            Vorlage nicht kennt) bleibt weg – im Gerät ließe er sich nicht prüfen.</p>
    </div>
</div>
