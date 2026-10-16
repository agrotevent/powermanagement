<?php
/**
 * Eine eingetragene Instanz: ihre Locations mit Zustand und den Wegen in
 * beide Richtungen.
 *
 * Übernehmen gibt es immer (mit „plaene.lesen“); bei „hier geändert“ und
 * „beide geändert“ mit Rückfrage. Hochladen nur, wenn das Token dort
 * „plaene.schreiben“ hat und mindestens eine verknüpfte Etage dort
 * bearbeitet werden darf – bei „hier geändert“, bei „beide geändert“ nur
 * mit Rückfrage (überschreibt dort). Mit nur „plaene.vorschlagen“: als
 * Vorschlag schicken, den dort jemand bestätigt.
 *
 * Alles von der Gegenseite (Namen, Schlüssel, Meldungen) geht durch View::e().
 *
 * @var array<string,mixed> $partner PartnerService::find()
 * @var array{ok:bool,message:string,locations:list<array<string,mixed>>,uebergangen:int} $locations
 * @var bool $planen „Standorte, Etagen und Grundrisse pflegen“
 */
use App\Services\PartnerAbgleich;
use App\Services\PartnerService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = (string) $partner['name'];
$subtitle = 'Andere Instanz · ' . PartnerService::produktName((string) $partner['remote_produkt']) . ' · ' . (string) $partner['base_url'];
$basis = '/instanzen/' . (int) $partner['id'];
$schreiben = PartnerService::darfDort($partner, 'plaene.schreiben');
$vorschlagen = PartnerService::darfDort($partner, 'plaene.vorschlagen');
?>
<p><a href="/instanzen">← alle Instanzen</a></p>

