<?php
/**
 * Der Verteilerplan: Einspeisung → Schutzorgane → Abgänge, mit den
 * Messgeräten an der Stelle, an der sie eingebaut sind. Jedes Messgerät ist
 * anklickbar und lässt sich in die Auswertung übernehmen.
 *
 * @var list<array<string,mixed>> $eingaenge
 * @var list<array<string,mixed>> $abgaenge
 * @var list<array<string,mixed>> $schutz (Baumreihenfolge mit 'tiefe')
 * @var array<int,list<array>> $mpJeAnschluss
 * @var array<int,list<array>> $mpJeSchutz
 * @var list<array> $mpAllgemein
 * @var callable $messgeraet
 */
use App\Services\SchutzService;
use App\Support\View;

$kinder = [];
foreach ($schutz as $s) {
    $kinder[(int) ($s['vorgeschaltet_id'] ?? 0)][] = $s;
}
$abgaengeJe = [];
foreach ($abgaenge as $a) {
    $abgaengeJe[(int) ($a['schutzorgan_id'] ?? 0)][] = $a;
}
// Ein Abgang als kompaktes Kästchen – so passt ein LS mit seinem Abgang
// in eine Zeile, und eine Verteilung mit 20 Abgängen bleibt überschaubar.
$abgangHtml = static function (array $a) use ($mpJeAnschluss, $messgeraet): string {
    $html = '<span class="vp-abgang"><span class="stecker" style="--stecker-farbe: ' . View::e($a['typ_farbe']) . '"></span>'
        . '<strong>' . View::e($a['bezeichnung']) . '</strong> <span class="small">' . View::e($a['typ_name']) . '</span>'
        . ($a['phase'] !== '' ? ' <span class="badge">' . View::e($a['phase']) . '</span>' : '')
        . ($a['leitung'] !== null ? ' <span class="small muted">→ ' . View::e((string) $a['leitung']['gegenstelle']) . '</span>' : '');
    foreach ($mpJeAnschluss[(int) $a['id']] ?? [] as $m) {
        $html .= ' ' . $messgeraet($m);
    }
    return $html . '</span>';
};
$kurz = ['hauptschalter' => 'HS', 'rcd' => 'RCD', 'rcbo' => 'RCBO', 'ls' => 'LS', 'nh' => 'NH', 'sonstiges' => '·'];
$zweig = static function (int $elter) use (&$zweig, $kinder, $abgaengeJe, $mpJeSchutz, $messgeraet, $abgangHtml, $kurz): string {
    $html = '';
    foreach ($kinder[$elter] ?? [] as $s) {
        $id = (int) $s['id'];
        $html .= '<li class="vp-schutz"><div class="vp-zeile"><span class="schutz-art schutz-' . View::e($s['art']) . '" title="' . View::e(SchutzService::ARTEN[$s['art']] ?? $s['art']) . '">'
            . View::e($kurz[$s['art']] ?? $s['art']) . '</span> '
            . '<strong>' . View::e($s['text']) . '</strong>';
        foreach ($mpJeSchutz[$id] ?? [] as $m) {
            $html .= ' ' . $messgeraet($m);
        }
        foreach ($abgaengeJe[$id] ?? [] as $a) {
            $html .= ' ' . $abgangHtml($a);
        }
        $html .= '</div>';
        $innen = $zweig($id);
        if ($innen !== '') {
            $html .= '<ul>' . $innen . '</ul>';
        }
        $html .= '</li>';
    }
    return $html;
};
?>
<div class="verteilerplan">
    <div class="vp-einspeisung">
        <strong>Einspeisung</strong>
        <?php foreach ($eingaenge as $e): ?>
            <span class="vp-eingang"><span class="stecker" style="--stecker-farbe: <?= View::e($e['typ_farbe']) ?>"></span><?= View::e($e['bezeichnung'] . ' ' . $e['typ_name']) ?>
                <?= $e['leitung'] !== null ? '<span class="small muted">← ' . View::e((string) $e['leitung']['gegenstelle']) . '</span>' : '' ?></span>
        <?php endforeach; ?>
        <?php foreach ($mpAllgemein as $m): ?> <?= $messgeraet($m) ?><?php endforeach; ?>
    </div>
    <ul class="vp-baum">
        <?= $zweig(0) ?>
        <?php if (($abgaengeJe[0] ?? []) !== []): ?>
            <li class="vp-schutz"><div class="vp-zeile"><span class="muted small">ohne zugeordnete Sicherung:</span>
                <?php foreach ($abgaengeJe[0] as $a): ?> <?= $abgangHtml($a) ?><?php endforeach; ?></div></li>
        <?php endif; ?>
    </ul>
    <?php if ($schutz === [] && $abgaenge === []): ?><p class="muted small">Noch keine Abgänge und Schutzorgane dokumentiert.</p><?php endif; ?>
</div>
