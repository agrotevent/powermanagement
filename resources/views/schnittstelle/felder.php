<?php
/**
 * Die Felder eines Zugangs – für „Neuer Zugang“ und „Zugang ändern“.
 *
 * @var array<string,mixed> $eingabe name, notiz, befugnisse, user_id, ablauf, sehen, bearbeiten
 * @var array<string,string> $befugnisse ApiZugang::BEFUGNISSE
 * @var array<string,bool> $vergebbar
 * @var list<array{id:int,username:string,aktiv:bool}> $benutzer
 * @var list<array<string,mixed>> $standorte
 */
use App\Auth\ApiZugang;
use App\Auth\Permissions;
use App\Support\View;

$gewaehlt = array_values(array_filter((array) ($eingabe['befugnisse'] ?? []), 'is_string'));
$heute = \App\Support\Zeit::anzeige(gmdate('c'), 'Y-m-d');
?>
<div class="grid grid-2">
    <div>
        <div class="field">
            <label for="api-name">Name</label>
            <input type="text" id="api-name" name="name" required maxlength="190" value="<?= View::e((string) ($eingabe['name'] ?? '')) ?>" placeholder="MasterAdmin Messe Nord">
            <span class="help">Woran man später erkennt, wer damit arbeitet – steht auch im Audit-Log.</span>
        </div>
        <div class="field">
            <label for="api-notiz">Notiz (optional)</label>
            <input type="text" id="api-notiz" name="notiz" maxlength="500" value="<?= View::e((string) ($eingabe['notiz'] ?? '')) ?>">
        </div>
        <div class="field mb0"><label>Befugnisse</label></div>
        <div role="group" aria-label="Befugnisse">
            <?php foreach ($befugnisse as $schluessel => $text): ?>
                <?php $an = in_array($schluessel, $gewaehlt, true); $darf = $vergebbar[$schluessel] ?? false; ?>
                <label class="check">
                    <input type="checkbox" name="befugnisse[]" value="<?= View::e($schluessel) ?>"<?= $an ? ' checked' : '' ?><?= $darf ? '' : ' disabled' ?>>
                    <span><code><?= View::e($schluessel) ?></code> <span class="small muted"><?= View::e($text) ?></span>
                        <?php if (!$darf): ?><span class="small"> – vergibt nur, wer selbst <?= View::e(implode(' und ', array_map(
                            static fn (string $r): string => '„' . Permissions::label($r) . '“', ApiZugang::BEFUGNIS_RECHT[$schluessel] ?? []))) ?> hat</span><?php endif; ?></span>
                </label>
                <?php if ($an && !$darf): ?>
                    <?php /* Bleibt gesetzt, damit Speichern sie nicht stillschweigend entfernt – der Server lehnt dann mit Begründung ab. */ ?>
                    <input type="hidden" name="befugnisse[]" value="<?= View::e($schluessel) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <p class="help">„Schreiben“ zusammen mit „Feststrom“ ändert hier Anlagen und Kabel direkt – dafür braucht es zusätzlich
                „<?= View::e(Permissions::label('anlagen.manage')) ?>“ und „<?= View::e(Permissions::label('leitungen.manage')) ?>“.
                Messgeräte gehen nie hinaus.</p>
        </div>
        <div class="field">
            <label for="api-benutzer">An Benutzer binden (optional)</label>
            <select id="api-benutzer" name="user_id">
                <option value="0">– keiner –</option>
                <?php foreach ($benutzer as $b): ?>
                    <option value="<?= (int) $b['id'] ?>"<?= (int) ($eingabe['user_id'] ?? 0) === (int) $b['id'] ? ' selected' : '' ?>><?= View::e($b['username'] . ($b['aktiv'] ? '' : ' (inaktiv)')) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="help">Das Token gilt dann nur, solange dieses Konto aktiv und gültig ist – wer geht, nimmt seine Zugänge mit.
                Was das Token sieht, regeln trotzdem nur Befugnisse und Standorte hier.</span>
        </div>
        <div class="field">
            <label for="api-ablauf">Gültig bis (optional)</label>
            <input type="date" id="api-ablauf" name="ablauf" min="<?= View::e($heute) ?>" value="<?= View::e((string) ($eingabe['ablauf'] ?? '')) ?>">
            <span class="help">Bis zum Ende dieses Tages; leer = unbefristet.</span>
        </div>
    </div>
    <div>
        <div class="field mb0"><label>Standorte</label></div>
        <p class="small muted">Nur was hier angehakt ist, sieht die andere Instanz: Standort, Etagen, Grundrisse, Maßstab, Georeferenz –
            mit „Feststrom“ auch die Feststromanschlüsse und fest verlegten Kabel. Nie Messgeräte, mobile Planung oder Fremdbestand.</p>
        <?= View::partial('partials/token-standorte', [
            'standorte'  => $standorte,
            'sehen'      => (array) ($eingabe['sehen'] ?? []),
            'bearbeiten' => (array) ($eingabe['bearbeiten'] ?? []),
        ]) ?>
    </div>
</div>
