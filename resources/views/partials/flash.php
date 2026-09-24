<?php use App\Support\View; ?>
<?php if (!empty($flashes)): ?>
<div class="flash-area" id="meldungen">
    <?php foreach ($flashes as $flash): ?>
        <div class="flash <?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
