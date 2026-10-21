<?php
/**
 * Eine Location von dort einem Standort hier zuordnen – je Etage.
 *
 * Erst der Standort hier (ein vorhandener, den man ändern darf, oder ein
 * neuer), dann je Etage dort die Etage hier, eine neue oder keine. Danach
 * übernehmen – oder nur verknüpfen: dann bleibt der Bestand hier, wie er
 * ist, und lässt sich später auf den Stand von dort bringen oder
 * zurückschicken.
 *
 * „Übernehmen“ fragt wie in der Liste nach, wenn es hier etwas überschreibt
 * (hier oder beide geändert, ein vorhandener Standort) – „Nur verknüpfen“
 * ändert nichts und fragt nicht.
 *
 * @var array<string,mixed> $partner
 * @var array{schluessel:string,name:string} $location
 * @var string $zustand                      PartnerAbgleich::ZUSTAENDE
 * @var string $rueckfrage                   vor „Übernehmen“ ('' = keine)
 * @var array<string,mixed>|null $hier       verknüpfter Standort
 * @var array<string,mixed>|null $vorschlag  passender Standort
 * @var int $ziel                            gewählter Standort hier, 0 = neu
 * @var bool $neu_erlaubt
 * @var list<array<string,mixed>> $standorte wählbare Standorte
 * @var list<array<string,mixed>> $etagen    Etagen dort mit Vorschlag hier
 * @var list<array<string,mixed>> $zielEtagen Etagen des gewählten Standorts
 * @var bool $feststrom                      Feststrom übernehmen anbieten
 * @var list<string> $stecker_fehlen
 * @var bool $darf_stecker
 * @var bool $darf_planen
 */
use App\Services\PartnerAbgleich;
use App\Support\Csrf;
use App\Support\View;

$title = 'Zuordnen: ' . $location['name'];
$subtitle = 'von „' . (string) $partner['name'] . '“ – welcher Standort und welche Etagen hier dazugehören';
$basis = '/instanzen/' . (int) $partner['id'];
?>
<p><a href="<?= View::e($basis) ?>">← zurück zu <?= View::e((string) $partner['name']) ?></a></p>

<div class="card">
    <div class="card-head"><h2>Standort hier</h2>
        <?php if ($hier !== null): ?>
            <?php [$zText, $zKlasse] = PartnerAbgleich::ZUSTAENDE[$zustand] ?? [$zustand, '']; ?>
            <span class="badge <?= View::e($zKlasse) ?>"><?= View::e($zText) ?></span>
        <?php endif; ?></div>
    <div class="card-body">
        <form method="get" action="<?= View::e($basis) ?>/zuordnen">
            <input type="hidden" name="schluessel" value="<?= View::e($location['schluessel']) ?>">
            <div class="field">
                <label for="zuordnen-ziel">„<?= View::e($location['name']) ?>“ gehört hier zu</label>
                <select id="zuordnen-ziel" name="ziel" data-absenden<?= $hier !== null ? ' disabled' : '' ?>>
                    <?php if ($neu_erlaubt): ?>
                        <option value="neu"<?= $ziel === 0 ? ' selected' : '' ?>>– neuer Standort „<?= View::e($location['name']) ?>“ –</option>
                    <?php endif; ?>
                    <?php foreach ($standorte as $s): ?>
                        <?php
                        $merk = '';
                        if ($hier !== null && (int) $s['id'] === (int) $hier['id']) {
                            $merk = ' (verknüpft)';
                        } elseif ($vorschlag !== null && (int) $s['id'] === (int) $vorschlag['id']) {
                            $merk = ' (passt: ' . (PartnerAbgleich::GRUENDE[$vorschlag['grund']] ?? '') . ')';
                        }
                        ?>
                        <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $ziel ? ' selected' : '' ?>><?= View::e((string) $s['name'] . $merk) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="help"><?= $hier !== null
                    ? 'Diese Location ist schon verknüpft. Ein anderer Standort geht erst nach „Lösen“ in der Liste.'
                    : 'Angeboten werden Standorte, die du ändern darfst und die noch mit keiner Location einer anderen Instanz verknüpft sind.' ?></span>
            </div>
            <noscript><button class="btn btn-sm" type="submit">Etagen dazu zeigen</button></noscript>
        </form>
    </div>
</div>

