<?php
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Settings;
use App\Support\View;

$title = 'Einstellungen';
$subtitle = 'InfluxDB, MQTT, Grenzwerte, Location-Abgleich';
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

<?php if (View::can('inventory.push')): ?>
<form method="post" action="/system/einstellungen/location" class="card mt">
    <?= Csrf::field() ?>
    <div class="card-head"><h2>Location-Abgleich</h2><span class="hint">dasselbe Repository wie in MasterAdmin</span></div>
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
<?php endif; ?>
