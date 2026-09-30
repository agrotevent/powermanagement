<?php use App\Support\View; ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title><?= View::e(($title ?? 'Dokumentation') . ' · ' . ($appName ?? 'PowerManagement')) ?></title>
<link rel="stylesheet" href="<?= View::asset('/assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= View::asset('/assets/css/strom.css') ?>">
</head>
<body class="druckseite">
<?= $content ?>
<script nonce="<?= \App\Support\View::e(\App\Support\View::nonce()) ?>">window.addEventListener('load', function () { if (location.hash !== '#ohne-druck') { window.print(); } });</script>
</body>
</html>
