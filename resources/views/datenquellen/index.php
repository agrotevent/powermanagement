<?php
use App\Messung\Datenquellen;
use App\Messung\Messdaten;
use App\Messung\MqttZugaenge;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Datenquellen & Geräte';
$subtitle = 'Shelly über MQTT, Victron und ComAp über Modbus TCP (WireGuard) oder Cloud';
$darf = View::can('datenquellen.manage');
$form = $bearbeiten ?? ($neuArt !== '' ? ['id' => 0, 'art' => $neuArt, 'name' => $vorlage['name'], 'geraet' => $vorlage['geraet'], 'host' => '', 'port' => 502,
    'unit_id' => $neuArt === 'modbus' ? 100 : 1, 'profil' => $neuArt === 'shelly' ? $vorlage['profil'] : '', 'extern_id' => $neuArt === 'shelly' ? ($vorlage['praefix'] ?: $vorlage['geraet']) : '', 'intervall_s' => $neuArt === 'modbus' ? 2 : 60,
    'aktiv' => 1, 'notizen' => '', 'zugang_hinterlegt' => false] : null);
?>
<div class="grid grid-2 kennzahlen">
    <div class="card stat-card <?= $pipeline !== null && (time() * 1000 - $pipeline['zeit']) < 120000 ? 'ok' : 'warn' ?>">
        <div class="stat"><span class="label">Node-RED → InfluxDB</span>
            <span class="value"><?= $pipeline !== null ? View::e(Zeit::anzeige(Zeit::iso(intdiv($pipeline['zeit'], 1000)), 'H:i:s')) : '–' ?></span>
            <span class="trend"><?= $pipeline !== null ? 'letzter Herzschlag · ' . (int) $pipeline['fehler'] . ' Schreibfehler · ' . (int) $pipeline['puffer'] . ' im Puffer' : 'kein Herzschlag in der letzten Stunde' ?></span></div>
    </div>
    <div class="card stat-card">
        <div class="stat"><span class="label">Sammler (Modbus, Cloud)</span>
            <span class="value"><?= count(array_filter($quellen, static fn ($q) => (int) $q['aktiv'] === 1 && $q['art'] !== 'shelly')) ?> aktiv</span>
            <span class="trend">schreibt über <?= $ziel === 'mqtt' ? 'MQTT → Node-RED' : 'direkt in die InfluxDB' ?></span></div>
    </div>
</div>

