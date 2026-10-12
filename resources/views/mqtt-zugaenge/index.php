<?php
use App\Messung\MqttZugaenge;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'MQTT-Zugänge der Shellys';
$subtitle = 'Gemeinsamer Zugang „shelly“ und eigene Zugänge – Gerät für Gerät umstellen';
$badge = [
    'gemeinsam'   => '',
    'angelegt'    => 'warn',
    'uebertragen' => 'info',
    'pruefung'    => 'warn',
    'eigen'       => 'ok',
];
// Im Demo-Modus stehen die Knöpfe da (für Doku und Vorführung) – sie
// melden nur, dass am Broker nichts verändert wird.
$aktiv = $darf && ($grund === null || \App\Support\Config::isDemo());
?>
<div class="card">
    <div class="card-body">
        <p>Alle Shellys melden sich bisher mit dem <strong>gemeinsamen Zugang</strong> <span class="mono">shelly</span> am Broker an.
            Wer dieses Kennwort aus einem Gerät ausliest, kann damit jedes andere Gerät schalten und mitlesen. Ein <strong>eigener Zugang</strong>
            darf nur den Themenbaum seines Geräts – und sobald die Umstellung bestätigt ist, kann der gemeinsame Zugang dieses Gerät weder schalten noch mitlesen.
            Der gemeinsame Zugang bleibt für alle anderen Geräte, bis jedes umgestellt ist.</p>
        <ol class="small mb0">
            <li><strong>Gen2+</strong> (Plus, Pro, Gen3/4): „Eigenen Zugang einrichten“ legt ihn am Broker an und überträgt ihn ans Gerät.
                Wirksam wird er mit dem <strong>nächsten Neustart</strong> des Geräts (Weboberfläche oder Strom aus/ein) – die App startet nie selbst neu.
                Bis dahin gelten beide Zugänge. Die Übertragung läuft noch über den gemeinsamen Zugang; wer das ausschließen will, nimmt „von Hand“.</li>
            <li><strong>Gen1</strong>: Benutzer und Kennwort werden einmal angezeigt und am Gerät eingetragen (Internet &amp; Security → Advanced Developer Settings).</li>
            <li><strong>Umstellung prüfen</strong>: der gemeinsame Zugang wird für bis zu <?= (int) MqttZugaenge::PRUEFUNG_SEKUNDEN[2] ?> s (Gen1: <?= (int) MqttZugaenge::PRUEFUNG_SEKUNDEN[1] ?> s)
                auf dieses Gerät gesperrt. Antwortet es trotzdem, nutzt es den eigenen Zugang, und die Sperre bleibt. Sonst fällt sie sofort weg.</li>
        </ol>
    </div>
</div>

<?php if ($grund !== null): ?><div class="notice warn"><?= View::e($grund) ?></div><?php endif; ?>
<?php if ($grund === null && $hindernis !== null): ?><div class="notice warn">Einrichten geht, bestätigen nicht: <?= View::e($hindernis) ?></div><?php endif; ?>
<?php if ($fehler !== null): ?><div class="notice danger">Geräteliste aus der InfluxDB: <?= View::e($fehler) ?></div><?php endif; ?>
<?php if ($schutz !== null && !$schutz['aktuell']): ?>
    <div class="notice warn">Node-RED hat die Liste der umgestellten Geräte nicht bestätigt. Dort laufen vermutlich Flows ohne Schutz vor Doppelgänger-Präfixen
        (etwa von Hand geänderte, beim Update behaltene). Die Flows aktualisieren – siehe docs/installation-debian-lxc.md.</div>
<?php endif; ?>
<?php $ungeschuetzt = array_filter($liste, static fn (array $z): bool => $z['zustand'] === 'eigen' && !$z['geschuetzt']); ?>
<?php if ($ungeschuetzt !== []): ?>
    <div class="notice danger">Bei <?= count($ungeschuetzt) ?> bestätigten Gerät(en) ist der gemeinsame Zugang am Broker gerade <strong>nicht</strong> gesperrt.
        „Prüfen“ stellt die Sperre wieder her, sobald der Broker-Abgleich arbeitet.</div>
<?php endif; ?>
<?php if ($ergebnis !== null && $ergebnis['fehler'] !== []): ?>
    <div class="notice warn">Der Broker-Abgleich meldet: <?= View::e(MqttZugaenge::fehlerText($ergebnis['fehler'])) ?></div>
<?php endif; ?>

