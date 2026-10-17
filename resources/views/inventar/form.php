<?php
use App\Services\InventarService;
use App\Support\Csrf;
use App\Support\View;
use App\Tanks\TankRechnung;

$neu = (int) $item['id'] === 0;
$art = (string) $item['art'];
$zs = $art === 'zwischenstecker';
$ts = $art === 'tanksensor';
$title = $neu ? match ($art) {
    'zwischenstecker' => 'Neuer Zwischenstecker',
    'tanksensor'      => 'Neuer Tanksensor',
    default           => 'Neuer Verteiler',
} : $item['kennung'] . ' bearbeiten';
$subtitle = match ($art) {
    'zwischenstecker' => 'Messadapter bis 125 A – misst, wo er gesteckt wird',
    'tanksensor'      => 'Füllstandsonde mit ihrem Shelly – misst im Tank, in den sie eingebaut wird',
    default           => 'Verteiler aus dem Lager mit seinen Shellys',
};
$stellen = match ($art) {
    'zwischenstecker' => ['durchgang' => 'Durchgang'],
    'tanksensor'      => ['fuellstand' => 'Füllstand'],
    default           => $stellenJeTyp[(string) ($item['vorlage_id'] ?? '')] ?? $stellenJeTyp[''],
};
// Die eingetragenen Shellys und Platz für weitere (mindestens drei Zeilen,
// immer eine leere). Ein Tanksensor hat genau einen Messkanal.
$zeilen = $ts ? array_slice($messgeraete, 0, 1) : $messgeraete;
$bis = $ts ? 1 : max(3, count($messgeraete) + 1);
while (count($zeilen) < $bis) {
    $zeilen[] = ['geraet' => '', 'kanal' => '', 'stelle' => (string) array_key_first($stellen), 'bezeichnung' => ''];
}
$kanaele = array_values(array_unique(array_map(static fn ($g) => (string) $g['kanal'], $geraete)));
// Sensordaten eines Tanksensors: Zahlen mit Komma, wie sie eingetippt werden.
$sensor = (array) ($item['eigenschaften'] ?? []);
$sw = static fn (string $k): string => View::zahlFeld($sensor[$k] ?? '');
$sv = static fn (string $k): string => (string) ($sensor[$k] ?? '');
$u4 = TankRechnung::zahlLesen($sensor['u_bei_4ma'] ?? null);
?>
<form method="post" action="/inventar/speichern" class="card" id="inventar-form"<?= $ts ? ' data-tanksensor data-schritte="' . View::e((string) json_encode(TankRechnung::SCHRITT)) . '"' : '' ?>>
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <input type="hidden" name="art" value="<?= View::e($art) ?>">
    <div class="card-head"><h2><?= View::e($title) ?></h2></div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <div class="field"><label for="kennung">Inventarnummer (Aufkleber)</label>
                    <input id="kennung" name="kennung" required value="<?= View::e($item['kennung']) ?>" placeholder="<?= match ($art) { 'zwischenstecker' => 'z. B. ZS-63-02', 'tanksensor' => 'z. B. TS-01', default => 'z. B. PD63-07' } ?>"></div>
                <div class="field"><label for="name">Name</label>
                    <input id="name" name="name" value="<?= View::e($item['name']) ?>" placeholder="<?= $ts ? 'optional, z. B. „Sonde 0–2 m, 5 m Kabel“' : 'optional, z. B. „roter PD 63 mit 3EM“' ?>"></div>
                <?php if ($zs): ?>
                    <div class="field"><label for="steckertyp_id">Steckverbinder</label>
                        <select id="steckertyp_id" name="steckertyp_id" required>
                            <option value="">– wählen –</option>
                            <?php foreach ($steckertypen as $t): ?>
                                <option value="<?= (int) $t['id'] ?>"<?= (int) $t['id'] === (int) $item['steckertyp_id'] ? ' selected' : '' ?>><?= View::e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                <?php elseif (!$ts): ?>
                    <div class="field"><label for="vorlage_id">Typ (Standard-Verteiler)</label>
                        <select id="vorlage_id" name="vorlage_id" data-stellen-je-typ="<?= View::e((string) json_encode($stellenJeTyp, JSON_UNESCAPED_UNICODE)) ?>">
                            <option value="">– ohne Typ –</option>
                            <?php foreach ($vorlagen as $v): ?>
                                <option value="<?= (int) $v['id'] ?>"<?= (int) $v['id'] === (int) $item['vorlage_id'] ? ' selected' : '' ?>><?= View::e($v['name'] . ($v['modell'] !== '' ? ' (' . $v['modell'] . ')' : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">Der Typ bringt Anschlüsse und Sicherungen mit. Wird das Stück in eine Anlage anderen Typs eingesetzt, lässt sich die Anlage umstellen.</div></div>
                <?php endif; ?>
                <div class="field"><label for="seriennummer">Seriennummer</label><input id="seriennummer" name="seriennummer" value="<?= View::e($item['seriennummer']) ?>"></div>
                <label class="check"><input type="checkbox" name="defekt" value="1"<?= (int) $item['defekt'] === 1 ? ' checked' : '' ?>> <span>defekt – nicht einsetzen</span></label>
                <div class="field"><label for="notizen">Notizen</label><textarea id="notizen" name="notizen" rows="3"><?= View::e((string) $item['notizen']) ?></textarea></div>
            </div>
            <div>
                <h3><?= $ts ? 'Messkanal' : 'Shellys' ?></h3>
                <p class="help"><?= match ($art) {
                    'zwischenstecker' => 'Der Shelly im Zwischenstecker misst den Durchgang – am Abgang oder an der Einspeisung, wo der Stecker beim Aufbau steckt.',
                    'tanksensor'      => 'Der Shelly, an dem die Sonde hängt – etwa ein Plus Uni (voltmeter:100), ein Plus mit Add-on (input:100) oder ein Uni (adc:0). Er meldet nur den Rohwert; Füllhöhe und Liter rechnet die App aus der Kennlinie.',
                    default           => 'Ein Shelly an der Einspeisung misst den ganzen Verteiler; Shellys an einzelnen Abgängen messen, was dort hängt. Beides geht auch zusammen.',
                } ?></p>
                <div class="inventar-zeilen" data-inventar-zeilen>
                    <?php foreach ($zeilen as $i => $g): ?>
                        <fieldset class="inventar-zeile">
                            <div class="field-row">
                                <div class="field"><label>Gerät</label><input name="geraet[]" list="geraete-liste" value="<?= View::e($g['geraet']) ?>" placeholder="<?= $ts ? 'shellyplusuni-…' : 'shellypro3em-…' ?>" autocomplete="off"<?= $ts ? ' required' : '' ?>></div>
                                <div class="field"><label>Kanal</label><input name="kanal[]" list="kanal-liste" value="<?= View::e($g['kanal']) ?>" placeholder="<?= $ts ? 'voltmeter:100' : 'em:0' ?>" autocomplete="off"<?= $ts ? ' data-sensor-kanal' : '' ?>></div>
                            </div>
                            <div class="field-row">
                                <?php if ($ts): ?>
                                    <input type="hidden" name="stelle[]" value="fuellstand">
                                <?php else: ?>
                                    <div class="field"><label>Sitzt an</label>
                                        <select name="stelle[]" data-stelle="<?= View::e($g['stelle']) ?>">
                                            <?php foreach ($stellen as $k => $n): ?><option value="<?= View::e($k) ?>"<?= $k === $g['stelle'] ? ' selected' : '' ?>><?= View::e($n) ?></option><?php endforeach; ?>
                                        </select></div>
                                <?php endif; ?>
                                <div class="field"><label>Bezeichnung</label><input name="bezeichnung[]" value="<?= View::e($g['bezeichnung']) ?>" placeholder="optional"></div>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
                <?php if (!$ts): ?><button type="button" class="btn btn-sm" data-zeile-dazu>+ weiterer Shelly</button><?php endif; ?>
                <datalist id="geraete-liste">
                    <?php foreach (array_unique(array_map(static fn ($g) => (string) $g['geraet'], $geraete)) as $g): ?><option value="<?= View::e($g) ?>"></option><?php endforeach; ?>
                </datalist>
                <datalist id="kanal-liste">
                    <?php foreach ($kanaele as $k): ?><option value="<?= View::e($k) ?>"></option><?php endforeach; ?>
                </datalist>

                <?php if ($ts): ?>
                    <h3 class="mt">Sensordaten</h3>
                    <p class="help">Was die Sonde misst und wie sie am Shelly hängt. Mit dem Einbau in einen Tank wird daraus die Kennlinie Rohwert → Füllhöhe → Liter.</p>
                    <fieldset>
                        <legend>Messprinzip</legend>
                        <div class="field"><label for="s_prinzip">Sensor</label>
                            <select id="s_prinzip" name="eigenschaften[prinzip]" data-sensor-prinzip>
                                <?php foreach (TankRechnung::PRINZIPIEN as $k => $n): ?><option value="<?= View::e($k) ?>"<?= $sv('prinzip') === $k ? ' selected' : '' ?>><?= View::e($n) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="field-row">
                            <div class="field"><label for="s_feld">Wert des Geräts</label>
                                <select id="s_feld" name="eigenschaften[feld]" data-sensor-feld>
                                    <?php foreach (TankRechnung::ROHFELDER as $k => [$n, $einheit]): ?><option value="<?= View::e($k) ?>"<?= $sv('feld') === $k ? ' selected' : '' ?>><?= View::e($n . ($einheit !== '' ? ' (' . $einheit . ')' : '') . ' – ' . $k) ?></option><?php endforeach; ?>
                                </select></div>
                            <div class="field"><label for="s_signal">Signal</label>
                                <select id="s_signal" name="eigenschaften[signal]" data-sensor-signal>
                                    <?php foreach (TankRechnung::SIGNALE as $k => $n): ?><option value="<?= View::e($k) ?>"<?= $sv('signal') === $k ? ' selected' : '' ?>><?= View::e($n) ?></option><?php endforeach; ?>
                                </select></div>
                        </div>
                    </fieldset>
                    <fieldset data-zeigen-signal="4-20mA-wandler">
                        <legend>Spannungswandler</legend>
                        <div class="field"><label for="s_endwert">Ausgang des Wandlers</label>
                            <select id="s_endwert" data-wandler-endwert>
                                <option value="">– wie eingetragen –</option>
                                <?php foreach (TankRechnung::WANDLER_ENDWERTE as $w): ?>
                                    <?php $vier = View::zahlFeld(TankRechnung::wandlerVorschlag($w)); ?>
                                    <option value="<?= View::e(View::zahlFeld($w)) ?>" data-u4="<?= View::e($vier) ?>">0–20 mA → 0–<?= View::e(View::zahlFeld($w)) ?> V (4 mA = <?= View::e($vier) ?> V)</option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="field-row">
                            <div class="field"><label for="s_u4">Spannung bei 4 mA (V)</label><input id="s_u4" name="eigenschaften[u_bei_4ma]" inputmode="decimal" value="<?= View::e($sw('u_bei_4ma')) ?>" placeholder="2,0" data-sensor-u4></div>
                            <div class="field"><label for="s_u20">Spannung bei 20 mA (V)</label><input id="s_u20" name="eigenschaften[u_bei_20ma]" inputmode="decimal" value="<?= View::e($sw('u_bei_20ma')) ?>" placeholder="10,0" data-sensor-u20></div>
                        </div>
                        <p class="help">So eintragen, wie der Shelly sie bei 4 und 20 mA meldet, nicht ausrechnen. Den Wandler auf 0–20 mA → 0–10 V stellen: 4 mA = 2,00 V, 20 mA = 10,00 V (U = I · 0,5 V/mA, mit dem Multimeter in der Schleife prüfen).</p>
                        <div class="notice warn" data-leitungsbruch<?= $u4 !== null && $u4 < 0.5 ? '' : ' hidden' ?>><?= View::e(TankRechnung::HINWEIS_LEITUNGSBRUCH) ?></div>
                    </fieldset>
                    <div class="field" data-zeigen-signal="4-20mA"><label for="s_buerde">Bürde (Ω)</label>
                        <input id="s_buerde" name="eigenschaften[buerde_ohm]" inputmode="decimal" value="<?= View::e($sw('buerde_ohm')) ?>" placeholder="499">
                        <div class="help">Widerstand am Analogeingang, durch den der Schleifenstrom fließt – etwa 499 Ω am Plus Uni (4–20 mA = 2–10 V) oder 470 Ω am Add-on.</div></div>
                    <fieldset data-zeigen-signal="4-20mA 4-20mA-wandler 0-10V">
                        <legend>Messbereich (steht auf dem Sensor)</legend>
                        <div class="field-row">
                            <div class="field"><label for="s_von">von</label><input id="s_von" name="eigenschaften[bereich_von]" inputmode="decimal" value="<?= View::e($sw('bereich_von')) ?>" placeholder="0"></div>
                            <div class="field"><label for="s_bis">bis</label><input id="s_bis" name="eigenschaften[bereich_bis]" inputmode="decimal" value="<?= View::e($sw('bereich_bis')) ?>" placeholder="z. B. 2"></div>
                            <div class="field"><label for="s_einheit">Einheit</label>
                                <select id="s_einheit" name="eigenschaften[bereich_einheit]" data-sensor-einheit>
                                    <?php foreach (TankRechnung::BEREICHSEINHEITEN as $k => $n): ?><option value="<?= View::e($k) ?>"<?= $sv('bereich_einheit') === $k ? ' selected' : '' ?>><?= View::e($n) ?></option><?php endforeach; ?>
                                </select></div>
                        </div>
                        <p class="help">Hydrostatisch: der Druck, den die Sonde bei 20 mA (bzw. 10 V) misst – etwa 0–2 m Wassersäule (≈ 2,4 m Diesel). Ultraschall und Schwimmer: Abstand bzw. Höhe in mm.</p>
                    </fieldset>
                    <div class="notice warn" data-zeigen-signal="0-10V"><?= View::e(TankRechnung::HINWEIS_0_10V) ?></div>
                    <div class="field" data-zeigen-prinzip="direkt"><label for="s_direkt">Das Gerät liefert</label>
                        <select id="s_direkt" name="eigenschaften[direkt_einheit]" data-sensor-direkt>
                            <option value="">– wählen –</option>
                            <?php foreach (['prozent' => 'Füllstand in %', 'liter' => 'Inhalt in Litern', 'mm' => 'Füllhöhe in mm'] as $k => $n): ?><option value="<?= View::e($k) ?>"<?= $sv('direkt_einheit') === $k ? ' selected' : '' ?>><?= View::e($n) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field" data-zeigen-prinzip="ultraschall"><label for="s_totzone">Totzone (mm)</label>
                        <input id="s_totzone" name="eigenschaften[totzone_mm]" inputmode="decimal" value="<?= View::e($sw('totzone_mm')) ?>" placeholder="z. B. 200">
                        <div class="help">So nah an der Sensorfläche misst der Sensor nicht – ein Wert darin gilt als Fehler, nicht als voll.</div></div>
                    <div class="field-row">
                        <div class="field"><label for="s_schritt">Meldeschritt</label>
                            <input id="s_schritt" name="eigenschaften[schritt_roh]" inputmode="decimal" value="<?= View::e($sw('schritt_roh')) ?>"
                                placeholder="<?= View::e(View::zahlFeld(TankRechnung::SCHRITT[$sv('feld')] ?? 1.0)) ?>" data-sensor-schritt>
                            <div class="help">Kleinster Schritt, in dem das Gerät meldet (report_thr) – leer = Vorgabe.</div></div>
                        <div class="field"><label for="s_hinweis">Hinweis</label>
                            <input id="s_hinweis" name="eigenschaften[hinweis]" value="<?= View::e($sv('hinweis')) ?>" maxlength="190" placeholder="optional, z. B. „PUR-Kabel, FKM-Dichtung“"></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-footer btn-row">
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="<?= $neu ? '/inventar' : '/inventar/' . (int) $item['id'] ?>">Abbrechen</a>
    </div>
</form>