<?php if ($fehler !== null): ?><div class="notice danger"><?= View::e($fehler) ?></div><?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Abgefragte und schaltbare Geräte</h2>
        <span class="spacer"></span>
        <?php if ($darf): ?>
            <div class="btn-row">
                <a class="btn btn-sm" href="/datenquellen?neu=modbus">+ Modbus TCP</a>
                <a class="btn btn-sm" href="/datenquellen?neu=comap_cloud">+ ComAp-Cloud</a>
                <a class="btn btn-sm" href="/datenquellen?neu=victron_vrm">+ Victron VRM</a>
                <a class="btn btn-sm" href="/datenquellen?neu=shelly">+ Shelly schaltbar</a>
            </div>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Name</th><th>Art</th><th>Adresse</th><th>Kennung</th><th>Letzter Kontakt</th><th></th></tr></thead>
            <tbody>
            <?php if ($quellen === []): ?><tr><td colspan="6" class="muted">Noch keine. Shelly-Geräte brauchen keinen Eintrag – außer sie sollen geschaltet werden.</td></tr><?php endif; ?>
            <?php foreach ($quellen as $q): ?>
                <tr>
                    <td data-label="Name"><strong><?= View::e($q['name']) ?></strong><?= (int) $q['aktiv'] === 1 ? '' : ' <span class="badge">aus</span>' ?>
                        <?php if ((string) $q['notizen'] !== ''): ?><div class="small muted"><?= View::e((string) $q['notizen']) ?></div><?php endif; ?></td>
                    <td data-label="Art"><?= View::e(Datenquellen::ARTEN[$q['art']] ?? $q['art']) ?><?= $q['profil'] !== '' ? '<div class="small muted">' . View::e($q['profil']) . '</div>' : '' ?></td>
                    <td data-label="Adresse" class="small mono"><?= $q['art'] === 'modbus' ? View::e($q['host'] . ':' . $q['port'] . ' · Unit ' . $q['unit_id']) : View::e($q['extern_id']) ?></td>
                    <td data-label="Kennung" class="small mono"><?= View::e($q['geraet']) ?></td>
                    <td data-label="Letzter Kontakt" class="small">
                        <?php if ($q['art'] === 'shelly'): ?><span class="muted">meldet sich über MQTT</span>
                            <?php $mqttZugang = MqttZugaenge::nachPraefix((string) $q['extern_id']); ?>
                            <div><a class="badge <?= $mqttZugang === null ? '' : ($mqttZugang['zustand'] === 'eigen' ? 'ok' : 'info') ?>" href="/datenquellen/mqtt-zugaenge#z-<?= View::e(MqttZugaenge::geraetName((string) $q['extern_id'])) ?>"
                                ><?= View::e($mqttZugang === null ? 'gemeinsamer Zugang' : (MqttZugaenge::ZUSTAENDE[$mqttZugang['zustand']] ?? $mqttZugang['zustand'])) ?></a></div>
                        <?php else: ?>
                            <?= View::e(Zeit::anzeige($q['letzter_kontakt'])) ?>
                            <?php if (!empty($q['letzter_fehler'])): ?><div class="text-warn"><?= View::e((string) $q['letzter_fehler']) ?></div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="right nowrap">
                        <?php if ($darf): ?>
                            <?php if ($q['art'] !== 'shelly'): ?>
                                <form method="post" action="/datenquellen/testen" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $q['id'] ?>"><button class="btn btn-sm" type="submit">Jetzt abfragen</button></form>
                            <?php endif; ?>
                            <a class="btn btn-sm" href="/datenquellen?bearbeiten=<?= (int) $q['id'] ?>">Bearbeiten</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($form !== null && $darf): ?>
