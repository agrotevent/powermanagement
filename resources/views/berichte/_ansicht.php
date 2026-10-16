<?php
/**
 * Leseansicht eines Berichts aus seinem Inhalt – für eingefrorene Berichte
 * und für alle, die einen Entwurf nur sehen. Texte der Prüfpunkte, Normen
 * und Gründe aus den festen Kopien (`labels`), sonst aus dem Katalog.
 *
 * @var array $inhalt vollständig (BerichtSchema::vollstaendig)
 * @var array $bewertung
 * @var ?array $unterschriften je Rolle (BerichtService::unterschriften) – ohne: kein Block
 */
use App\Berichte\Felder;
use App\Berichte\Katalog;
use App\Support\View;

$kopf = $inhalt['kopf'];
$labels = is_array($inhalt['labels'] ?? null) ? $inhalt['labels'] : [];
$normen = (array) ($labels['normen'] ?? Katalog::normen());
$gruende = (array) ($labels['gruende'] ?? Katalog::gruende());
$punktText = static fn (string $teil, string $s): string => (string) ($labels[$teil][$s] ?? (($teil === 'besichtigung' ? Katalog::besichtigung() : Katalog::erprobung())[$s]['text'] ?? $s));
$zustand = ['io' => '✓ i.O.', 'nio' => '✗ n.i.O.', 'entf' => 'entf.'];
$datum = static fn (string $d): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
$wert = static function (array $w): string {
    $v = (string) ($w['w'] ?? '');
    return $v === '' ? '' : trim(($w['op'] ?? '') . ' ' . Felder::komma($v));
};
$jaNein = ['ja' => 'Ja', 'nein' => 'Nein', '' => '–'];
$zeile = static fn (string $t): string => nl2br(View::e($t !== '' ? $t : '–'));
?>
<div class="bericht-ansicht">
    <div class="card">
        <div class="card-head"><h2>Kopf</h2></div>
        <div class="card-body">
            <dl class="kv">
                <dt>Veranstaltung</dt><dd><?= $zeile(trim($kopf['veranstaltung'] . ($kopf['zeitraum'] !== '' ? "\n" . $kopf['zeitraum'] : ''))) ?></dd>
                <dt>Auftrag / Projekt</dt><dd><?= View::e(trim($kopf['auftrag_nr'] . ($kopf['projekt_nr'] !== '' ? ' / ' . $kopf['projekt_nr'] : '')) ?: '–') ?></dd>
                <dt>Kunde</dt><dd><?= $zeile(trim($kopf['kunde']['firma'] . "\n" . $kopf['kunde']['anschrift'])) ?></dd>
                <dt>Ansprechpartner</dt><dd><?= $zeile(trim(implode("\n", array_filter([$kopf['kunde']['ansprechpartner'], $kopf['kunde']['telefon'], $kopf['kunde']['email']])))) ?></dd>
                <dt>Projektleiter Kunde</dt><dd><?= $zeile($kopf['projektleiter_kunde']) ?></dd>
                <dt>Projektleiter Montage</dt><dd><?= $zeile($kopf['projektleiter_montage']) ?></dd>
                <?php if (is_array($kopf['pruefer'] ?? null)): ?>
                    <dt>Prüfer</dt><dd><?= View::e(trim(($kopf['pruefer']['name'] ?? '') . ' · ' . ($kopf['pruefer']['qualifikation'] ?? ''), ' ·')) ?></dd>
                <?php endif; ?>
                <dt>Anlage</dt><dd><strong><?= View::e($kopf['anlage']['bezeichnung'] ?: '–') ?></strong><?= $kopf['anlage']['details'] !== '' ? '<br>' . nl2br(View::e($kopf['anlage']['details'])) : '' ?></dd>
                <dt>Aufstellort</dt><dd><?= $zeile($kopf['anlage']['aufstellort']) ?></dd>
                <dt>Netz</dt><dd><?= View::e(implode(' · ', array_filter([
                    $inhalt['netz']['spannung'] !== '' ? $inhalt['netz']['spannung'] . ' V' : '', $inhalt['netz']['frequenz'] !== '' ? $inhalt['netz']['frequenz'] . ' Hz' : '',
                    $inhalt['netz']['form'], (string) (((array) ($labels['einspeisungen'] ?? Katalog::einspeisungen()))[$inhalt['netz']['einspeisung']] ?? ''), $inhalt['netz']['erder'] !== '' ? 'Erder: ' . $inhalt['netz']['erder'] : '',
                ])) ?: '–') ?></dd>
                <dt>Prüfung nach</dt><dd><?= View::e(implode(', ', array_merge(array_map(static fn ($n) => (string) ($normen[$n] ?? $n), $inhalt['grundlage']['normen']),
                    $inhalt['grundlage']['sonstige'] !== '' ? [$inhalt['grundlage']['sonstige']] : [])) ?: '–') ?></dd>
                <dt>Grund</dt><dd><?= View::e((string) ($gruende[$inhalt['grundlage']['grund']] ?? '–')) ?></dd>
                <dt>Prüfdatum</dt><dd><?= View::e($datum((string) $kopf['pruefdatum'])) ?><?= $kopf['ort'] !== '' ? ' · ' . View::e($kopf['ort']) : '' ?></dd>
            </dl>
        </div>
    </div>

    <div class="grid grid-2">
        <?php foreach (['besichtigung' => 'Besichtigung', 'erprobung' => 'Erprobung'] as $teil => $name): ?>
            <div class="card">
                <div class="card-head"><h2><?= $name ?></h2></div>
                <div class="card-body tight">
                    <?php $antworten = Katalog::inReihenfolge((array) ($inhalt['punkte'][$teil] ?? []), $teil === 'besichtigung' ? Katalog::besichtigung() : Katalog::erprobung()); ?>
                    <?php if ($antworten === []): ?><p class="muted small mb0">Nichts eingetragen.</p><?php endif; ?>
                    <ul class="liste-schlicht">
                        <?php foreach ($antworten as $s => $z): ?>
                            <li><span class="bewertung <?= $z === 'io' ? 'ok' : ($z === 'nio' ? 'mangel' : 'entf') ?>"><?= View::e($zustand[$z] ?? $z) ?></span>
                                <?= View::e($punktText($teil, (string) $s)) ?>
                                <?php if (($inhalt['punkt_bemerkung'][$s] ?? '') !== ''): ?><div class="small muted"><?= View::e($inhalt['punkt_bemerkung'][$s]) ?></div><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Messprotokoll</h2>
            <span class="hint">Erdung <?= View::e($wert((array) $inhalt['messung']['erdung']) ?: '–') ?> Ω · R_iso bei <?= View::e((string) $inhalt['messung']['riso_u']) ?> V</span></div>
        <?php if (is_array($inhalt['messgeraete'] ?? null) && $inhalt['messgeraete'] !== []): ?>
            <div class="card-body tight small">Messgeräte: <?= View::e(implode('; ', array_map(static fn (array $g): string => trim($g['fabrikat'] . ' ' . $g['typ'])
                . ($g['seriennummer'] !== '' ? ', Nr. ' . $g['seriennummer'] : '') . ($g['kalibriert_bis'] !== '' ? ', kalibriert bis ' . $datum($g['kalibriert_bis']) : '')
                . ($g['norm'] !== '' ? ' (' . $g['norm'] . ')' : ''), $inhalt['messgeraete']))) ?></div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Nr.</th><th>Ort / Anlagenteil</th><th>Schutz</th><th class="num">Zs Ω</th><th class="num">R_iso MΩ</th><th class="num">IΔ mA</th>
                    <th class="num">t_A ms</th><th class="num">U_B V</th><th>Bewertung</th></tr></thead>
                <tbody>
                <?php if ($inhalt['kreise'] === []): ?><tr><td colspan="9" class="muted">Keine Stromkreise.</td></tr><?php endif; ?>
                <?php foreach ($inhalt['kreise'] as $k): ?>
                    <?php $bw = $bewertung['kreise'][(string) $k['z']] ?? null; [$kl, $tx] = Felder::bewertung((string) ($bw['auto'] ?? ''), (string) $k['pruefer']); ?>
                    <tr>
                        <td data-label="Nr."><?= View::e((string) $k['nr']) ?></td>
                        <td data-label="Ort / Anlagenteil"><?= View::e((string) $k['ort']) ?><?= (string) $k['kabel'] !== '' ? '<div class="small muted">' . View::e((string) $k['kabel']) . '</div>' : '' ?></td>
                        <td data-label="Schutz" class="small"><?= View::e(Felder::schutzText($k)) ?></td>
                        <td data-label="Zs Ω" class="num"><?= View::e($wert((array) $k['w']['zs']) ?: ($wert((array) $k['w']['ik']) !== '' ? 'Ik ' . $wert((array) $k['w']['ik']) . ' A' : '')) ?></td>
                        <td data-label="R_iso MΩ" class="num"><?= View::e($wert((array) $k['w']['riso'])) ?></td>
                        <td data-label="IΔ mA" class="num"><?= View::e($wert((array) $k['w']['idelta'])) ?></td>
                        <td data-label="t_A ms" class="num"><?= View::e($wert((array) $k['w']['ta'])) ?></td>
                        <td data-label="U_B V" class="num"><?= View::e($wert((array) $k['w']['ub'])) ?></td>
                        <td data-label="Bewertung"><span class="bewertung <?= View::e($kl) ?>"><?= View::e($tx) ?></span>
                            <?= (string) $k['status'] === 'nicht_gemessen' && (string) $k['grund'] !== '' ? '<div class="small muted">' . View::e((string) $k['grund']) . '</div>' : '' ?>
                            <?= (string) $k['begruendung'] !== '' ? '<div class="small muted">abweichend bewertet: ' . View::e((string) $k['begruendung']) . '</div>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Ergebnis</h2></div>
        <div class="card-body">
            <dl class="kv">
                <dt>Entspricht den Regeln</dt><dd><?= View::e($jaNein[$inhalt['ergebnis']['entspricht']] ?? '–') ?></dd>
                <dt>Mängelfrei</dt><dd><?= View::e($jaNein[$inhalt['ergebnis']['maengelfrei']] ?? '–') ?></dd>
                <?php if ($inhalt['ergebnis']['instand_bis'] !== ''): ?><dt>Instand zu setzen bis</dt><dd><?= View::e($datum($inhalt['ergebnis']['instand_bis'])) ?></dd><?php endif; ?>
                <dt>Nächster Prüftermin</dt><dd><?= View::e($inhalt['ergebnis']['naechste'] ?: '–') ?></dd>
                <?php if ($inhalt['ergebnis']['plakette'] === 'ja'): ?><dt>Prüfplakette</dt><dd><?= View::e(trim('ja ' . $inhalt['ergebnis']['plakette_nr'])) ?></dd><?php endif; ?>
                <?php if ($inhalt['ergebnis']['errichter'] === '1'): ?><dt>Errichterbestätigung</dt><dd><?= View::e((string) ($inhalt['texte']['errichter'] ?? 'ja')) ?></dd><?php endif; ?>
            </dl>
            <?php if ($inhalt['maengel'] !== []): ?>
                <h3 class="mt">Mängel</h3>
                <ul class="liste-schlicht">
                    <?php foreach ($inhalt['maengel'] as $m): ?>
                        <li><?= nl2br(View::e((string) $m['text'])) ?><div class="small muted"><?= View::e(implode(' · ', array_filter([
                            ($m['frist'] ?? '') !== '' ? 'Frist ' . $datum((string) $m['frist']) : '', ($m['behoben'] ?? '') !== '' ? 'behoben ' . $datum((string) $m['behoben']) : '']))) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($inhalt['bemerkungen'] !== ''): ?><h3 class="mt">Bemerkungen</h3><p><?= nl2br(View::e((string) $inhalt['bemerkungen'])) ?></p><?php endif; ?>
        </div>
    </div>

    <?php if (isset($unterschriften) && is_array($unterschriften) && ($unterschriften !== [] || (string) ($bericht['status'] ?? 'entwurf') !== 'entwurf')): ?>
        <?= View::partial('berichte/_unterschriften', ['bericht' => $bericht ?? [], 'inhalt' => $inhalt, 'unterschriften' => $unterschriften]) ?>
    <?php endif; ?>
</div>
