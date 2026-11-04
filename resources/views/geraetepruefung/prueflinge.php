<?php
/**
 * Prüflinge der Geräteprüfung – Liste mit Filtern: Besitzer (eigener Bestand
 * oder ein Kunde), fällig (überfällig oder in 30 Tagen, nur überfällig, ohne
 * Termin), Betrieb (in Betrieb, außer Betrieb, alle) und Suche über Kennung,
 * Bezeichnung und Seriennummer. Fälligkeit und Ergebnis mit Symbol und Wort.
 *
 * @var list<array<string,mixed>> $liste Prueflinge::liste()
 * @var int $anzahl alle passenden (ohne Grenze)
 * @var array{besitzer:string,faellig:string,betrieb:string,suche:string} $filter
 * @var list<array{id:int,name:string}> $kunden
 * @var bool $pflegen
 */
use App\Geraetepruefung\Prueflinge;
use App\Kunden\Pruefnachweise;
use App\Support\View;

$title = 'Prüflinge';
$subtitle = 'Geräteprüfung (DGUV V3): eigener Bestand und Geräte der Kunden';
$gefiltert = $filter['besitzer'] !== '' || $filter['faellig'] !== '' || $filter['betrieb'] !== '' || $filter['suche'] !== '';
$neuLink = '/geraetepruefung/prueflinge/neu' . ($filter['besitzer'] !== '' && $filter['besitzer'] !== 'eigen' ? '?kunde=' . (int) $filter['besitzer'] : '');
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="/geraetepruefung">← Geräteprüfung</a>
    <div class="spacer"></div>
    <?php if ($pflegen): ?><a class="btn btn-sm btn-primary" href="<?= View::e($neuLink) ?>">+ Prüfling</a><?php endif; ?>
</div>

<form method="get" action="/geraetepruefung/prueflinge" class="card">
    <div class="card-body field-row">
        <div class="field"><label for="pl-besitzer">Besitzer</label>
            <select id="pl-besitzer" name="besitzer" data-absenden>
                <option value="">alle</option>
                <option value="eigen"<?= $filter['besitzer'] === 'eigen' ? ' selected' : '' ?>><?= View::e(Prueflinge::EIGEN) ?></option>
                <?php foreach ($kunden as $k): ?>
                    <option value="<?= (int) $k['id'] ?>"<?= $filter['besitzer'] === (string) $k['id'] ? ' selected' : '' ?>><?= View::e($k['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label for="pl-faellig">Fällig</label>
            <select id="pl-faellig" name="faellig" data-absenden>
                <?php foreach (Prueflinge::FILTER_FAELLIG as $w => $t): ?>
                    <option value="<?= View::e((string) $w) ?>"<?= $filter['faellig'] === (string) $w ? ' selected' : '' ?>><?= View::e($t) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label for="pl-betrieb">Betrieb</label>
            <select id="pl-betrieb" name="betrieb" data-absenden>
                <?php foreach (Prueflinge::FILTER_BETRIEB as $w => $t): ?>
                    <option value="<?= View::e((string) $w) ?>"<?= $filter['betrieb'] === (string) $w ? ' selected' : '' ?>><?= View::e($t) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label for="pl-suche">Suche</label>
            <input id="pl-suche" name="suche" type="search" maxlength="100" value="<?= View::e($filter['suche']) ?>" placeholder="Kennung, Bezeichnung, Seriennummer"></div>
        <div class="field"><label class="sr-only" for="pl-los">Suchen</label><button id="pl-los" class="btn" type="submit">Suchen</button></div>
    </div>
</form>

<div class="card mt">
    <div class="card-head"><h2><?= $anzahl === 1 ? '1 Prüfling' : (int) $anzahl . ' Prüflinge' ?></h2>
        <?php if ($anzahl > count($liste)): ?><span class="hint">die ersten <?= count($liste) ?> – Filter oder Suche grenzen ein</span><?php endif; ?></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Kennung</th><th>Gerät</th><th>Besitzer</th><th>Letzte Prüfung</th><th>Nächste Prüfung</th></tr></thead>
            <tbody>
            <?php if ($liste === []): ?>
                <tr><td colspan="5" class="muted"><?= $gefiltert ? 'Kein Prüfling passt zu den Filtern.' : 'Noch keine Prüflinge – sie kommen aus der Datenbank des Prüfgeräts oder werden hier angelegt.' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($liste as $p): ?>
                <tr>
                    <td data-label="Kennung"><a href="/geraetepruefung/prueflinge/<?= (int) $p['id'] ?>"><strong class="mono"><?= View::e((string) $p['kennung']) ?></strong></a>
                        <?php if ($p['inventar_kennung'] !== null): ?><div class="small muted">Inventar <?= View::e((string) $p['inventar_kennung']) ?></div><?php endif; ?></td>
                    <td data-label="Gerät"><?= View::e((string) $p['bezeichnung'] !== '' ? (string) $p['bezeichnung'] : '–') ?>
                        <?php $geraet = trim(implode(' ', array_filter([(string) $p['hersteller'], (string) $p['typ']]))); ?>
                        <?php if ($geraet !== '' || (string) $p['seriennummer'] !== ''): ?>
                            <div class="small muted"><?= View::e(implode(' · ', array_filter([$geraet, (string) $p['seriennummer'] !== '' ? 'SN ' . $p['seriennummer'] : '']))) ?></div>
                        <?php endif; ?></td>
                    <td data-label="Besitzer" class="small"><?= View::e((string) $p['besitzer']) ?></td>
                    <td data-label="Letzte Prüfung"><?= $p['letzte_pruefung'] !== null ? View::e(Pruefnachweise::datum((string) $p['letzte_pruefung'])) . ' ' : '' ?>
                        <?= View::partial('geraetepruefung/_pruefling-ergebnis', ['ergebnis' => (string) $p['letztes_ergebnis'], 'geprueft' => $p['letzte_pruefung'] !== null]) ?></td>
                    <td data-label="Nächste Prüfung"><?= View::partial('geraetepruefung/_pruefling-faelligkeit', ['f' => $p['faelligkeit']]) ?>
                        <?php if ($p['naechste_pruefung'] !== null && !$p['ausser_betrieb']): ?><div class="small muted"><?= View::e(Pruefnachweise::datum((string) $p['naechste_pruefung'])) ?></div><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
