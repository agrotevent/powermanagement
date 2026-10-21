<?php
/**
 * Einrichtungslink oder vorläufiges Kennwort eines Kundenzugangs – nur in
 * dieser einen Antwort (Cache-Control: no-store; nicht in Sitzung, Flash
 * oder Adresse). Ohne gültige app.url gibt es nur den Pfad, mit Warnung.
 *
 * @var array<string,mixed> $kunde
 * @var array<string,mixed> $ergebnis KundenService::zugangAnlegen/zugangLink/zugangKennwort
 * @var ?string $anmeldung app.url + /login
 * @var bool $unverschluesselt app.url mit http:// (nicht localhost)
 */
use App\Support\View;
use App\Support\Zeit;

$title = 'Zugang für ' . (string) $kunde['name'];
$subtitle = 'nur jetzt sichtbar';
$id = (int) $kunde['id'];
$kennwort = isset($ergebnis['kennwort']) ? (string) $ergebnis['kennwort'] : null;
$link = isset($ergebnis['link']) && is_string($ergebnis['link']) ? $ergebnis['link'] : null;
$pfad = (string) ($ergebnis['pfad'] ?? '');
?>
<div class="notice warn">Was hier steht, wird <strong>nur jetzt</strong> angezeigt – danach nie wieder. Jetzt kopieren und dem Kunden
    auf sicherem Weg geben (persönlich, Telefon, verschlüsselt) – <strong>nicht per unverschlüsselter Mail</strong>.
    Geht es verloren: in der Kundenverwaltung einen neuen Link oder ein neues vorläufiges Kennwort erzeugen.</div>
<?php if ($unverschluesselt): ?>
    <div class="notice danger">Die Adresse der Oberfläche (<code>app.url</code>) ist unverschlüsselt (http://) – Kennwort und Link gingen
        im Klartext durchs Netz. Bitte HTTPS einrichten.</div>
<?php endif; ?>
<div class="card">
    <div class="card-body">
        <p><?= View::e((string) $ergebnis['message']) ?></p>
        <dl class="zugangsdaten">
            <dt>Benutzername</dt><dd><code id="e-name"><?= View::e((string) $ergebnis['username']) ?></code>
                <button class="btn btn-sm btn-ghost" type="button" data-kopieren="#e-name">kopieren</button></dd>
            <dt>Anmeldung</dt><dd><?= $anmeldung !== null ? '<code>' . View::e($anmeldung) . '</code>'
                : '<span class="muted">Anmeldeseite dieser Oberfläche (<code>/login</code>)</span>' ?></dd>
        </dl>
        <?php if ($kennwort !== null): ?>
            <h2>Vorläufiges Kennwort</h2>
            <div class="api-token-zeile">
                <input type="text" class="mono" readonly id="e-geheim" value="<?= View::e($kennwort) ?>" aria-label="Vorläufiges Kennwort"
                       autocomplete="off" spellcheck="false" data-auswaehlen>
                <button class="btn btn-primary" type="button" data-kopieren="#e-geheim">Kopieren</button>
            </div>
            <p class="small muted">Bei der ersten Anmeldung legt der Kunde ein eigenes Kennwort fest.</p>
        <?php else: ?>
            <h2>Einrichtungslink</h2>
            <?php if ($link !== null): ?>
                <div class="api-token-zeile">
                    <input type="text" class="mono" readonly id="e-geheim" value="<?= View::e($link) ?>" aria-label="Einrichtungslink"
                           autocomplete="off" spellcheck="false" data-auswaehlen>
                    <button class="btn btn-primary" type="button" data-kopieren="#e-geheim">Kopieren</button>
                </div>
            <?php else: ?>
                <div class="notice warn">In <code>config/config.php</code> fehlt eine gültige <code>app.url</code> – hier steht deshalb nur
                    der Pfad. Vor den Pfad gehört die Adresse, unter der Kunden diese Oberfläche erreichen.</div>
                <div class="api-token-zeile">
                    <input type="text" class="mono" readonly id="e-geheim" value="<?= View::e($pfad) ?>" aria-label="Pfad des Einrichtungslinks"
                           autocomplete="off" spellcheck="false" data-auswaehlen>
                    <button class="btn btn-primary" type="button" data-kopieren="#e-geheim">Kopieren</button>
                </div>
            <?php endif; ?>
            <p class="small muted">Gilt einmal<?= isset($ergebnis['bis']) ? ', bis ' . View::e(Zeit::anzeige((string) $ergebnis['bis'])) : '' ?>.
                Darüber legt der Kunde sein Kennwort fest und meldet sich danach an.</p>
        <?php endif; ?>
    </div>
    <div class="card-footer btn-row">
        <a class="btn btn-primary btn-sm" href="/kunden/<?= $id ?>?tab=zugaenge">Zurück zu den Zugängen</a>
    </div>
</div>