<div class="grid grid-4 kennzahlen">
    <div class="card stat-card"><div class="stat"><span class="label">Gemeinsamer Zugang</span><span class="value"><?= (int) $zaehler['gemeinsam'] ?></span><span class="trend">umstellbar</span></div></div>
    <div class="card stat-card"><div class="stat"><span class="label">In Umstellung</span><span class="value"><?= (int) $zaehler['wartet'] ?></span><span class="trend">angelegt oder wartet auf Neustart</span></div></div>
    <div class="card stat-card ok"><div class="stat"><span class="label">Eigener Zugang</span><span class="value"><?= (int) $zaehler['eigen'] ?></span><span class="trend">bestätigt, gemeinsamer gesperrt</span></div></div>
    <div class="card stat-card"><div class="stat"><span class="label">Broker-Abgleich</span>
        <span class="value"><?= $ergebnis === null ? '–' : View::e($ergebnis['status'] === 'unveraendert' || $ergebnis['status'] === 'ok' ? 'bereit' : $ergebnis['status']) ?></span>
        <span class="trend"><?= $ergebnis === null ? 'nicht eingerichtet' : 'zuletzt ' . View::e(Zeit::anzeige(Zeit::iso($ergebnis['zeit']), 'd.m. H:i')) . ($ergebnis['lauf'] !== null ? ' · Lauf ' . (int) $ergebnis['lauf'] : '') ?></span></div></div>
</div>

<nav class="tabs tabs-umbruch" aria-label="Filter">
    <?php foreach (['' => 'Alle', 'gemeinsam' => 'Gemeinsam', 'wartet' => 'In Umstellung', 'eigen' => 'Eigen', 'nicht' => 'Nicht umstellbar'] as $k => $n): ?>
        <a class="tab<?= $filter === $k ? ' active' : '' ?>" href="?filter=<?= $k ?>"><?= $n ?><?= $k !== '' ? ' (' . (int) $zaehler[$k] . ')' : '' ?></a>
    <?php endforeach; ?>
</nav>

