<?php use App\Support\View; ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<?= View::partial('partials/webapp-kopf') ?>
<title><?= View::e(($title ?? 'Anmeldung') . ' · ' . ($appName ?? 'MasterAdmin')) ?></title>
<link rel="stylesheet" href="<?= View::asset('/assets/css/app.css') ?>">
</head>
<body>
<?= $content ?>
</body>
</html>
