<?php
/**
 * Ein Token im Klartext – nur in dieser einen Antwort (nicht
 * zwischengespeichert, nicht in Sitzung oder Adresse).
 *
 * @var string $token
 * @var string $meldung
 * @var bool $erneut  true: „Anzeigen“, false: eben angelegt
 * @var array<string,mixed>|null $zugang ApiZugaenge::find()
 * @var string $adresse …/api/v1
 * @var bool $vermutet  app.url fehlt – Adresse aus dieser Anfrage
 */
use App\Support\View;

$title = $erneut ? 'Token anzeigen' : 'Neuer Zugang';
$subtitle = 'Das Token steht nur auf dieser Seite';
$pruefen = "curl -i -H 'Authorization: Bearer " . $token . "' " . rtrim($adresse, '/') . '/info';
?>
<div class="notice warn">Das Token wird hier <strong>nur jetzt</strong> angezeigt. Jetzt kopieren und bei der anderen Instanz
    eintragen. Geht es verloren: mit dem eigenen Kennwort „Anzeigen“ – oder widerrufen und einen neuen Zugang anlegen.</div>
<div class="card">
    <div class="card-body">
        <p><?= View::e($meldung) ?></p>
        <div class="api-token-zeile">
            <input type="text" class="mono" readonly id="api-token" value="<?= View::e($token) ?>" aria-label="Token"
                   autocomplete="off" spellcheck="false" data-auswaehlen>
            <button class="btn btn-primary" type="button" data-kopieren="#api-token">Kopieren</button>
        </div>
        <dl class="zugangsdaten mt">
            <dt>Adresse</dt>
            <dd><code><?= View::e($adresse) ?></code>
                <?php if ($vermutet): ?><span class="small muted">(aus dieser Anfrage – app.url ist nicht gesetzt)</span><?php endif; ?></dd>
            <dt>Prüfen</dt>
            <dd><code id="api-pruefen"><?= View::e($pruefen) ?></code>
                <button class="btn btn-sm btn-ghost" type="button" data-kopieren="#api-pruefen">kopieren</button></dd>
        </dl>
        <p class="small muted">Bei der anderen Instanz unter <strong>Andere Instanzen</strong> eintragen – dort die Adresse ohne
            <code>/api/v1</code>. Das Token gehört nur in den Kopf <code>Authorization: Bearer …</code>, nie in eine Adresse.</p>
        <?php if ($zugang !== null && $zugang['standorte'] === []): ?>
            <p><span class="badge warn">keine Standorte freigegeben</span> Das Token sieht noch keinen Standort –
                unter <a href="/system/schnittstelle/<?= (int) $zugang['id'] ?>">Ändern</a> freigeben.</p>
        <?php endif; ?>
    </div>
    <div class="card-footer btn-row">
        <a class="btn btn-primary btn-sm" href="/system/schnittstelle">Zur Übersicht</a>
    </div>
</div>
