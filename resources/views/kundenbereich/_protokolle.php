<?php
/**
 * Liste der Protokolle (Übergabeberichte) des Kunden – je Nummer die
 * gültige Fassung; frühere (ersetzte) Fassungen aufklappbar darunter.
 * Zustand immer als Wort, nicht nur als Farbe. Das PDF nur, wenn es beim
 * Abschluss gespeichert wurde.
 *
 * @var list<array<string,mixed>> $berichte KundenSicht::berichte()
 * @var bool $mitLocation Location je Eintrag zeigen (nicht auf der Location-Seite)
 */
use App\Kunden\Kundenbereich;
use App\Support\View;

$mitLocation = $mitLocation ?? true;
$datum = static fn (string $d): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
$basis = Kundenbereich::PFAD . '/protokolle/';
$name = static fn (array $p): string => (string) $p['nummer'] . ((int) $p['fassung'] > 1 ? ' (Fassung ' . (int) $p['fassung'] . ')' : '');
?>
<ul class="kb-protokolle">
    <?php foreach ($berichte as $p): ?>
        <li>
            <a class="kb-ort" href="<?= View::e($basis . (int) $p['id']) ?>">
                <span class="kb-ort-text">
                    <span class="kb-ort-name"><?= View::e($p['titel'] !== '' ? (string) $p['titel'] : 'Übergabebericht') ?></span>
                    <span class="kb-meta"><?= View::e(implode(' · ', array_filter([$name($p), 'Prüfung am ' . $datum((string) $p['pruefdatum']),
                        (string) $p['veranstaltung'], $mitLocation ? (string) $p['standort'] : '']))) ?></span>
                    <?php if ($p['status'] === 'ersetzt'): ?>
                        <span class="kb-zustand kb-zustand-alt">ersetzt<?= $p['ersetzt_durch'] !== null ? ' durch ' . View::e((string) $p['ersetzt_durch']) : '' ?></span>
                    <?php else: ?>
                        <span class="kb-zustand kb-zustand-ok">gültige Fassung</span>
                    <?php endif; ?>
                </span>
                <span class="kb-pfeil" aria-hidden="true">›</span>
            </a>
            <?php if ($p['pdf']): ?>
                <a class="btn btn-sm kb-pdf" href="<?= View::e($basis . (int) $p['id'] . '/pdf') ?>" target="_blank" rel="noopener">PDF</a>
            <?php endif; ?>
            <?php if (($p['fruehere'] ?? []) !== []): ?>
                <details class="kb-fruehere">
                    <summary>Frühere Fassungen (<?= count($p['fruehere']) ?>)</summary>
                    <ul>
                        <?php foreach ($p['fruehere'] as $f): ?>
                            <li>
                                <a href="<?= View::e($basis . (int) $f['id']) ?>"><?= View::e($name($f)) ?></a>
                                <span class="kb-meta">Prüfung am <?= View::e($datum((string) $f['pruefdatum'])) ?> · ersetzt<?= $f['ersetzt_durch'] !== null
                                    ? ' durch ' . View::e((string) $f['ersetzt_durch']) : '' ?></span>
                                <?php if ($f['pdf']): ?><a class="btn btn-sm" href="<?= View::e($basis . (int) $f['id'] . '/pdf') ?>" target="_blank" rel="noopener">PDF</a><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
