<?php
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Settings;
use App\Support\View;

$title = 'Einstellungen';
$subtitle = 'InfluxDB, MQTT, Grenzwerte, Location-Abgleich, Firma & Übergabeberichte, Eventworx';
$feld = static function (string $schluessel, string $typ = 'text', string $platzhalter = '') use ($werte): string {
    $name = str_replace('.', '__', $schluessel);
    $wert = $werte[$schluessel] ?? '';
    $vorgabe = (string) Config::get($schluessel, '');
    return '<div class="field"><label for="e_' . $name . '">' . View::e(Settings::OVERRIDABLE[$schluessel]) . '</label>'
        . '<input id="e_' . $name . '" type="' . $typ . '" name="' . $name . '" value="' . View::e($wert) . '" placeholder="' . View::e($platzhalter !== '' ? $platzhalter : $vorgabe) . '">'
        . '<div class="help mono">' . View::e($schluessel) . ($wert === '' ? ' · aus config.php: ' . View::e($vorgabe === '' ? '–' : $vorgabe) : '') . '</div></div>';
};
?>
<form method="post" action="/system/einstellungen/speichern">
    <?= Csrf::field() ?>
    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>Oberfläche</h2></div>
            <div class="card-body">
                <?= $feld('app.name') ?><?= $feld('app.subtitle') ?><?= $feld('app.short_name') ?><?= $feld('app.timezone') ?>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h2>Grenzwerte</h2></div>
            <div class="card-body">
                <?= $feld('grenzen.warnung', 'number', '80') ?><?= $feld('grenzen.alarm', 'number', '95') ?><?= $feld('grenzen.spannungsfall', 'text', '3') ?>
                <?= $feld('material.reserve', 'number', '10') ?>
                <p class="help">Auslastung = höchster Phasenstrom durch Nennstrom der Anlage. Der Spannungsfall gilt für die Planungshinweise an Kabeln.
                    Die Kabelreserve kommt in der Materialliste auf die Länge im Plan (Durchhang, Weg zur Dose), bevor in Stücke geteilt wird.</p>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h2>InfluxDB</h2></div>
            <div class="card-body">
                <?= $feld('influx.url') ?><?= $feld('influx.database') ?><?= $feld('influx.org') ?><?= $feld('influx.username') ?>
                <div class="field"><label for="influx_token">API-Token (2.x/3.x)</label><input id="influx_token" name="influx_token" type="password" autocomplete="new-password" placeholder="<?= $geheim['influx'] ? 'hinterlegt – leer lassen zum Behalten' : '' ?>"></div>
                <div class="field"><label for="influx_password">Kennwort (1.8)</label><input id="influx_password" name="influx_password" type="password" autocomplete="new-password"></div>
                <p class="help">Abgefragt wird mit InfluxQL über /query – das verstehen 1.8, 2.x (DBRP-Zuordnung) und 3.x. „-“ entfernt ein Geheimnis.</p>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h2>MQTT und Sammler</h2></div>
            <div class="card-body">
                <?= $feld('mqtt.host') ?><?= $feld('mqtt.port', 'number') ?><?= $feld('mqtt.username') ?>
                <div class="field"><label for="mqtt_password">Kennwort</label><input id="mqtt_password" name="mqtt_password" type="password" autocomplete="new-password" placeholder="<?= $geheim['mqtt'] ? 'hinterlegt – leer lassen zum Behalten' : '' ?>"></div>
                <?= $feld('sammler.ziel', 'text', 'mqtt') ?>
                <p class="help">Der Sammler (Modbus, Clouds) schreibt nach <code>pm/messwert/&lt;gerät&gt;/&lt;kanal&gt;</code>; Node-RED übernimmt in die InfluxDB.
                    Mit „influx“ schreibt er direkt, ohne Node-RED.</p>
            </div>
        </div>
    </div>
    <div class="btn-row">
        <button class="btn btn-primary" type="submit">Speichern</button>
    </div>
