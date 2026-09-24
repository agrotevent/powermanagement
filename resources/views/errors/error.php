<?php use App\Support\View; ?>
<!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><title>Fehler <?= (int) $code ?></title>
<link rel="stylesheet" href="<?= View::asset('/assets/css/app.css') ?>"></head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <h1><?= (int) $code ?></h1>
        <p class="muted"><?= View::e($message) ?></p>
        <a class="btn btn-primary" href="<?= View::e(\App\Auth\Auth::check() ? \App\Auth\Auth::landingPath() : '/login') ?>">Weiter</a>
    </div>
</div>
</body></html>
