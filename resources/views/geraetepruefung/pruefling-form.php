<?php
/**
 * Formular eines Prüflings – neu ($p null) oder bearbeiten. Geprüft wird
 * alles auf dem Server (Prueflinge::speichern): Kennung je Besitzer
 * eindeutig, Inventarstück nur beim eigenen Bestand (und nur mit
 * „Datenquellen ansehen“), Prüfablauf nur ein am Gerät gültiger, Zahlen als
 * Dezimaltext (Komma erlaubt). Zahlen stehen ungerundet im Feld
 * (View::zahlFeld): unverändert speichern ändert nichts. Den Besitzer gibt
 * es nach dem ersten Prüfbericht nicht mehr zu ändern; die Daten der
 * letzten und nächsten Prüfung nur, solange es hier keine Prüfung gibt.
 *
 * @var array<string,mixed>|null $p
 * @var array<string,mixed> $werte gespeicherte Werte bzw. Eingaben nach einem Fehler
 * @var list<array{id:int,name:string,kundennummer:string}> $kunden
 * @var list<array{id:int,geraet_id:string,nr:string,name:string,schutzklasse:string,ungueltig:bool}> $ablaeufe
 * @var list<array{id:int,kennung:string,name:string}> $inventar
 * @var bool $darfInventar
 * @var int $pruefungen
 * @var int $berichte
 */
use App\Geraetepruefung\Prueflinge;
use App\Kunden\Pruefnachweise;
use App\Support\Csrf;
use App\Support\View;

$id = $p === null ? null : (int) $p['id'];
$title = $id === null ? 'Neuer Prüfling' : 'Prüfling ' . (string) $p['kennung'] . ' bearbeiten';
$subtitle = 'Geräteprüfung (DGUV V3)';
$w = static fn (string $feld): string => is_scalar($werte[$feld] ?? null) ? (string) $werte[$feld] : '';
$gewaehlt = static fn (string $feld, string $wert): string => $w($feld) === $wert ? ' selected' : '';
$ausserBetrieb = !empty($werte['ausser_betrieb']);
$zurueck = $id === null ? '/geraetepruefung/prueflinge' : '/geraetepruefung/prueflinge/' . $id;
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="<?= View::e($zurueck) ?>">← <?= $id === null ? 'Prüflinge' : 'Prüfling' ?></a>
</div>

