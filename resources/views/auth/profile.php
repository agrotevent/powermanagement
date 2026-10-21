<?php
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Mein Konto';
$subtitle = 'Rollen, Freigaben und Passwort';
/** @var array<string,mixed>|null $sso AuthController::ssoProfil() */
$sso = $sso ?? null;
$wegText = ['profil' => 'selbst im Profil verbunden', 'freischaltung' => 'von der Verwaltung freigeschaltet',
    'anlage' => 'bei der ersten Anmeldung automatisch angelegt', 'cli' => 'über die Kommandozeile'];
$ssoRollen = array_values(array_filter($roles, static fn (array $r): bool => ($r['quelle'] ?? 'hand') === 'sso'));
?>
<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Mein Konto</h2></div>
        <div class="card-body">
            <dl class="kv">
                <dt>Benutzername</dt><dd><?= View::e($currentUser['username']) ?></dd>
                <dt>Name</dt><dd><?= View::e($currentUser['full_name'] ?: '–') ?></dd>
                <dt>Zugang gültig</dt>
                <dd>
                    <?php if (empty($currentUser['gueltig_ab']) && empty($currentUser['gueltig_bis'])): ?>
                        unbefristet
                    <?php else: ?>
                        <?= !empty($currentUser['gueltig_ab']) ? 'ab ' . View::e(Zeit::anzeige($currentUser['gueltig_ab'])) : '' ?>
                        <?= !empty($currentUser['gueltig_bis']) ? ' bis ' . View::e(Zeit::anzeige($currentUser['gueltig_bis'])) . ' (' . View::e(Zeit::abstand($currentUser['gueltig_bis'])) . ')' : '' ?>
                    <?php endif; ?>
                </dd>
                <dt>Letzte Anmeldung</dt><dd><?= View::e(Zeit::anzeige($currentUser['last_login_at'])) ?></dd>
                <?php if (trim((string) ($currentUser['pruefer_qualifikation'] ?? '')) !== '' || View::can('berichte.pruefen')): ?>
                    <dt>Prüfer-Qualifikation</dt>
                    <dd><?= trim((string) ($currentUser['pruefer_qualifikation'] ?? '')) !== '' ? View::e((string) $currentUser['pruefer_qualifikation'])
                        : '<span class="text-warn">nicht hinterlegt – ohne sie keine Prüfer-Unterschrift</span>' ?>
                        <div class="small muted">Trägt die Benutzerverwaltung ein.</div></dd>
                <?php endif; ?>
                <dt>Rollen</dt>
                <dd>
                    <?php foreach ($roles as $role): ?>
                        <span class="badge primary"><?= View::e($role['name']) ?><?= ($role['quelle'] ?? 'hand') === 'sso' ? ' · über Gruppe' : '' ?><?= !empty($role['gueltig_bis']) ? ' bis ' . View::e(Zeit::anzeige($role['gueltig_bis'], ($role['quelle'] ?? 'hand') === 'sso' ? 'd.m.Y H:i' : 'd.m.Y')) : '' ?></span>
                    <?php endforeach; ?>
                </dd>
            </dl>

            <h3 class="mt">Was ich sehen darf</h3>
            <?php if ($alles): ?>
                <p>Alle Standorte und Anlagen, Messdaten ohne Zeitgrenze.</p>
            <?php elseif ($freigaben === []): ?>
                <p class="muted">Keine wirksame Freigabe – es sind keine Anlagen sichtbar.</p>
            <?php else: ?>
                <ul class="liste-schlicht">
                <?php foreach ($freigaben as $f): ?>
                    <li>
                        <strong><?= View::e($f['ziel_name']) ?></strong>
                        <?= (int) $f['darf_bearbeiten'] === 1 ? '<span class="badge info">bearbeiten</span>' : '' ?>
                        <?= (int) $f['darf_steuern'] === 1 ? '<span class="badge warn">schalten</span>' : '' ?>
                        <div class="small muted">
                            gültig <?= $f['gueltig_bis'] ? 'bis ' . View::e(Zeit::anzeige($f['gueltig_bis'])) : 'unbefristet' ?> ·
                            Daten <?= $f['daten_ab'] || $f['daten_bis']
                                ? View::e(($f['daten_ab'] ? 'ab ' . Zeit::anzeige($f['daten_ab']) : '') . ($f['daten_bis'] ? ' bis ' . Zeit::anzeige($f['daten_bis']) : ''))
                                : 'ohne Zeitgrenze' ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($sso !== null && $sso['nur_sso']): ?>
    <div class="card">
        <div class="card-head"><h2>Passwort</h2></div>
        <div class="card-body">
            <p>Dieses Konto meldet sich nur über <?= View::e($sso['name']) ?> an.</p>
            <p class="small muted mb0">Ein Kennwort gilt dafür nicht – geändert wird es bei <?= View::e($sso['name']) ?>.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-head"><h2>Passwort ändern</h2></div>
        <div class="card-body">
            <form method="post" action="/profil/passwort">
                <?= Csrf::field() ?>
                <div class="field">
                    <label>Aktuelles Passwort</label>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </div>
                <div class="field">
                    <label>Neues Passwort</label>
                    <input type="password" name="new_password" autocomplete="new-password" required>
                    <div class="help">Mindestens 12 Zeichen, Buchstaben und Ziffern.</div>
                </div>
                <div class="field">
                    <label>Neues Passwort wiederholen</label>
                    <input type="password" name="new_password_repeat" autocomplete="new-password" required>
                </div>
                <button class="btn btn-primary" type="submit">Passwort speichern</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($sso !== null): ?>
    <?php $i = $sso['identitaet']; ?>
    <div class="card" id="sso">
        <div class="card-head"><h2>Anmeldung über <?= View::e($sso['name']) ?></h2></div>
        <div class="card-body">
            <?php if ($sso['weg'] === 'sso' && $sso['seit'] > 0): ?>
                <p><span class="badge ok">angemeldet über <?= View::e($sso['name']) ?></span>
                    seit <?= View::e(Zeit::anzeige(gmdate('c', (int) $sso['seit']))) ?></p>
            <?php endif; ?>
            <?php if ($i !== null): ?>
                <dl class="kv">
                    <dt>Verknüpft seit</dt><dd><?= View::e(Zeit::anzeige($i['angelegt_am'])) ?></dd>
                    <dt>Wie</dt><dd><?= View::e($wegText[$i['weg']] ?? $i['weg']) ?></dd>
                    <dt>Name dort</dt><dd><?= View::e($i['name_dort'] !== '' ? $i['name_dort'] : '–') ?></dd>
                    <dt>Zuletzt über <?= View::e($sso['name']) ?></dt><dd><?= $i['zuletzt_am'] !== null ? View::e(Zeit::anzeige($i['zuletzt_am'])) : 'noch nie' ?></dd>
                    <dt>Gruppen dort</dt>
                    <dd><?php if ($i['gruppen'] === []): ?>–<?php else: ?>
                        <details><summary><?= count($i['gruppen']) ?> <?= count($i['gruppen']) === 1 ? 'Gruppe' : 'Gruppen' ?> bei der letzten Anmeldung</summary>
                            <ul class="liste-schlicht small"><?php foreach ($i['gruppen'] as $g): ?><li><?= View::e($g) ?></li><?php endforeach; ?></ul>
                        </details><?php endif; ?></dd>
                    <dt>Rollen aus Gruppen</dt>
                    <dd><?php if ($ssoRollen === []): ?>–<?php endif; ?>
                        <?php foreach ($ssoRollen as $r): ?>
                            <span class="badge info"><?= View::e($r['name']) ?> · über Gruppe<?= !empty($r['gueltig_bis']) ? ', bis ' . View::e(Zeit::anzeige($r['gueltig_bis'])) : '' ?></span>
                        <?php endforeach; ?></dd>
                </dl>
                <?php if ($i['anderer_aussteller']): ?>
                    <p class="notice warn mt">Die Verknüpfung gehört zu einem anderen Anmeldedienst als dem jetzt eingerichteten – sie gilt erst wieder, wenn die Verwaltung sie umstellt.</p>
                <?php endif; ?>
                <p class="help mt">Rollen aus Gruppen gelten bis zum Ende der Sitzung über <?= View::e($sso['name']) ?>; eine Anmeldung mit Kennwort entfernt sie.
                    Was sichtbar ist, regeln weiter die Freigaben hier.</p>
                <?php if (!$sso['nur_sso']): ?>
                    <form method="post" action="/profil/sso/loesen" class="mt"
                          data-confirm="Die Verknüpfung mit <?= View::e($sso['name']) ?> lösen?<?= $sso['weg'] === 'sso' ? ' Diese Sitzung endet, danach mit Kennwort anmelden.' : '' ?>">
                        <?= Csrf::field() ?>
                        <button class="btn" type="submit">Verknüpfung lösen</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p>Dieses Konto ist nicht mit <?= View::e($sso['name']) ?> verknüpft.</p>
                <?php if ($sso['verbinden']): ?>
                    <p><a class="btn btn-primary" href="/login/sso/verbinden">Mit <?= View::e($sso['name']) ?> verbinden</a></p>
                    <p class="help">Bei <?= View::e($sso['name']) ?> einmal neu anmelden – danach geht die Anmeldung hier auch darüber.
                        Benutzername und E-Mail-Adresse verknüpfen nie von selbst.</p>
                <?php elseif ($sso['weg'] === 'notzugang'): ?>
                    <p class="small muted">Ein Notzugang wird nie verknüpft.</p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($sso['verwaltung']): ?>
                <p class="notice warn mt mb0">Anmelden über <?= View::e($sso['name']) ?> geht für dieses Konto erst, wenn „Verwaltung per SSO“ eingeschaltet ist.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