</form>
<form method="post" action="/system/einstellungen/pruefen" class="mt">
    <?= Csrf::field() ?>
    <button class="btn" type="submit">InfluxDB, MQTT und Node-RED prüfen</button>
</form>

<?php if (View::can('settings.manage')): ?>
<form method="post" action="/system/einstellungen/location" class="card mt">
    <?= Csrf::field() ?>
    <div class="card-head"><h2>Location- und Verteiler-Abgleich</h2><span class="hint">dasselbe Repository wie in MasterAdmin</span></div>
    <div class="card-body grid grid-2">
        <div>
            <div class="field"><label for="repo_url">Adresse des Repositories</label><input id="repo_url" name="repo_url" value="<?= View::e((string) ($werte['inventory.repo_url'] ?? Config::get('inventory.repo_url', ''))) ?>" placeholder="https://github.com/…/masteradmin-locations.git"></div>
            <div class="field"><label for="branch">Zweig</label><input id="branch" name="branch" value="<?= View::e((string) ($werte['inventory.branch'] ?? Config::get('inventory.branch', 'main'))) ?>"></div>
        </div>
        <div>
            <div class="field"><label for="token">Zugangstoken</label><input id="token" name="token" type="password" autocomplete="new-password"
                placeholder="<?= $geheim['repo'] === 'eingetragen' ? 'hinterlegt – leer lassen zum Behalten' : ($geheim['repo'] === 'umgebung' ? 'kommt aus der Umgebung' : '') ?>">
                <div class="help">Verschlüsselt abgelegt, nie wieder angezeigt. „-“ entfernt es. Alternativ Umgebungsvariable POWERMANAGEMENT_REPO_TOKEN im PHP-FPM-Pool.</div></div>
        </div>
    </div>
    <div class="card-footer"><button class="btn btn-primary" type="submit">Speichern</button></div>
</form>