<form method="post" action="<?= View::e($basis) ?>/uebernehmen" class="card" data-laeuft="läuft …" data-laeuft-oben
      data-laeuft-hinweis="Holt die Location von dort. Mit Grundrissen kann das einige Minuten dauern. Bitte nicht noch einmal klicken.">
    <?= Csrf::field() ?>
    <input type="hidden" name="schluessel" value="<?= View::e($location['schluessel']) ?>">
    <input type="hidden" name="ziel" value="<?= $ziel === 0 ? 'neu' : (int) $ziel ?>">
    <div class="card-head"><h2>Etagen</h2></div>
    <div class="table-wrap">
        <table class="tabelle-karten api-zuordnung">
            <thead><tr><th>Etage dort</th><th>Inhalt</th><th>Etage hier</th></tr></thead>
            <tbody>
            <?php if ($etagen === []): ?><tr><td colspan="3" class="muted">Die Location dort hat keine Etagen.</td></tr><?php endif; ?>
            <?php foreach ($etagen as $i => $e): ?>
                <?php $vor = $e['ziel']; ?>
                <tr>
                    <td data-label="Etage dort"><label for="zuordnen-etage-<?= (int) $i ?>"><strong><?= View::e((string) $e['name']) ?></strong></label>
                        <div class="small muted mono"><?= View::e((string) $e['schluessel']) ?></div>
                        <?php if ($e['bearbeiten']): ?><span class="badge">dort bearbeitbar</span><?php endif; ?></td>
                    <td data-label="Inhalt" class="small">
                        <?= $e['plan'] ? ($e['webp'] ? 'Plan <span class="muted">(webp – wird nicht übernommen)</span>' : 'Plan') : '<span class="muted">ohne Plan</span>' ?>
                        <?= $e['massstab'] ? ' · Maßstab' : '' ?><?= $e['georeferenz'] ? ' · Georeferenz' : '' ?>
                        <?= $e['anlagen'] !== null ? ' · ' . View::e(PartnerAbgleich::feststromText((int) $e['anlagen'], (int) $e['leitungen'])) : '' ?>
                    </td>
                    <td data-label="Etage hier">
                        <select id="zuordnen-etage-<?= (int) $i ?>" name="etagen[<?= View::e((string) $e['schluessel']) ?>]">
                            <option value="">nicht übernehmen</option>
                            <option value="neu"<?= $vor === null && $hier === null ? ' selected' : '' ?>>als neue Etage</option>
                            <?php foreach ($zielEtagen as $f): ?>
                                <?php $belegt = (string) $f['partner_schluessel'] !== '' && (string) $f['partner_schluessel'] !== (string) $e['schluessel']; ?>
                                <option value="<?= (int) $f['id'] ?>"<?= $vor !== null && (int) $vor['id'] === (int) $f['id'] ? ' selected' : '' ?><?= $belegt ? ' disabled' : '' ?>>
                                    <?= View::e((string) $f['name'] . ($belegt ? ' (mit anderer Etage dort verknüpft)' : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($vor !== null): ?>
                            <div class="small muted"><?= $vor['grund'] === 'verknuepft' ? 'verknüpft' : 'Vorschlag: ' . View::e(PartnerAbgleich::GRUENDE[$vor['grund']] ?? '') ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body">
        <?php if ($feststrom): ?>
            <label class="check">
                <input type="checkbox" name="feststrom" value="1" checked>
                <span>Feststrom übernehmen <span class="small muted">– Feststromanschlüsse mit Schutzorganen und Anschlüssen und die fest
                    verlegten Kabel der gewählten Etagen. Messgeräte kommen nicht mit, eine Schaltsperre hier bleibt.</span></span>
            </label>
            <?php if ($stecker_fehlen !== []): ?>
                <div class="notice<?= $darf_stecker ? '' : ' warn' ?>">Diese Steckertypen gibt es hier noch nicht:
                    <?= View::e(implode(', ', $stecker_fehlen)) ?>.
                    <?= $darf_stecker ? 'Sie werden beim Übernehmen angelegt.' : 'Anlegen darf sie nur, wer die Steckverbinder pflegt – mit Feststrom geht das Übernehmen deshalb nicht.' ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <p class="small muted">Von dort kommen Plan, Maßstab-Text, Notizen, Maßstab und Georeferenz der gewählten Etagen. Namen von Standort
            und Etagen hier bleiben, ein fehlender Maßstab oder eine fehlende Georeferenz dort löscht hier nichts, gelöscht wird nichts.</p>
        <?php if ($darf_planen): ?>
            <div class="btn-row">
                <button class="btn btn-primary" type="submit" name="aktion" value="uebernehmen"<?= $rueckfrage !== '' ? ' data-confirm="' . View::e($rueckfrage) . '"' : '' ?> data-laeuft="übernimmt …">Übernehmen</button>
                <?php if ($ziel > 0): ?>
                    <button class="btn" type="submit" name="aktion" value="verknuepfen" data-laeuft="verknüpft …">Nur verknüpfen</button>
                    <span class="small muted">verknüpft die zugeordneten Etagen, ohne etwas zu holen – danach lassen sie sich zurückschicken
                        oder später auf den Stand von dort bringen.</span>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="notice">Übernehmen und Verknüpfen braucht das Recht, Standorte, Etagen und Grundrisse zu pflegen.</div>
        <?php endif; ?>
    </div>
</form>
