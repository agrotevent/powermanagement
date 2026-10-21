<?php
/**
 * Eine Kachel mit dem Füllstand eines Tanks.
 *
 * Wie die Live-Kachel einer Anlage: live.js setzt nur die Zahlen
 * ([data-tank], [data-feld="hinweis"]) und die Stufe, die Kachel bleibt
 * stehen. Unter dem Balken stehen Restlaufzeit und „nachbestellen bis“
 * (Versorgung über einen Kraftstoffschlauch oder aus dem Pegel). Mit
 * $details (Tankseite) zeigt sie zusätzlich Füllhöhe, Rohwert mit Alter, die
 * Kennlinie und den Verbrauch.
 *
 * @var array<string,mixed> $objekt
 * @var array<string,mixed> $l Zusammenfassung aus LiveService (mit „tank“)
 * @var bool $details
 */
use App\Services\AnlagenService;
use App\Support\View;
use App\Tanks\TankService;

$art = AnlagenService::ARTEN[$objekt['art']] ?? ['kuerzel' => 'T', 'farbe' => '#57534e', 'name' => $objekt['art']];
$stufe = (string) ($l['stufe'] ?? 'keine');
$t = is_array($l['tank'] ?? null) ? $l['tank'] : [];
$details ??= false;
$zahl = static fn (mixed $w, string $einheit): string => is_numeric($w) ? TankService::zahl(round((float) $w)) . ' ' . $einheit : '';
$prozent = is_numeric($t['prozent'] ?? null) ? (float) $t['prozent'] : null;
$gross = $zahl($t['liter'] ?? null, 'l') ?: ($zahl($t['hoehe_mm'] ?? null, 'mm') ?: '–');
$roh = is_numeric($t['roh'] ?? null)
    ? TankService::zahl(round((float) $t['roh'], 3)) . (($t['roh_einheit'] ?? '') !== '' ? ' ' . $t['roh_einheit'] : '') . (($t['stand'] ?? null) !== null ? ' · ' . $t['stand'] : '')
    : '–';
?>
<div class="live-kachel tank-kachel stufe-<?= View::e($stufe) ?>" data-objekt-id="<?= (int) $objekt['id'] ?>" data-art="tank">
    <div class="live-kopf">
        <span class="art-zeichen" style="--art-farbe: <?= View::e($art['farbe']) ?>"><?= View::e($art['kuerzel']) ?></span>
        <div class="live-titel">
            <a class="live-name" href="/anlagen/<?= (int) $objekt['id'] ?>"><?= View::e($objekt['name']) ?></a>
            <span class="live-ort small muted"><?= View::e(trim(($objekt['kennung'] ?? '') . ' · ' . ($objekt['site_name'] ?? ''), ' ·')) ?></span>
        </div>
    </div>
    <div class="tank-stand">
        <span class="tank-zahl" data-tank="menge"><?= View::e($gross) ?></span>
        <span class="tank-prozent" data-tank="prozent"><?= $prozent !== null ? View::e(TankService::zahl(round($prozent))) . ' %' : '' ?></span>
    </div>
    <div class="tank-balken" aria-hidden="true"><span data-tank-balken style="width: <?= $prozent !== null ? max(0, min(100, round($prozent, 1))) : 0 ?>%"></span></div>
    <div class="small muted" data-tank="nutzbar"><?= ($n = $zahl($t['nutzbar_l'] ?? null, 'l')) !== '' ? 'nutzbar ' . View::e($n) : '' ?></div>
    <div class="small tank-rest" data-tank="rest"><?= View::e(TankService::restZeile($t)) ?></div>
    <?php if ($details): ?>
        <dl class="kv small tank-details">
            <dt>Füllhöhe</dt><dd data-tank="hoehe"><?= View::e($zahl($t['hoehe_mm'] ?? null, 'mm') ?: '–') ?></dd>
            <dt>Rohwert</dt><dd data-tank="roh"><?= View::e($roh) ?></dd>
            <dt>Gerät</dt><dd data-tank="online"><?= ($t['online'] ?? null) === true ? 'online' : (($t['online'] ?? null) === false ? 'offline' : '–') ?></dd>
            <dt>Kennlinie</dt><dd data-tank="kennlinie"><?= View::e((string) ($t['kennlinie'] ?? '–')) ?></dd>
            <dt>Verbrauch</dt><dd data-tank="verbrauch"><?= View::e((string) (($t['verbrauch_text'] ?? '') !== '' ? $t['verbrauch_text'] : '–')) ?></dd>
            <dt>Leer um</dt><dd data-tank="leer"><?= View::e((string) ($t['leer_um_text'] ?? '–')) ?></dd>
        </dl>
    <?php endif; ?>
    <?php // Restlaufzeit und Verbrauch stehen schon unter dem Balken – der Hinweis ohne sie. ?>
    <div class="live-hinweis small muted" data-feld="hinweis"><?= View::e((string) (is_string($t['hinweis_kurz'] ?? null) ? $t['hinweis_kurz'] : ($l['hinweis'] ?? ''))) ?></div>
</div>