<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Gerät</th><th>Präfix / Benutzer</th><th>Zugang</th><th>Seit</th><th></th></tr></thead>
            <tbody>
            <?php if ($liste === []): ?>
                <tr><td colspan="5" class="muted">Keine Shellys in dieser Ansicht. Geräte erscheinen, sobald Node-RED ihr MQTT-Präfix gemeldet hat oder sie als schaltbare Datenquelle eingetragen sind.</td></tr>
            <?php endif; ?>
            <?php foreach ($liste as $z): ?>
                <?php $zugang = $z['zugang']; $id = $zugang !== null ? (int) $zugang['id'] : 0; ?>
                <tr id="z-<?= View::e($z['geraet']) ?>">
                    <td data-label="Gerät">
                        <strong><?= View::e($z['name'] !== '' ? $z['name'] : $z['geraet']) ?></strong>
                        <div class="small muted">Gen<?= (int) $z['generation'] === 1 ? '1' : '2+' ?><?= $z['zuletzt'] !== null ? ' · Daten ' . View::e(Zeit::anzeige(Zeit::iso(intdiv((int) $z['zuletzt'], 1000)), 'd.m. H:i')) : '' ?></div>
                    </td>
                    <td data-label="Präfix / Benutzer" class="small">
                        <span class="mono"><?= View::e($z['praefix']) ?></span>
                        <?php if ($z['benutzer'] !== null && $z['benutzer'] !== $z['praefix']): ?><div class="muted">Benutzer <span class="mono"><?= View::e($z['benutzer']) ?></span></div><?php endif; ?>
                    </td>
                    <td data-label="Zugang">
                        <span class="badge <?= View::e($badge[$z['zustand']] ?? '') ?>"><?= View::e(MqttZugaenge::ZUSTAENDE[$z['zustand']] ?? $z['zustand']) ?></span>
                        <?php if ($zugang !== null && (int) $zugang['neustart_noetig'] === 1 && $z['zustand'] === 'eigen'): ?><span class="badge warn">neues Kennwort eintragen</span><?php endif; ?>
                        <?php if ($z['zustand'] === 'eigen' && !$z['geschuetzt']): ?><span class="badge bad">nicht gesperrt</span><?php endif; ?>
                        <?php if ($z['doppelt']): ?><span class="badge warn" title="Mehrere Präfixe mit demselben Gerätenamen">Doppelgänger?</span><?php endif; ?>
                        <?php if ($zugang !== null && (string) $zugang['meldung'] !== ''): ?><div class="small muted"><?= View::e((string) $zugang['meldung']) ?></div><?php endif; ?>
                        <?php if (!$z['umstellbar'] && $zugang === null): ?><div class="small muted"><?= View::e($z['grund']) ?></div><?php endif; ?>
                    </td>
                    <td data-label="Seit" class="small">
                        <?php if ($zugang !== null): ?>
                            <?= View::e(Zeit::anzeige((string) ($zugang['bestaetigt_am'] ?? $zugang['uebertragen_am'] ?? $zugang['created_at']))) ?>
                        <?php else: ?><span class="muted">–</span><?php endif; ?>
                    </td>
                    <td class="right">
                        <?php if ($aktiv): ?>
                        <div class="btn-row">
                            <?php if ($zugang === null && $z['umstellbar']): ?>
                                <form method="post" action="/datenquellen/mqtt-zugaenge/einrichten" data-laeuft="richtet ein …"
                                      data-confirm="<?= View::e((int) $z['generation'] === 1
                                          ? 'Eigenen Zugang für ' . $z['praefix'] . ' anlegen? Benutzer und Kennwort werden einmal angezeigt.'
                                          : 'Eigenen Zugang für ' . $z['praefix'] . ' anlegen und ans Gerät übertragen? Wirksam ab dem nächsten Neustart des Geräts.') ?>">
                                    <?= Csrf::field() ?><input type="hidden" name="praefix" value="<?= View::e($z['praefix']) ?>"><input type="hidden" name="generation" value="<?= (int) $z['generation'] ?>">
                                    <button class="btn btn-sm btn-primary" type="submit">Eigenen Zugang einrichten</button>
                                </form>
                                <?php if ((int) $z['generation'] === 2): ?>
                                    <form method="post" action="/datenquellen/mqtt-zugaenge/einrichten" data-laeuft="richtet ein …"
                                          data-confirm="<?= View::e('Eigenen Zugang für ' . $z['praefix'] . ' anlegen, ohne ihn per MQTT zu übertragen? Benutzer und Kennwort werden einmal angezeigt und in der Weboberfläche des Shelly eingetragen.') ?>">
                                        <?= Csrf::field() ?><input type="hidden" name="praefix" value="<?= View::e($z['praefix']) ?>"><input type="hidden" name="generation" value="2"><input type="hidden" name="von_hand" value="1">
                                        <button class="btn btn-sm btn-ghost" type="submit" title="Kennwort anzeigen und am Gerät eintragen – geht nicht über MQTT">von Hand</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($zugang !== null && (int) $z['generation'] === 2 && in_array($z['zustand'], ['angelegt', 'uebertragen'], true)): ?>
                                <form method="post" action="/datenquellen/mqtt-zugaenge/uebertragen" data-laeuft="überträgt …">
                                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="btn btn-sm<?= $z['zustand'] === 'angelegt' ? ' btn-primary' : '' ?>" type="submit"><?= $z['zustand'] === 'angelegt' ? 'Übertragen' : 'Erneut übertragen' ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($zugang !== null && $z['zustand'] !== 'pruefung'): ?>
                                <form method="post" action="/datenquellen/mqtt-zugaenge/pruefen" data-laeuft="prüft …" data-laeuft-hinweis="Das dauert bis zu einer halben Minute.">
                                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="btn btn-sm<?= $z['zustand'] !== 'eigen' ? ' btn-primary' : '' ?>" type="submit"><?= $z['zustand'] === 'eigen' ? 'Prüfen' : 'Umstellung prüfen' ?></button>
                                </form>
                                <?php if ($z['zustand'] === 'uebertragen'): ?>
                                    <form method="post" action="/datenquellen/mqtt-zugaenge/pruefen" data-laeuft="prüft …"
                                          data-confirm="Trotzdem prüfen, obwohl der Shelly seit der Übertragung nicht neu gestartet ist? Der gemeinsame Zugang wird dafür bis zu <?= (int) MqttZugaenge::PRUEFUNG_SEKUNDEN[2] ?> s auf dieses Gerät gesperrt.">
                                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="erzwingen" value="1">
                                        <button class="btn btn-sm btn-ghost" type="submit" title="Ohne Neustart-Prüfung">ohne Neustart</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($zugang !== null && in_array($z['zustand'], ['angelegt', 'eigen'], true)): ?>
                                <form method="post" action="/datenquellen/mqtt-zugaenge/erneuern" data-laeuft="erneuert …"
                                      data-confirm="<?= View::e('Neues Kennwort für ' . $z['praefix'] . '? Es wird einmal angezeigt und am Gerät eingetragen. Bis dahin ist das Gerät getrennt.') ?>">
                                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit">Neues Kennwort</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($zugang !== null && $z['zustand'] !== 'pruefung'): ?>
                                <form method="post" action="/datenquellen/mqtt-zugaenge/entfernen" data-laeuft="entfernt …"
                                      data-confirm="Eigenen Zugang für <?= View::e($z['praefix']) ?> entfernen? Nutzt das Gerät ihn schon, ist es sofort getrennt – dort dann wieder den gemeinsamen Zugang eintragen.">
                                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit">Entfernen</button>
                                </form>
                                <?php if ((int) $z['generation'] === 2 && $z['zustand'] !== 'eigen'): ?>
                                    <form method="post" action="/datenquellen/mqtt-zugaenge/entfernen" data-laeuft="entfernt …"
                                          data-confirm="Entfernen erzwingen? Hat der Shelly den eigenen Zugang schon gespeichert, verliert er beim nächsten Neustart die Verbindung – vorher in seiner Weboberfläche wieder den gemeinsamen Zugang eintragen.">
                                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="erzwingen" value="1">
                                        <button class="btn btn-sm btn-ghost" type="submit" title="Ohne das Gerät zu fragen">erzwingen</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="small muted">Wird ein Gerät mit eigenem Zugang ersetzt (gleiches Präfix, neues Gerät): „Neues Kennwort“ und am neuen Gerät eintragen – oder den Zugang entfernen, dann gilt dort wieder der gemeinsame.
    Ein neues Kennwort wird immer angezeigt, nie per MQTT übertragen: wer das bisherige Kennwort kennt, könnte dort mitlesen.</p>