<div class="card">
    <div class="card-body">
        <dl class="zugangsdaten">
            <dt>Adresse</dt>
            <dd><span class="mono api-adresse"><?= View::e((string) $partner['base_url']) ?></span>
                <?php if ($partner['unverschluesselt']): ?><span class="badge warn">unverschlüsselt</span>
                    <span class="small muted">http über ein Netz – Token und Pläne gehen lesbar hinüber. Besser https.</span><?php endif; ?></dd>
            <dt>Gegenseite</dt>
            <dd><?= View::e(PartnerService::produktName((string) $partner['remote_produkt'])) ?>
                <?php if ((string) $partner['remote_name'] !== ''): ?> · nennt sich „<?= View::e((string) $partner['remote_name']) ?>“<?php endif; ?></dd>
            <dt>Befugnisse dort</dt>
            <dd class="small"><?= $partner['befugnisse'] === [] ? '<span class="muted">–</span>'
                : implode(', ', array_map(static fn (string $b): string => '<code>' . View::e($b) . '</code>', $partner['befugnisse'])) ?></dd>
            <dt>Geprüft</dt>
            <dd class="small"><?= (string) ($partner['verified_at'] ?? '') !== '' ? View::e(Zeit::anzeige((string) $partner['verified_at'])) : 'nie' ?>
                · Token <span class="mono"><?= View::e((string) $partner['token_prefix']) ?>…</span>
                <?php if ((string) $partner['letzter_fehler'] !== ''): ?><div class="notice warn mt"><?= View::e((string) $partner['letzter_fehler']) ?></div><?php endif; ?></dd>
        </dl>
    </div>
    <div class="card-footer btn-row">
        <form method="post" action="<?= View::e($basis) ?>/pruefen">
            <?= Csrf::field() ?><button class="btn btn-sm" type="submit">Verbindung prüfen</button>
        </form>
        <details class="api-zurueck">
            <summary class="btn btn-sm btn-ghost">Neues Token eintragen</summary>
            <form method="post" action="<?= View::e($basis) ?>/pruefen" class="api-neues-token" autocomplete="off">
                <?= Csrf::field() ?>
                <label for="partner-neues-token" class="small">Neues Token von dort – wird nur übernommen, wenn die Gegenseite es annimmt.</label>
                <input type="password" id="partner-neues-token" name="token" required autocomplete="off" spellcheck="false" placeholder="mtma_…">
                <button class="btn btn-sm btn-primary" type="submit">Prüfen und ersetzen</button>
            </form>
        </details>
        <form method="post" action="<?= View::e($basis) ?>/loeschen"
              data-confirm="<?= View::e('„' . $partner['name'] . '“ austragen? Übernommene Standorte bleiben, wie sie sind – sie werden nur nicht mehr abgeglichen.') ?>">
            <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Austragen</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Locations dort</h2>
        <span class="hint">Übernehmen holt den Stand von dort; Namen, Schaltsperren und Messpunkte hier bleiben, gelöscht wird nichts.</span></div>
    <?php if (!$locations['ok']): ?>
        <div class="card-body"><div class="notice warn"><?= View::e($locations['message']) ?></div></div>
    <?php else: ?>
        <?php if ($locations['uebergangen'] > 0): ?>
            <div class="card-body"><div class="notice"><?= (int) $locations['uebergangen'] ?> Location(s) dort mit ungültigem Schlüssel übergangen.</div></div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="tabelle-karten api-dort">
                <thead><tr><th>Location dort</th><th>Etagen dort → hier</th><th>Zustand</th><th></th></tr></thead>
                <tbody>
                <?php if ($locations['locations'] === []): ?><tr><td colspan="4" class="muted">Dort ist diesem Token keine Location freigegeben.</td></tr><?php endif; ?>
                <?php foreach ($locations['locations'] as $l): ?>
                    <?php
                    $z = (string) $l['zustand'];
                    [$zText, $zKlasse] = PartnerAbgleich::ZUSTAENDE[$z] ?? [$z, ''];
                    $hier = $l['hier'];
                    $siteId = (int) ($hier['id'] ?? 0);
                    $zuordnen = $basis . '/zuordnen?schluessel=' . rawurlencode((string) $l['schluessel']);
                    $zurueckBereit = $hier !== null && $l['sichtbar'] && $l['aendern'] && (int) $l['bearbeitbar'] > 0
                        && in_array($z, ['hier', 'beide', 'verknuepft'], true);
                    $uebernehmenFrage = match ($z) {
                        'hier', 'beide' => 'Änderungen hier auf den verknüpften Etagen werden überschrieben'
                            . ($zurueckBereit ? ' – vorher zurückschicken, wenn sie dort ankommen sollen.' : '.'),
                        'verknuepft'    => 'Die verknüpften Etagen hier bekommen den Stand von dort (Plan, Maßstab, Georeferenz'
                            . ($l['feststrom'] ? ', Feststrom' : '') . ').',
                        'passt'         => 'Mit „' . (string) ($l['vorschlag']['name'] ?? '') . '“ hier verknüpfen und den Stand von dort übernehmen? '
                            . 'Etagen mit gleichem Schlüssel oder Namen bekommen Plan, Maßstab und Georeferenz von dort, die übrigen kommen neu dazu; Namen hier bleiben.',
                        default         => '',
                    };
                    ?>
                    <tr>
                        <td data-label="Location dort"><strong><?= View::e((string) $l['name']) ?></strong>
                            <div class="small muted mono"><?= View::e((string) $l['schluessel']) ?></div></td>
                        <td data-label="Etagen" class="small api-etagen">
                            <?php foreach ($l['etagen'] as $e): ?>
                                <?php $ziel = $e['ziel']; ?>
                                <div class="api-etage">
                                    <span><?= View::e((string) $e['name']) ?><?= $e['bearbeiten'] ? ' <span class="badge">bearbeiten</span>' : '' ?></span>
                                    <span class="muted"><?= $e['plan'] ? 'Plan' : 'ohne Plan' ?><?= $e['anlagen'] !== null ? ' · ' . View::e(PartnerAbgleich::feststromText((int) $e['anlagen'], (int) $e['leitungen'])) : '' ?></span>
                                    <span class="api-ziel">→
                                        <?php if ($ziel === null): ?>
                                            <span class="muted"><?= $hier !== null ? 'noch nicht hier – über Zuordnen' : 'neue Etage' ?></span>
                                        <?php else: ?>
                                            <?= View::e((string) $ziel['name']) ?> <span class="muted">(<?= View::e(PartnerAbgleich::GRUENDE[$ziel['grund']] ?? '') ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($l['etagen'] === []): ?><span class="muted">keine Etagen</span><?php endif; ?>
                        </td>
                        <td data-label="Zustand">
                            <span class="badge <?= View::e($zKlasse) ?>"><?= View::e($z === 'passt' ? 'passt zu' : $zText) ?></span>
                            <?php if ($hier !== null): ?>
                                <div class="small"><?= $l['sichtbar'] ? 'verknüpft mit <strong>' . View::e((string) $hier['name']) . '</strong>'
                                    : '<span class="muted">verknüpft mit einem Standort, den du nicht siehst</span>' ?></div>
                            <?php elseif ($l['vorschlag'] !== null): ?>
                                <div class="small"><strong><?= View::e((string) $l['vorschlag']['name']) ?></strong>
                                    <span class="muted">(<?= View::e(PartnerAbgleich::GRUENDE[$l['vorschlag']['grund']] ?? '') ?>)</span></div>
                            <?php else: ?>
                                <div class="small muted">wird als neuer Standort angelegt</div>
                            <?php endif; ?>
                            <?php if ($l['git']): ?><div class="small muted">auch im Location-Abgleich (Git) – zwei Quellen</div><?php endif; ?>
                        </td>
                        <td class="right">
                            <div class="btn-row api-aktionen">
                                <?php if ($l['aendern'] && $l['sichtbar']): ?>
                                    <form method="post" action="<?= View::e($basis) ?>/uebernehmen"<?= $uebernehmenFrage !== '' ? ' data-confirm="' . View::e($uebernehmenFrage) . '"' : '' ?>>
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="schluessel" value="<?= View::e((string) $l['schluessel']) ?>">
                                        <input type="hidden" name="aktion" value="uebernehmen">
                                        <?php if ($l['feststrom']): ?><input type="hidden" name="feststrom" value="1"><?php endif; ?>
                                        <button class="btn btn-sm<?= in_array($z, ['neu', 'passt', 'dort'], true) ? ' btn-primary' : '' ?>" type="submit"
                                                title="<?= View::e($l['feststrom'] ? 'Grundrisse, Maßstab, Georeferenz und Feststrom' : 'Grundrisse, Maßstab und Georeferenz') ?>">Übernehmen</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($planen && $l['sichtbar']): ?>
                                    <a class="btn btn-sm" href="<?= View::e($zuordnen) ?>">Zuordnen …</a>
                                <?php endif; ?>
                                <?php if ($zurueckBereit && $schreiben): ?>
                                    <?php $erzwingen = $z !== 'hier'; ?>
                                    <form method="post" action="<?= View::e($basis) ?>/zurueck"<?= $erzwingen ? ' data-confirm="' . View::e($z === 'beide'
                                        ? 'Dort hat sich seit dem letzten Übernehmen auch etwas geändert. Hochladen überschreibt die Änderungen dort.'
                                        : 'Hier wurde noch nichts von dort übernommen. Hochladen überschreibt den Stand dort auf den verknüpften Etagen.') . '"' : '' ?>>
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="site_id" value="<?= $siteId ?>">
                                        <?php if ($erzwingen): ?><input type="hidden" name="erzwingen" value="1"><?php endif; ?>
                                        <button class="btn btn-sm<?= $z === 'hier' ? ' btn-primary' : '' ?>" type="submit"
                                                title="Geht für <?= (int) $l['bearbeitbar'] ?> verknüpfte Etage(n), die dort bearbeitet werden dürfen – ohne Bestätigung dort">Hochladen</button>
                                    </form>
                                <?php elseif ($zurueckBereit && $vorschlagen): ?>
                                    <details class="api-zurueck">
                                        <summary class="btn btn-sm">Änderungen zurückschicken</summary>
                                        <form method="post" action="<?= View::e($basis) ?>/zurueck">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="site_id" value="<?= $siteId ?>">
                                            <label class="small" for="zurueck-notiz-<?= $siteId ?>">Was geändert wurde und warum</label>
                                            <textarea id="zurueck-notiz-<?= $siteId ?>" name="notiz" rows="2" maxlength="2000"></textarea>
                                            <button class="btn btn-sm btn-primary" type="submit">Als Vorschlag schicken</button>
                                            <p class="small muted">Geht für <?= (int) $l['bearbeitbar'] ?> verknüpfte Etage(n), die dort bearbeitet werden dürfen.
                                                Übernommen wird es dort erst, wenn es jemand bestätigt.</p>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <?php if ($hier !== null && $l['loesen'] && $l['sichtbar']): ?>
                                    <form method="post" action="<?= View::e($basis) ?>/loesen"
                                          data-confirm="<?= View::e('Verknüpfung von „' . $hier['name'] . '“ lösen? Der Standort bleibt, wie er ist – er wird nur nicht mehr abgeglichen.') ?>">
                                        <?= Csrf::field() ?><input type="hidden" name="site_id" value="<?= $siteId ?>">
                                        <button class="btn btn-sm btn-ghost" type="submit">Lösen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if (!$l['aendern'] && $l['sichtbar']): ?>
                                <div class="small muted">Übernehmen nur mit „bearbeiten“ am Standort<?= $planen ? '' : ' und dem Recht, Standorte zu pflegen' ?>.</div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