<form method="post" action="/geraetepruefung/prueflinge/speichern" class="card">
    <?= Csrf::field() ?>
    <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <div class="card-head"><h2><?= View::e($title) ?></h2><span class="hint">Angaben wie im Prüfgerät</span></div>
    <div class="card-body">
        <div class="field-row">
            <div class="field"><label for="pf-kennung">Kennung</label>
                <input id="pf-kennung" name="kennung" required maxlength="<?= (int) Prueflinge::KENNUNG_LAENGE ?>" class="mono" value="<?= View::e($w('kennung')) ?>" autocomplete="off">
                <div class="help">Prüflings-ID bzw. Barcode am Prüfgerät – je Besitzer nur einmal (ohne Groß-/Kleinschreibung).</div></div>
            <div class="field"><label for="pf-besitzer">Besitzer</label>
                <select id="pf-besitzer" name="kunde_id"<?= $berichte > 0 ? ' disabled' : '' ?>>
                    <option value=""<?= $gewaehlt('kunde_id', '') ?>><?= View::e(Prueflinge::EIGEN) ?></option>
                    <?php foreach ($kunden as $k): ?>
                        <option value="<?= (int) $k['id'] ?>"<?= $gewaehlt('kunde_id', (string) $k['id']) ?>><?= View::e($k['name'] . ($k['kundennummer'] !== '' ? ' (' . $k['kundennummer'] . ')' : '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($berichte > 0): ?><div class="help">Es gibt schon Prüfberichte, die diesen Besitzer nennen – er bleibt.</div><?php endif; ?></div>
        </div>
        <?php if ($darfInventar): ?>
            <div class="field"><label for="pf-inventar">Inventarstück</label>
                <select id="pf-inventar" name="inventar_id">
                    <option value=""<?= $gewaehlt('inventar_id', '') ?>>keins</option>
                    <?php foreach ($inventar as $i): ?>
                        <option value="<?= (int) $i['id'] ?>"<?= $gewaehlt('inventar_id', (string) $i['id']) ?>><?= View::e($i['kennung'] . ($i['name'] !== '' ? ' · ' . $i['name'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="help">Nur beim eigenen Bestand; jedes Stück nur mit einem Prüfling, Tanksensoren nie. Am Inventar steht dann die letzte Prüfung.</div></div>
        <?php elseif (($p['inventar_id'] ?? null) !== null): ?>
            <p class="small muted">Mit einem Inventarstück verknüpft – ändern nur mit „Datenquellen ansehen“; die Verknüpfung bleibt beim Speichern.</p>
        <?php endif; ?>
        <div class="field-row">
            <div class="field"><label for="pf-bezeichnung">Bezeichnung</label>
                <input id="pf-bezeichnung" name="bezeichnung" maxlength="<?= (int) Prueflinge::TEXTE['bezeichnung'] ?>" value="<?= View::e($w('bezeichnung')) ?>" placeholder="z. B. Kabeltrommel 25 m"></div>
            <div class="field"><label for="pf-sn">Seriennummer</label>
                <input id="pf-sn" name="seriennummer" maxlength="<?= (int) Prueflinge::TEXTE['seriennummer'] ?>" value="<?= View::e($w('seriennummer')) ?>"></div>
        </div>
        <div class="field-row">
            <div class="field"><label for="pf-hersteller">Hersteller</label>
                <input id="pf-hersteller" name="hersteller" maxlength="<?= (int) Prueflinge::TEXTE['hersteller'] ?>" value="<?= View::e($w('hersteller')) ?>"></div>
            <div class="field"><label for="pf-typ">Typ</label>
                <input id="pf-typ" name="typ" maxlength="<?= (int) Prueflinge::TEXTE['typ'] ?>" value="<?= View::e($w('typ')) ?>"></div>
            <div class="field"><label for="pf-modell">Modell</label>
                <input id="pf-modell" name="modell" maxlength="<?= (int) Prueflinge::TEXTE['modell'] ?>" value="<?= View::e($w('modell')) ?>"></div>
        </div>
        <div class="field-row">
            <div class="field"><label for="pf-abteilung">Abteilung</label>
                <input id="pf-abteilung" name="abteilung" maxlength="<?= (int) Prueflinge::TEXTE['abteilung'] ?>" value="<?= View::e($w('abteilung')) ?>"></div>
            <div class="field"><label for="pf-gebaeude">Gebäude</label>
                <input id="pf-gebaeude" name="gebaeude" maxlength="<?= (int) Prueflinge::TEXTE['gebaeude'] ?>" value="<?= View::e($w('gebaeude')) ?>"></div>
        </div>

        <h3 class="mt">Prüfung</h3>
        <div class="field-row">
            <div class="field"><label for="pf-sk">Schutzklasse</label>
                <select id="pf-sk" name="schutzklasse">
                    <?php foreach (Prueflinge::SCHUTZKLASSEN as $k => $n): ?>
                        <option value="<?= View::e((string) $k) ?>"<?= $gewaehlt('schutzklasse', (string) $k) ?>><?= View::e($n) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="field"><label for="pf-ablauf">Prüfablauf</label>
                <select id="pf-ablauf" name="pruefablauf_id">
                    <option value=""<?= $gewaehlt('pruefablauf_id', '') ?>>keiner</option>
                    <?php foreach ($ablaeufe as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"<?= $gewaehlt('pruefablauf_id', (string) $a['id']) ?>><?= View::e(trim($a['nr'] . ' ' . $a['name']) . ($a['ungueltig'] ? ' (am Prüfgerät ungültig)' : '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="help"><?= $ablaeufe === [] ? 'Noch keine Prüfabläufe – sie kommen mit der ersten importierten Datenbank des Prüfgeräts. ' : '' ?>Ohne Prüfablauf geht der Prüfling nicht in die Datei für das Prüfgerät.</div></div>
            <div class="field"><label for="pf-intervall">Prüfintervall (Monate)</label>
                <input id="pf-intervall" name="intervall_monate" type="number" min="0" max="<?= (int) Prueflinge::INTERVALL_HOECHSTENS ?>" step="1" inputmode="numeric" value="<?= View::e($w('intervall_monate')) ?>">
                <div class="help">0 = keine Wiederholung.</div></div>
        </div>
        <div class="field-row">
            <?php foreach (Prueflinge::ZAHLEN_NAMEN as $feld => $name): ?>
                <div class="field"><label for="pf-<?= View::e($feld) ?>"><?= View::e($name) ?></label>
                    <input id="pf-<?= View::e($feld) ?>" name="<?= View::e($feld) ?>" inputmode="decimal" maxlength="<?= (int) Prueflinge::ZAHLEN[$feld] ?>" value="<?= View::e(View::zahlFeld($w($feld))) ?>"></div>
            <?php endforeach; ?>
        </div>
        <?php if ($pruefungen === 0): ?>
            <div class="field-row">
                <div class="field"><label for="pf-letzte">Letzte Prüfung</label>
                    <input id="pf-letzte" name="letzte_pruefung" type="date" value="<?= View::e($w('letzte_pruefung')) ?>">
                    <div class="help">Nur, solange es hier keine Prüfung gibt (etwa eine Prüfung anderswo).</div></div>
                <div class="field"><label for="pf-naechste">Nächste Prüfung</label>
                    <input id="pf-naechste" name="naechste_pruefung" type="date" value="<?= View::e($w('naechste_pruefung')) ?>">
                    <div class="help">Leer: letzte Prüfung plus Intervall.</div></div>
            </div>
        <?php else: ?>
            <p class="small">Letzte Prüfung <?= View::e(Pruefnachweise::datum($p['letzte_pruefung'] ?? null) ?: '–') ?>, nächste <?= View::e(Pruefnachweise::datum($p['naechste_pruefung'] ?? null) ?: '–') ?>
                – aus den <?= (int) $pruefungen ?> Prüfungen hier gerechnet, nach dem Speichern mit dem neuen Intervall.</p>
        <?php endif; ?>
        <label class="check"><input type="checkbox" name="ausser_betrieb" value="1"<?= $ausserBetrieb ? ' checked' : '' ?>> außer Betrieb – nicht mehr fällig, nicht in der Datei für das Prüfgerät als aktiv</label>
        <div class="field mt"><label for="pf-bemerkung">Bemerkung</label>
            <textarea id="pf-bemerkung" name="bemerkung" rows="3" maxlength="<?= (int) Prueflinge::TEXTE['bemerkung'] ?>"><?= View::e($w('bemerkung')) ?></textarea></div>
    </div>
    <div class="card-footer btn-row">
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="<?= View::e($zurueck) ?>">Abbrechen</a>
    </div>
</form>