<?php
$bw = $berichte;
$bname = static fn (string $schluessel): string => str_replace('.', '__', $schluessel);
$bfeld = static function (string $schluessel, string $label, string $hilfe = '', string $platzhalter = '', int $max = 190) use ($bw, $bname): string {
    $id = 'f_' . $bname($schluessel);
    return '<div class="field"><label for="' . $id . '">' . View::e($label) . '</label>'
        . '<input id="' . $id . '" name="' . $bname($schluessel) . '" maxlength="' . $max . '" value="' . View::e($bw[$schluessel] ?? '') . '"'
        . ($platzhalter !== '' ? ' placeholder="' . View::e($platzhalter) . '"' : '') . '>'
        . ($hilfe !== '' ? '<div class="help">' . View::e($hilfe) . '</div>' : '') . '</div>';
};
$btext = static function (string $schluessel, string $label, string $hilfe = '', int $zeilen = 3, int $max = 2000) use ($bw, $bname): string {
    $id = 'f_' . $bname($schluessel);
    return '<div class="field"><label for="' . $id . '">' . View::e($label) . '</label>'
        . '<textarea id="' . $id . '" name="' . $bname($schluessel) . '" rows="' . $zeilen . '" maxlength="' . $max . '">' . View::e($bw[$schluessel] ?? '') . '</textarea>'
        . ($hilfe !== '' ? '<div class="help">' . View::e($hilfe) . '</div>' : '') . '</div>';
};
$bwahl = static function (string $schluessel, string $label, array $optionen, string $hilfe = '') use ($bw, $bname): string {
    $id = 'f_' . $bname($schluessel);
    $html = '<div class="field"><label for="' . $id . '">' . View::e($label) . '</label><select id="' . $id . '" name="' . $bname($schluessel) . '">';
    foreach ($optionen as $w => $t) {
        $html .= '<option value="' . View::e((string) $w) . '"' . ((string) ($bw[$schluessel] ?? '') === (string) $w ? ' selected' : '') . '>' . View::e($t) . '</option>';
    }
    return $html . '</select>' . ($hilfe !== '' ? '<div class="help">' . View::e($hilfe) . '</div>' : '') . '</div>';
};
$gewaehlteNormen = array_filter(array_map('trim', explode(',', (string) ($bw['berichte.normen'] ?? ''))));
?>
<form method="post" action="/system/einstellungen/firma" class="card mt" id="firma">
    <?= Csrf::field() ?>
    <div class="card-head"><h2>Firma &amp; Übergabeberichte</h2><span class="hint">Kopf und Fuß der Berichte, Nummern, Vorgaben, Texte</span></div>
    <div class="card-body grid grid-2">
        <div>
            <h3>Firmendaten</h3>
            <?= $bfeld('firma.name', 'Firmenname', 'Pflicht vor der ersten Prüfer-Unterschrift – steht im Kopf als Auftragnehmer.', '', 120) ?>
            <?= $bfeld('firma.zusatz', 'Zusatz', 'z. B. „Veranstaltungstechnik“ – unter dem Namen im Kopf.') ?>
            <?= $btext('firma.anschrift', 'Anschrift', '', 3, 500) ?>
            <?= $btext('firma.kontakt', 'Kontakt', 'Telefon, E-Mail, Web – im Fuß.', 3, 500) ?>
            <?= $btext('firma.bank', 'Bankverbindung', 'Optional, im Fuß.', 3, 500) ?>
            <?= $btext('firma.register', 'Register und Geschäftsführung',
                'Bei einer GmbH gehören Sitz, Registergericht, Registernummer und Geschäftsführer auf Geschäftsbriefe (§ 35a GmbHG).', 3, 500) ?>
        </div>
        <div>
            <h3>Berichte</h3>
            <div class="field-row">
                <?= $bfeld('berichte.praefix', 'Kürzel der Nummer', '1–6 Großbuchstaben: UB-2026-0042.', 'UB', 6) ?>
                <?= $bfeld('berichte.aufbewahrung_jahre', 'Aufbewahrung (Jahre)', 'bis 31.12. des Jahres Prüfdatum + Jahre', '10', 2) ?>
            </div>
            <div class="field-row">
                <?= $bfeld('berichte.ort', 'Ort (Ersatz)', 'Wenn der Standort keine Anschrift hat.', '', 120) ?>
                <?= $bfeld('berichte.bereitschaft', 'Bereitschaft (Telefon)', 'In der Einweisung und im Bericht.', '', 120) ?>
            </div>
            <fieldset><legend>Prüfung nach (Vorgabe)</legend>
                <input type="hidden" name="berichte__normen[]" value="">
                <?php foreach (\App\Berichte\Katalog::normen() as $k => $t): ?>
                    <label class="check"><input type="checkbox" name="berichte__normen[]" value="<?= View::e($k) ?>"<?= in_array($k, $gewaehlteNormen, true) ? ' checked' : '' ?>> <span><?= View::e($t) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <div class="field-row">
                <?= $bwahl('berichte.riso_u', 'Messspannung R_iso', ['250' => '250 V', '500' => '500 V', '1000' => '1000 V']) ?>
                <?= $bwahl('berichte.zs_faktor', 'Faktor für Zs', ['1.0' => '1,0', '0.8' => '0,8', '0.67' => '0,67'], 'Zs ≤ f · U0 / Ia') ?>
                <?= $bwahl('berichte.ul', 'Berührungsspannung U_L', ['50' => '50 V', '25' => '25 V']) ?>
            </div>
            <div class="field-row">
                <?= $bfeld('berichte.erde_generator', 'Erdung Generator (Ω)', 'Grenzwert im TN-System', '50', 12) ?>
                <?= $bfeld('berichte.rpe_hinweis', 'R_PE Hinweis ab (Ω)', '', '1,0', 12) ?>
            </div>
            <p class="help">Neue Berichte übernehmen diese Werte beim Anlegen und behalten sie – eine Änderung hier wirkt nur auf neue Berichte.</p>
        </div>
    </div>
    <div class="card-body grid grid-2">
        <?= $btext('berichte.text_kunde', 'Text zur Kundenbestätigung', 'Was der Kunde mit der Unterschrift bestätigt (nicht den prüftechnischen Inhalt).', 4, 2000) ?>
        <?= $btext('berichte.text_einweisung', 'Text zur Einweisung', '{telefon} wird durch die Bereitschaft ersetzt.', 4, 2000) ?>
        <?= $btext('berichte.text_errichter', 'Text der Errichterbestätigung', 'DGUV Vorschrift 3 § 5 Abs. 4 – nur bei „entspricht: Ja“.', 4, 2000) ?>
        <?= $btext('berichte.datenschutz', 'Datenschutzhinweis (Art. 13 DSGVO)', 'Steht über der Unterschrift des Kunden.', 4, 3000) ?>
    </div>
    <div class="card-footer"><button class="btn btn-primary" type="submit">Speichern</button></div>
