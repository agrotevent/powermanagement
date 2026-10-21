<?php
/**
 * Layout des Kundenmodus: ohne Navigation und Kopfleiste, nur der
 * Firmenname. Das Gerät liegt beim Kunden – was er hier nicht sieht, kann
 * er auch nicht antippen (gesperrt ist es ohnehin, im Router).
 *
 * @var string $content
 */
use App\Support\View;

$firma = (string) ($firma ?? '');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title><?= View::e($title ?? 'Übergabebericht') ?> · <?= View::e($firma !== '' ? $firma : ($appName ?? 'PowerManagement')) ?></title>
<?= View::partial('partials/webapp-kopf') ?>
<link rel="stylesheet" href="<?= View::asset('/assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= View::asset('/assets/css/strom.css') ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%23b45309'/><path d='M18 3 7 18h7l-2 11 11-15h-7z' fill='white'/></svg>">
</head>
<body class="kundenmodus">
<header class="kunden-kopf">
    <strong><?= View::e($firma !== '' ? $firma : ($appName ?? 'PowerManagement')) ?></strong>
    <span>Übergabebericht</span>
</header>
<main class="kunden-inhalt">
    <?= View::partial('partials/flash', ['flashes' => $flashes ?? []]) ?>
    <?= $content ?>
</main>
<script nonce="<?= View::e(View::nonce()) ?>">window.PM_TOKEN = <?= json_encode(\App\Support\Csrf::token()) ?>;</script>
<script src="<?= View::asset('/assets/js/app.js') ?>"></script>
<?php foreach (($scripts ?? []) as $js): ?>
<script src="<?= View::asset($js) ?>"></script>
<?php endforeach; ?>
</body>
</html>
