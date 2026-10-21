<?php
/**
 * Einen Zugang ändern: Name, Notiz, Befugnisse, Standorte, Bindung, Ablauf.
 * Das Token selbst bleibt dasselbe.
 *
 * @var array<string,mixed> $token ApiZugaenge::find()
 * @var array<string,string> $befugnisse
 * @var array<string,bool> $vergebbar
 * @var list<array<string,mixed>> $standorte
 * @var list<array{id:int,username:string,aktiv:bool}> $benutzer
 * @var array<string,mixed> $eingabe
 */
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Zugang „' . (string) $token['name'] . '“';
$subtitle = 'Befugnisse, Standorte, Bindung und Ablauf';
$widerrufen = $token['zustand'] === 'widerrufen';
?>
<?php if ($widerrufen): ?>
    <div class="notice warn">Dieser Zugang ist widerrufen (<?= View::e(Zeit::anzeige((string) $token['revoked_at'])) ?>) und lässt sich nicht mehr ändern.</div>
<?php elseif ($token['zustand'] === 'abgelaufen'): ?>
    <div class="notice warn">Dieser Zugang ist abgelaufen. Mit einem neuen Ablaufdatum gilt er wieder.</div>
<?php endif; ?>
<form method="post" action="/system/schnittstelle/<?= (int) $token['id'] ?>/speichern" class="card">
    <?= Csrf::field() ?>
    <div class="card-head"><h2>Zugang</h2>
        <span class="small muted mono"><?= View::e((string) $token['token_prefix']) ?>…</span></div>
    <div class="card-body">
        <?= View::partial('schnittstelle/felder', [
            'eingabe'    => $eingabe,
            'befugnisse' => $befugnisse,
            'vergebbar'  => $vergebbar,
            'benutzer'   => $benutzer,
            'standorte'  => $standorte,
        ]) ?>
        <p class="small muted">Angelegt <?= View::e(Zeit::anzeige((string) $token['created_at'])) ?> von <?= View::e((string) $token['created_by']) ?>
            <?php if ($token['last_used_at'] !== null): ?> · zuletzt benutzt <?= View::e(Zeit::anzeige((string) $token['last_used_at'])) ?><?php endif; ?></p>
    </div>
    <div class="card-footer btn-row">
        <?php if (!$widerrufen): ?><button class="btn btn-primary" type="submit">Speichern</button><?php endif; ?>
        <a class="btn" href="/system/schnittstelle">Zurück</a>
    </div>
</form>