<?php $art = (string) $form['art']; ?>
<div class="card" id="formular">
    <div class="card-head"><h2><?= (int) $form['id'] > 0 ? View::e($form['name']) . ' bearbeiten' : 'Neue Datenquelle: ' . View::e(Datenquellen::ARTEN[$art] ?? $art) ?></h2></div>
    <form method="post" action="/datenquellen/speichern" class="card-body">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
        <input type="hidden" name="art" value="<?= View::e($art) ?>">
        <div class="grid grid-2">
            <div>
                <div class="field"><label for="dq-name">Name</label><input id="dq-name" name="name" required value="<?= View::e($form['name']) ?>"></div>
                <div class="field"><label for="dq-geraet">Kennung in der InfluxDB</label><input id="dq-geraet" name="geraet" value="<?= View::e($form['geraet']) ?>" placeholder="leer: aus dem Namen">
                    <div class="help">Unter diesem Namen (Tag „geraet“) stehen die Werte in der InfluxDB; Messpunkte an Anlagen beziehen sich darauf.</div></div>
                <?php if ($art === 'modbus'): ?>
                    <div class="field-row">
                        <div class="field"><label for="dq-host">Adresse</label><input id="dq-host" name="host" value="<?= View::e($form['host']) ?>" placeholder="10.20.30.2 (hinter WireGuard)" required></div>
                        <div class="field"><label for="dq-port">Port</label><input id="dq-port" name="port" type="number" value="<?= (int) $form['port'] ?>"></div>
                        <div class="field"><label for="dq-unit">Unit-ID</label><input id="dq-unit" name="unit_id" type="number" value="<?= (int) $form['unit_id'] ?>"></div>
                    </div>
                    <div class="help">Victron GX: Unit-ID des Multi/Quattro (VE.Bus), häufig 227 – die Systemwerte liest das Profil selbst von Unit 100.
                        ComAp: Modbus-Adresse der Steuerung (meist 1).</div>
                    <div class="field mt"><label for="dq-profil">Registerprofil</label>
                        <select id="dq-profil" name="profil" required>
                            <option value="">– wählen –</option>
                            <?php foreach ($profile as $p): ?>
                                <option value="<?= View::e($p['schluessel']) ?>"<?= $form['profil'] === $p['schluessel'] ? ' selected' : '' ?>><?= View::e($p['name']) ?><?= $p['ohne_adresse'] > 0 ? ' (' . (int) $p['ohne_adresse'] . ' ohne Adresse)' : '' ?></option>
                            <?php endforeach; ?>
                        </select></div>
                <?php elseif ($art === 'comap_cloud'): ?>
                    <div class="field"><label for="dq-ext">unitGuid der Steuerung</label><input id="dq-ext" name="extern_id" value="<?= View::e($form['extern_id']) ?>" required>
                        <div class="help">Nach dem ersten Speichern mit Zugangsdaten lassen sich die Einheiten abrufen.</div></div>
                <?php elseif ($art === 'victron_vrm'): ?>
                    <div class="field"><label for="dq-ext">Installations-ID (idSite)</label><input id="dq-ext" name="extern_id" value="<?= View::e($form['extern_id']) ?>" required>
                        <div class="help">Steht im VRM-Portal in der Adresse der Installation.</div></div>
                <?php else: ?>
                    <div class="field"><label for="dq-ext">MQTT-Präfix des Shelly</label><input id="dq-ext" name="extern_id" value="<?= View::e($form['extern_id']) ?>" required placeholder="shellypro4pm-a0b1c2d3e4f5"></div>
                    <div class="field"><label for="dq-gen">Generation</label>
                        <select id="dq-gen" name="profil"><option value="gen2"<?= $form['profil'] !== 'gen1' ? ' selected' : '' ?>>Gen2+/Plus/Pro (RPC)</option><option value="gen1"<?= $form['profil'] === 'gen1' ? ' selected' : '' ?>>Gen1 (shellies/…)</option></select></div>
                <?php endif; ?>
            </div>
            <div>
                <?php if ($art !== 'shelly'): ?>
                    <div class="field"><label for="dq-int">Abfrageintervall (Sekunden)</label><input id="dq-int" name="intervall_s" type="number" min="1" value="<?= (int) $form['intervall_s'] ?>">
                        <div class="help"><?= $art === 'modbus' ? 'Modbus über WireGuard: 1–5 s ergeben Livewerte.' : 'Clouds: nicht unter 60 s – sie aktualisieren selbst nur im Minutentakt.' ?></div></div>
                <?php else: ?><input type="hidden" name="intervall_s" value="60"><?php endif; ?>
                <?php if ($art === 'comap_cloud'): ?>
                    <p class="small muted">Zugangsdaten werden verschlüsselt abgelegt und nie wieder angezeigt<?= $form['zugang_hinterlegt'] ? ' (hinterlegt – leer lassen, um sie zu behalten)' : '' ?>.</p>
                    <div class="field"><label>ComAp-Key (Developer-Portal)</label><input name="zugang[comap_key]" autocomplete="off"></div>
                    <div class="field-row">
                        <div class="field"><label>clientId</label><input name="zugang[client_id]" autocomplete="off"></div>
                        <div class="field"><label>secret</label><input name="zugang[secret]" type="password" autocomplete="new-password"></div>
                    </div>
                    <div class="field"><label>Login-ID (WebSupervisor-Benutzer)</label><input name="zugang[login_id]" autocomplete="off"></div>
                <?php elseif ($art === 'victron_vrm'): ?>
                    <p class="small muted">Zugangstoken aus VRM → Einstellungen → Integrationen. Verschlüsselt abgelegt<?= $form['zugang_hinterlegt'] ? ' (hinterlegt)' : '' ?>.</p>
                    <div class="field"><label>Zugangstoken</label><input name="zugang[token]" type="password" autocomplete="new-password"></div>
                <?php endif; ?>
                <label class="check"><input type="checkbox" name="aktiv" value="1"<?= (int) $form['aktiv'] === 1 ? ' checked' : '' ?>> aktiv</label>
                <div class="field"><label for="dq-notiz">Notiz</label><textarea id="dq-notiz" name="notizen" rows="2"><?= View::e((string) $form['notizen']) ?></textarea></div>
            </div>
        </div>
        <div class="btn-row">
            <button class="btn btn-primary" type="submit">Speichern</button>
            <a class="btn btn-ghost" href="/datenquellen">Abbrechen</a>
        </div>
    </form>
    <?php if ((int) $form['id'] > 0): ?>
        <div class="card-footer btn-row">
            <?php if ($art === 'comap_cloud'): ?>
                <form method="post" action="/datenquellen/comap-einheiten"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $form['id'] ?>"><button class="btn btn-sm" type="submit">Einheiten in WebSupervisor abrufen</button></form>
            <?php endif; ?>
            <form method="post" action="/datenquellen/loeschen" data-confirm="Datenquelle löschen? Die Messwerte in der InfluxDB bleiben."><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $form['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">Löschen</button></form>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Geräte in der InfluxDB</h2><span class="hint">Shelly melden sich von selbst über MQTT/Node-RED</span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Gerät</th><th>Kanal</th><th>Typ</th><th class="num">Leistung</th><th>Zuletzt</th><th>Zugeordnet</th><th></th></tr></thead>
            <tbody>
            <?php if ($geraete === []): ?><tr><td colspan="7" class="muted">Noch keine Messwerte. Shelly an den MQTT-Broker hängen (siehe Installationsanleitung) – dann erscheinen sie hier.</td></tr><?php endif; ?>
            <?php foreach ($geraete as $g): ?>
                <?php $zu = $zugeordnet[$g['geraet'] . '|' . $g['kanal']] ?? []; ?>
                <tr>
                    <td data-label="Gerät"><span class="mono"><?= View::e($g['geraet']) ?></span><?= $g['name'] !== '' ? '<div class="small muted">' . View::e($g['name'] . ($g['modell'] !== '' ? ' · ' . $g['modell'] : '')) . '</div>' : '' ?></td>
                    <td data-label="Kanal" class="mono"><?= View::e($g['kanal']) ?></td>
                    <td data-label="Typ"><?= View::e($g['typ']) ?></td>
                    <td data-label="Leistung" class="num"><?= View::e(Messdaten::format($g['p'], 'W')) ?></td>
                    <td data-label="Zuletzt" class="small"><?= $g['zuletzt'] !== null ? View::e(Zeit::anzeige(Zeit::iso(intdiv((int) $g['zuletzt'], 1000)))) : '–' ?></td>
                    <td data-label="Zugeordnet" class="small"><?= $zu === [] ? '<span class="muted">keiner Anlage</span>' : implode(', ', array_map(static fn ($z) => '<a href="/anlagen/' . $z['id'] . '?reiter=messung">' . View::e($z['name']) . '</a>', $zu)) ?></td>
                    <td class="right">
                        <?php if ($darf && $g['typ'] === 'shelly' && preg_match('/^(switch|relay):/', $g['kanal']) === 1 && Datenquellen::nachGeraet($g['geraet']) === null): ?>
                            <a class="btn btn-sm" href="/datenquellen?neu=shelly&amp;geraet=<?= rawurlencode($g['geraet']) ?>&amp;name=<?= rawurlencode($g['name'] ?: $g['geraet']) ?>&amp;praefix=<?= rawurlencode((string) ($g['praefix'] ?? '')) ?>&amp;profil=<?= (int) ($g['generation'] ?? 0) === 1 ? 'gen1' : 'gen2' ?>#formular">schaltbar machen</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