</form>

<?php
$ewx = $eventworx;
$ewxFremd = $ewx['token'] && $ewx['token_host'] !== $ewx['host'];
?>
<div class="card mt" id="eventworx">
    <div class="card-head"><h2>Eventworx</h2><span class="hint">Kundendaten für Übergabeberichte – nur lesend</span></div>
    <form method="post" action="/system/einstellungen/eventworx">
        <?= Csrf::field() ?>
        <div class="card-body grid grid-2">
            <div>
                <div class="field"><label for="eventworx_adresse">Mandant</label>
                    <input id="eventworx_adresse" name="eventworx_adresse" value="<?= View::e($ewx['adresse']) ?>" placeholder="firma oder firma.eventworx.biz"
                        autocomplete="off" autocapitalize="off" spellcheck="false">
                    <div class="help">Nur die eigene Adresse https://&lt;mandant&gt;.eventworx.biz – ein eingefügter Link aus Eventworx geht auch. Leer entfernt die Anbindung.</div></div>
                <p class="btn-row">
                    <?php if ($ewx['demo']): ?>
                        <span class="badge info">Demo</span> <span class="help">Eventworx wird nicht gefragt, die Suche liefert Beispielvorgänge.</span>
                    <?php elseif ($ewx['eingerichtet']): ?>
                        <span class="badge ok">eingerichtet</span> <span class="help">Token hinterlegt für <?= View::e($ewx['host']) ?></span>
                    <?php elseif ($ewxFremd): ?>
                        <span class="badge bad">Token für andere Adresse</span> <span class="help">Bitte für <?= View::e($ewx['host']) ?> ein Token eintragen.</span>
                    <?php elseif ($ewx['host'] !== ''): ?>
                        <span class="badge warn">ohne Token</span> <span class="help">Ohne Token bleibt es bei Freitext.</span>
                    <?php else: ?>
                        <span class="badge">nicht eingerichtet</span> <span class="help">Kundendaten werden dann als Freitext eingetragen.</span>
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <div class="field"><label for="eventworx_token">API-Token</label>
                    <input id="eventworx_token" name="eventworx_token" type="password" autocomplete="new-password"
                        placeholder="<?= $ewx['token'] && !$ewxFremd ? View::e('hinterlegt für ' . $ewx['token_host'] . ' – leer lassen zum Behalten') : '' ?>">
                    <div class="help">Verschlüsselt abgelegt, nie wieder angezeigt, gilt nur für diese Adresse. „-“ entfernt es.</div></div>
                <p class="help">Ein Token „REST API Lesen“ genügt – PowerManagement liest nur Aufträge und Angebote.
                    Anlegen in Eventworx unter <em>Setup → Programmeinstellungen → API</em> (REST-Modul).</p>
            </div>
        </div>
        <div class="card-footer btn-row">
            <button class="btn btn-primary" type="submit">Speichern</button>
            <button class="btn" type="submit" form="eventworx-pruefen">Verbindung prüfen</button>
        </div>
    </form>
    <form method="post" action="/system/einstellungen/eventworx/pruefen" id="eventworx-pruefen"><?= Csrf::field() ?></form>
</div>
<?php endif; ?>
