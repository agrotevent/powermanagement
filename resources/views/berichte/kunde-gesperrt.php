<?php
/**
 * Kundenmodus auf einen Bericht, den dieses Konto nicht (mehr) sieht – etwa
 * weil die Freigabe inzwischen entzogen wurde: nur das Entsperren, nichts
 * vom Bericht.
 *
 * @var int $id
 * @var array $modus
 */
use App\Support\Csrf;
use App\Support\View;

$name = trim((string) ($modus['name'] ?? ''));
$kennwortVon = $name !== '' ? 'Kennwort von ' . $name : 'Kennwort des Prüfers';
?>
<div class="kunde">
    <section class="card kunde-fertig">
        <div class="card-body">
            <h1>Gerät gesperrt</h1>
            <p class="kunde-gross">Bitte geben Sie das Gerät an <strong><?= View::e($name !== '' ? $name : 'den Prüfer') ?></strong> zurück.</p>
        </div>
    </section>
    <section class="card kunde-entsperren">
        <div class="card-head"><h2>Für den Prüfer: Gerät entsperren</h2></div>
        <div class="card-body">
            <form method="post" action="/berichte/<?= (int) $id ?>/kunde/beenden" class="inline-form">
                <?= Csrf::field() ?>
                <input type="password" name="kennwort" required autocomplete="new-password" aria-label="<?= View::e($kennwortVon) ?>"
                       placeholder="<?= View::e($kennwortVon) ?>">
                <button class="btn" type="submit">Entsperren</button>
            </form>
        </div>
    </section>
</div>
