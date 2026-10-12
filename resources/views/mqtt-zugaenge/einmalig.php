<?php
use App\Support\View;

$title = 'Eigener MQTT-Zugang';
$subtitle = 'Wird nur jetzt angezeigt';
?>
<div class="notice warn">Benutzer und Kennwort werden <strong>nicht wieder angezeigt</strong>. Jetzt am Gerät eintragen – geht es verloren: „Neues Kennwort“.</div>
<div class="card">
    <div class="card-body">
        <p><?= View::e($meldung) ?></p>
        <dl class="zugangsdaten">
            <dt>Benutzer</dt>
            <dd><code id="mqtt-benutzer"><?= View::e($benutzer) ?></code> <button class="btn btn-sm btn-ghost" type="button" data-kopieren="#mqtt-benutzer">kopieren</button></dd>
            <dt>Kennwort</dt>
            <dd><code id="mqtt-kennwort"><?= View::e($kennwort) ?></code> <button class="btn btn-sm btn-ghost" type="button" data-kopieren="#mqtt-kennwort">kopieren</button></dd>
        </dl>
        <ol class="small">
            <li>Weboberfläche des Shelly öffnen: <strong>Internet &amp; Security → Advanced Developer Settings</strong>.</li>
            <li>Nur <strong>Username</strong> und <strong>Password</strong> ändern; Server und alles andere unverändert lassen.</li>
            <li>Speichern – der Shelly startet dabei neu und meldet sich mit dem eigenen Zugang an.</li>
            <li>Hier „Umstellung prüfen“: dann verliert der gemeinsame Zugang den Zugriff auf dieses Gerät.</li>
        </ol>
    </div>
    <div class="card-footer btn-row">
        <a class="btn btn-primary btn-sm" href="/datenquellen/mqtt-zugaenge">Zur Übersicht</a>
    </div>
</div>
