<?php
use App\Berichte\Felder;
use App\Berichte\Katalog;
use App\Support\View;

$zustand = ['io' => 'i.O.', 'nio' => 'n.i.O.', 'entf' => 'entf.'];
$teile = ['besichtigung' => ['Besichtigung', Katalog::besichtigung()], 'erprobung' => ['Erprobung', Katalog::erprobung()]];
$punkt = static function (string $teil, string $schluessel, array $p) use ($inhalt, $hinweise, $zustand): string {
    $wert = (string) ($inhalt['punkte'][$teil][$schluessel] ?? '');
    $bem = (string) ($inhalt['punkt_bemerkung'][$schluessel] ?? '');
    $offen = $wert === 'nio' || $bem !== '';
    $html = '<div class="punkt" data-punkt="' . View::e($schluessel) . '">'
        . '<div class="punkt-text"><span>' . View::e($p['text']) . '</span> <span class="punkt-quelle">' . View::e($p['quelle']) . '</span>';
    foreach ($hinweise[$schluessel] ?? [] as $h) {
        $html .= '<div class="punkt-hinweis">' . View::e($h) . '</div>';
    }
    $html .= '</div>'
        . Felder::dreiwahl('punkte.' . $teil . '.' . $schluessel, $wert, $zustand, ['label' => $p['text'], 'kurz' => $p['kurz']])
        . '<button type="button" class="btn btn-sm btn-ghost punkt-bem-knopf" data-bemerkung-auf' . ($offen ? ' hidden' : '') . '>+ Bemerkung</button>'
        . '<div class="punkt-bemerkung" data-punkt-bemerkung' . ($offen ? '' : ' hidden') . '>'
        . Felder::text('punkt_bemerkung.' . $schluessel, $bem, 'Bemerkung', ['max' => 500, 'platzhalter' => $wert === 'nio' ? 'Was ist nicht in Ordnung?' : ''])
        . '</div>';
    if ($schluessel === 'sicherheitseinrichtungen') {
        $html .= '<div class="punkt-bemerkung">' . Felder::text('sicherheitskategorie', (string) $inhalt['sicherheitskategorie'], 'Höchste Sicherheitskategorie', ['max' => 32]) . '</div>';
    }
    return $html . '</div>';
};
?>
<section class="card bericht-abschnitt" id="abschnitt-punkte" data-abschnitt="punkte">
    <div class="card-head"><h2>Besichtigung und Erprobung</h2><div class="spacer"></div>
        <button type="button" class="btn btn-sm" data-kurzliste-io data-frage="Alle noch nicht beantworteten Punkte der Kurzliste auf i.O. setzen?">Übrige Kurzliste auf i.O.</button></div>
    <div class="card-body">
        <p class="small muted">Je Punkt i.O., n.i.O. oder entfällt; ein zweiter Tipp nimmt die Wahl zurück. Hinweise aus der App stehen am Punkt – gesetzt wird damit nichts.</p>
        <?php foreach ($teile as $teil => [$name, $katalog]): ?>
            <?php
            $kurz = array_filter($katalog, static fn (array $p): bool => $p['kurz']);
            $weitere = array_diff_key($katalog, $kurz);
            $beantwortet = array_intersect_key($inhalt['punkte'][$teil] ?? [], $weitere) !== [];
            ?>
            <h3 class="mt"><?= View::e($name) ?></h3>
            <div class="punkte" data-feld-ziel="punkte.<?= $teil ?>">
                <?php foreach ($kurz as $s => $p): ?><?= $punkt($teil, $s, $p) ?><?php endforeach; ?>
            </div>
            <?php if ($weitere !== []): ?>
                <details class="weitere-punkte"<?= $beantwortet ? ' open' : '' ?>>
                    <summary>Weitere Prüfpunkte (<?= count($weitere) ?>)</summary>
                    <div class="punkte"><?php foreach ($weitere as $s => $p): ?><?= $punkt($teil, $s, $p) ?><?php endforeach; ?></div>
                </details>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
