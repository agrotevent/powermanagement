<?php
use App\Services\InventarService;
use App\Support\View;
use App\Support\Zeit;

$title = 'Inventar';
$subtitle = 'Verteiler und Zwischenstecker mit ihren Shellys – beim Aufbau in geplante Anlagen eingesetzt';
$zustand = [
    'lager'      => ['im Lager', 'ok'],
    'im_einsatz' => ['im Einsatz', 'info'],
    'defekt'     => ['defekt', 'bad'],
];
?>
<div class="toolbar-zeile">
    <form method="get" action="/inventar" class="inline-form">
        <select name="art" onchange="this.form.submit()" aria-label="Art">
            <option value="">Verteiler und Zwischenstecker</option>
            <?php foreach (InventarService::ARTEN as $k => $n): ?>
                <option value="<?= $k ?>"<?= $art === $k ? ' selected' : '' ?>><?= View::e($n) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="suche" value="<?= View::e($suche) ?>" placeholder="Nummer, Name, Seriennummer …">
        <button class="btn btn-sm" type="submit">Suchen</button>
    </form>
    <div class="spacer"></div>
    <?php if ($darf): ?>
        <a class="btn btn-primary btn-sm" href="/inventar/neu?art=verteilung">+ Verteiler</a>
        <a class="btn btn-sm" href="/inventar/neu?art=zwischenstecker">+ Zwischenstecker</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Nummer</th><th>Art · Typ</th><th class="num">Messkanäle</th><th>Zustand</th><th>Wo</th></tr></thead>
            <tbody>
            <?php if ($liste === []): ?>
                <tr><td colspan="5" class="muted">Noch nichts im Inventar. Verteiler mit eingebauten Shellys und Zwischenstecker (Messadapter bis 125 A)
                    werden hier einmal angelegt und beim Aufbau in die geplanten Anlagen eingesetzt.</td></tr>
            <?php endif; ?>
            <?php foreach ($liste as $i): ?>
                <?php [$text, $klasse] = $zustand[$i['zustand']]; $e = $i['einsatz']; ?>
                <tr>
                    <td data-label="Nummer"><a href="/inventar/<?= (int) $i['id'] ?>"><strong><?= View::e($i['kennung']) ?></strong></a>
                        <?php if ($i['name'] !== ''): ?><div class="small muted"><?= View::e($i['name']) ?></div><?php endif; ?></td>
                    <td data-label="Art · Typ"><?= View::e($i['art_name']) ?> <span class="small muted">· <?= View::e($i['typ']) ?></span></td>
                    <td data-label="Messkanäle" class="num"><?= (int) $i['messgeraete_zahl'] ?></td>
                    <td data-label="Zustand"><span class="badge <?= $klasse ?>"><?= View::e($text) ?></span></td>
                    <td data-label="Wo" class="small">
                        <?php if ($e !== null): ?>
                            <?= View::e(trim(($e['anlage_name'] ?? $e['objekt_name']) . ($e['anschluss_name'] ? ' · ' . $e['anschluss_name'] : ''))) ?>
                            <div class="muted"><?= View::e((string) ($e['site_name'] ?? '')) ?> · seit <?= View::e(Zeit::anzeige((string) $e['ab'], 'd.m.Y H:i')) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
