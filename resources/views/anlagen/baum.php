<?php
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Support\View;

$title = 'Verteilbaum';
$subtitle = 'Wer speist wen – aus den eingezeichneten Kabeln';

$knoten = static function (array $k) use (&$knoten, $live, $sichtbar): string {
    if (!in_array($k['id'], $sichtbar, false)) {
        // Eine nicht freigegebene Anlage erscheint nur als Platzhalter,
        // damit der Baum nicht auseinanderfällt.
        $html = '<li class="baum-knoten verborgen"><span class="muted small">nicht freigegebene Anlage</span>';
    } else {
        $art = AnlagenService::ARTEN[$k['art']];
        $l = $live[(int) $k['id']] ?? [];
        $html = '<li class="baum-knoten"><div class="baum-karte stufe-' . View::e($l['stufe'] ?? 'keine') . '" data-objekt-id="' . (int) $k['id'] . '">'
            . '<span class="art-zeichen" style="--art-farbe: ' . View::e($art['farbe']) . '">' . View::e($art['kuerzel']) . '</span> '
            . '<a href="/anlagen/' . (int) $k['id'] . '"><strong>' . View::e($k['name']) . '</strong></a> '
            . '<span class="baum-wert" data-feld="p">' . View::e(Messdaten::format($l['p'] ?? null, 'W')) . '</span>'
            . '<span class="baum-wert muted" data-feld="auslastung">' . (($l['auslastung'] ?? null) !== null ? (int) round((float) $l['auslastung']) . ' %' : '') . '</span>';
        if ($k['kabel'] !== null) {
            $html .= '<div class="small muted">über ' . View::e(trim($k['kabel']['kabeltyp'] . ' ' . ($k['kabel']['querschnitt_mm2'] !== null ? str_replace('.', ',', (string) (float) $k['kabel']['querschnitt_mm2']) . ' mm²' : ''))) . '</div>';
        }
        $html .= '</div>';
    }
    if ($k['kinder'] !== []) {
        $html .= '<ul>';
        foreach ($k['kinder'] as $kind) {
            $html .= $knoten($kind);
        }
        $html .= '</ul>';
    }
    return $html . '</li>';
};
?>
<div class="toolbar-zeile">
    <form method="get" action="/anlagen/baum" class="inline-form">
        <select name="standort" data-absenden aria-label="Standort">
            <?php foreach ($standorte as $s): ?>
                <option value="<?= (int) $s['id'] ?>"<?= (int) $siteId === (int) $s['id'] ? ' selected' : '' ?>><?= View::e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <span class="small muted" data-live-url="/dashboard/live<?= $siteId ? '?standort=' . (int) $siteId : '' ?>"></span>
</div>
<div class="card">
    <div class="card-body">
        <?php if ($baum === []): ?>
            <p class="muted">Auf diesem Standort gibt es noch keine Anlagen.</p>
        <?php else: ?>
            <ul class="verteilbaum">
                <?php foreach ($baum as $k): ?><?= $knoten($k) ?><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
