<?php
/**
 * System → Anmeldung (SSO): Zustand, Verbindung, Optionen, Gruppen → Rollen,
 * Anmeldeanfragen und verknüpfte Konten. Alles über View::e – auch was der
 * Anmeldedienst geschickt hat (Name dort, Gruppen, Name und E-Mail einer
 * Anfrage). Das Secret steht nie in der Seite.
 *
 * @var array<string,mixed> $zustand Einstellungen::zustand()
 * @var list<array<string,mixed>> $zuordnungen Gruppe → Rolle
 * @var list<array{id:int,name:string,verwaltend:bool,waehlbar:bool,freigaben:int}> $rollen
 * @var list<array<string,mixed>> $anfragen Konten::anfragen()
 * @var list<array<string,mixed>> $konten Ziele zum Freischalten (unverknüpft, verwaltbar)
 * @var list<array<string,mixed>> $verknuepfungen
 * @var bool $darfBenutzer system.users.manage
 */
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Anmeldung (SSO)';
$subtitle = 'OpenID Connect, etwa Authentik – Verbindung, Gruppen → Rollen, Anmeldeanfragen';
$z = $zustand;
$name = (string) $z['name'];
$alter = static function (?int $sekunden): string {
    if ($sekunden === null) {
        return '–';
    }
    return match (true) {
        $sekunden < 120   => 'vor ' . $sekunden . ' s',
        $sekunden < 7200  => 'vor ' . intdiv($sekunden, 60) . ' min',
        $sekunden < 172800 => 'vor ' . intdiv($sekunden, 3600) . ' h',
        default           => 'vor ' . intdiv($sekunden, 86400) . ' Tagen',
    };
};
$secretText = [
    'hinterlegt'        => ['ok', 'hinterlegt'],
    'fehlt'             => ['bad', 'fehlt'],
    'andere_verbindung' => ['warn', 'gehört zu einer anderen Verbindung – bitte neu eingeben'],
    'unlesbar'          => ['bad', 'lässt sich nicht entschlüsseln (app.key geändert?) – bitte neu eingeben'],
][(string) $z['secret']] ?? ['bad', 'fehlt'];
$wegText = ['profil' => 'im Profil', 'freischaltung' => 'Freischaltung', 'anlage' => 'automatisch angelegt', 'cli' => 'Kommandozeile'];
$grundText = ['kein_konto' => 'noch kein Konto', 'name' => 'Benutzername vergeben oder ungültig'];
$waehlbar = array_values(array_filter($rollen, static fn (array $r): bool => $r['waehlbar']));
$gruppenListe = static function (array $gruppen): string {
    if ($gruppen === []) {
        return '–';
    }
    $html = '<details><summary>' . count($gruppen) . ' ' . (count($gruppen) === 1 ? 'Gruppe' : 'Gruppen') . '</summary><ul class="liste-schlicht small">';
    foreach ($gruppen as $g) {
        $html .= '<li>' . View::e((string) $g) . '</li>';
    }
    return $html . '</ul></details>';
};
?>
<div class="card">
    <div class="card-body">
        <p class="mb0">Optional – das Kennwort bleibt. <?= View::e($name) ?> entscheidet, wer sich anmelden darf (und über den zweiten Faktor);
            hier entscheidet sich, welche Rollen aus Gruppen kommen. <strong>Freigaben setzt du immer hier</strong> – an der Rolle oder am Konto.
            Verknüpft wird nur über die Kennung beim Anmeldedienst (Aussteller und Subjekt), nie über Benutzername oder E-Mail-Adresse.</p>
    </div>
</div>

<div class="grid grid-2">
    <div class="card" id="zustand">
        <div class="card-head"><h2>Zustand</h2></div>
        <div class="card-body">
            <?php if ($z['app_url_fehler'] !== null): ?>
                <p class="notice danger"><?= View::e((string) $z['app_url_fehler']) ?></p>
            <?php endif; ?>
            <dl class="kv">
                <dt>Anmeldung über SSO</dt>
                <dd><?php if ($z['wirksam']): ?><span class="badge ok">an</span>
                    <?php elseif ($z['aktiv']): ?><span class="badge warn">an, aber nicht vollständig eingerichtet</span>
                    <?php else: ?><span class="badge">aus</span><?php endif; ?></dd>
                <dt>Aussteller (Issuer)</dt>
                <dd><?= $z['aussteller'] !== '' ? '<code>' . View::e((string) $z['aussteller']) . '</code>' : '<span class="muted">nicht eingetragen</span>' ?>
                    <?php if ($z['aussteller_warnung'] !== null): ?><div class="small text-warn"><?= View::e((string) $z['aussteller_warnung']) ?></div><?php endif; ?></dd>
                <dt>Client-ID</dt>
                <dd><?= $z['client_id'] !== '' ? '<code>' . View::e((string) $z['client_id']) . '</code>' : '<span class="muted">nicht eingetragen</span>' ?></dd>
                <dt>Client-Secret</dt>
                <dd><span class="badge <?= $secretText[0] ?>"><?= View::e($secretText[1]) ?></span></dd>
                <dt>Rückruf-Adresse</dt>
                <dd><?php if ($z['rueckruf'] !== ''): ?>
                        <div class="sso-kopieren"><input id="sso-rueckruf" readonly value="<?= View::e((string) $z['rueckruf']) ?>" aria-label="Rückruf-Adresse">
                            <button class="btn btn-sm btn-ghost" type="button" data-kopieren="#sso-rueckruf">kopieren</button></div>
                        <div class="help">In <?= View::e($name) ?> als Redirect URI (<em>Strict</em>) eintragen.</div>
                    <?php else: ?><span class="muted">erst mit app.url</span><?php endif; ?></dd>
                <dt>Rücksprung nach dem Abmelden</dt>
                <dd><?php if ($z['abmelde_rueckkehr'] !== ''): ?>
                        <div class="sso-kopieren"><input id="sso-rueckkehr" readonly value="<?= View::e((string) $z['abmelde_rueckkehr']) ?>" aria-label="Rücksprung nach dem Abmelden">
                            <button class="btn btn-sm btn-ghost" type="button" data-kopieren="#sso-rueckkehr">kopieren</button></div>
                        <div class="help">Optional, als Redirect URI vom Typ <em>Logout</em> – dann unten „Rücksprung ist eingetragen“ anhaken.</div>
                    <?php else: ?><span class="muted">erst mit app.url</span><?php endif; ?></dd>
                <dt>Signatur</dt>
                <dd><?= $z['algs'] !== [] ? View::e(implode(', ', $z['algs'])) : '<span class="muted">noch nicht geprüft</span>' ?></dd>
                <dt>Schlüssel (kid)</dt>
                <dd><?= $z['kids'] !== [] ? View::e(implode(', ', array_map(static fn (string $k): string => $k === '' ? '(ohne kid)' : $k, $z['kids']))) : '–' ?></dd>
                <dt>Endpunkte auf</dt>
                <dd><?= $z['endpunkte'] !== null ? View::e((string) $z['endpunkte']) : '–' ?><?= $z['endpunkte'] !== null && !$z['abmelden_angeboten'] ? ' <span class="small muted">(ohne Abmelden beim Anmeldedienst)</span>' : '' ?></dd>
                <dt>Zwischenspeicher</dt>
                <dd>Discovery <?= View::e($alter($z['discovery_alter'])) ?> · Schlüssel <?= View::e($alter($z['jwks_alter'])) ?></dd>
                <dt>Sitzung über SSO</dt>
                <dd>höchstens <?= View::e(rtrim(rtrim(number_format((int) $z['sitzung_dauer'] / 3600, 1, ',', ''), '0'), ',')) ?> h – so lange gelten auch die Rollen aus Gruppen</dd>
            </dl>
            <?php if ((int) $z['fremde_identitaeten'] > 0): ?>
                <p class="notice warn mt"><?= (int) $z['fremde_identitaeten'] ?> Verknüpfung(en) gehören zu einem anderen Aussteller und gelten nicht –
                    gehört er zur selben Authentik-Installation: <code>bin/power sso:aussteller-umstellen</code>.</p>
            <?php endif; ?>

            <h3 class="mt">Letzte Prüfung</h3>
            <?php if ($z['geprueft_am'] === null): ?>
                <p class="muted">Noch nicht geprüft.</p>
            <?php else: ?>
                <p class="small muted"><?= View::e(Zeit::anzeige(gmdate('c', (int) $z['geprueft_am']))) ?></p>
                <ul class="liste-schlicht small sso-befund">
                    <?php foreach ($z['befund'] as $b): ?>
                        <li class="<?= $b['ok'] ? 'text-ok' : 'text-bad' ?>"><?= $b['ok'] ? '✓' : '✗' ?> <?= View::e((string) $b['text']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="card-footer btn-row">
            <form method="post" action="/system/sso/pruefen" data-laeuft="prüft …" data-laeuft-hinweis="Fragt den Anmeldedienst – das dauert bis zu einer halben Minute.">
                <?= Csrf::field() ?>
                <button class="btn" type="submit">Verbindung prüfen</button>
            </form>
            <?php if ($z['aktiv']): ?>
                <form method="post" action="/system/sso/schalten"
                      data-confirm="Die Anmeldung über <?= View::e($name) ?> ausschalten? Laufende SSO-Sitzungen enden, Rollen aus Gruppen sind sofort weg, „nur SSO“ ist aus.">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="an" value="0">
                    <button class="btn btn-ghost" type="submit">Ausschalten</button>
                </form>
            <?php else: ?>
                <form method="post" action="/system/sso/schalten" data-laeuft="prüft und schaltet ein …" data-laeuft-hinweis="Eingeschaltet wird erst nach einer frischen Prüfung.">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="an" value="1">
                    <button class="btn btn-primary" type="submit"<?= $z['eingerichtet'] ? '' : ' disabled' ?>>Einschalten</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" action="/system/sso/verbindung" class="card" id="verbindung">
        <?= Csrf::field() ?>
        <div class="card-head"><h2>Verbindung</h2></div>
        <div class="card-body">
            <div class="field"><label for="sso-aussteller">Aussteller (Issuer)</label>
                <input id="sso-aussteller" name="aussteller" type="url" inputmode="url" maxlength="255" autocomplete="off" spellcheck="false"
                       value="<?= View::e((string) $z['aussteller']) ?>" placeholder="https://auth.example.com/application/o/powermanagement/">
                <div class="help">In Authentik unter <em>Providers → Provider → OpenID Configuration Issuer</em> – genau so, mit „/“ am Ende.</div></div>
            <div class="field"><label for="sso-client">Client-ID</label>
                <input id="sso-client" name="client_id" maxlength="255" autocomplete="off" spellcheck="false" value="<?= View::e((string) $z['client_id']) ?>"></div>
            <div class="field"><label for="sso-secret">Client-Secret</label>
                <input id="sso-secret" name="client_secret" type="password" autocomplete="new-password" maxlength="512"
                       placeholder="<?= $z['secret'] === 'hinterlegt' ? 'hinterlegt – leer lassen zum Behalten' : '' ?>">
                <div class="help">Leer = unverändert, „-“ = entfernen. Liegt verschlüsselt und gilt nur für diesen Aussteller und diese Client-ID.</div></div>
            <div class="field"><label for="sso-name">Beschriftung</label>
                <input id="sso-name" name="name" maxlength="40" value="<?= View::e($name) ?>">
                <div class="help">Auf der Anmeldeseite: „Mit <?= View::e($name) ?> anmelden“.</div></div>
            <p class="help mb0">Ändern sich Aussteller oder Client-ID, ist die Anmeldung über SSO aus, laufende SSO-Sitzungen enden und das Secret ist neu einzugeben.</p>
        </div>
        <div class="card-footer btn-row"><button class="btn btn-primary" type="submit">Verbindung speichern</button></div>
    </form>

    <form method="post" action="/system/sso/optionen" class="card" id="optionen">
        <?= Csrf::field() ?>
        <div class="card-head"><h2>Anmeldung &amp; Konten</h2></div>
        <div class="card-body">
            <div class="field-row">
                <div class="field"><label for="sso-scopes">Scopes</label>
                    <input id="sso-scopes" name="scopes" maxlength="600" spellcheck="false" value="<?= View::e((string) $z['scopes']) ?>"></div>
                <div class="field"><label for="sso-claim">Gruppen-Claim</label>
                    <select id="sso-claim" name="gruppen_claim">
                        <?php foreach (\App\Auth\Sso\Einstellungen::GRUPPEN_CLAIMS as $claim): ?>
                            <option value="<?= View::e($claim) ?>"<?= $claim === $z['gruppen_claim'] ? ' selected' : '' ?>><?= View::e($claim) ?></option>
                        <?php endforeach; ?>
                    </select></div>
            </div>
            <div class="field"><label for="sso-pflicht">Pflichtgruppen (eine je Zeile)</label>
                <textarea id="sso-pflicht" name="pflichtgruppen" rows="3" spellcheck="false"><?= View::e(implode("\n", $z['pflichtgruppen'])) ?></textarea>
                <div class="help">Personal muss in mindestens einer davon sein – leer: keine Prüfung hier (dann in <?= View::e($name) ?> binden, wer hinein darf).</div></div>
            <label class="check"><input type="checkbox" name="anlegen" value="1"<?= $z['schalter']['anlegen'] ? ' checked' : '' ?>>
                Unbekannte Personen mit zugeordneter Gruppe automatisch anlegen</label>
            <p class="help">Sonst wird eine unbekannte Anmeldung eine Anmeldeanfrage (unten), die die Verwaltung freischaltet.</p>
            <label class="check"><input type="checkbox" name="abmelde_rueckkehr" value="1"<?= $z['schalter']['abmelde_rueckkehr'] ? ' checked' : '' ?>>
                Rücksprung nach dem Abmelden ist in <?= View::e($name) ?> eingetragen</label>
            <div class="field"><label for="sso-tage">Konten ohne SSO-Anmeldung deaktivieren nach … Tagen</label>
                <input id="sso-tage" name="inaktiv_tage" type="number" min="0" max="3650" step="1" value="<?= (int) $z['inaktiv_tage'] ?>">
                <div class="help">Nur Konten „nur über SSO“; die Wartung deaktiviert sie. 0 = nie.</div></div>
        </div>
        <div class="card-footer btn-row"><button class="btn btn-primary" type="submit">Optionen speichern</button></div>
    </form>

    <div class="card" id="gruppen">
        <div class="card-head"><h2>Gruppen → Rollen</h2></div>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Gruppe</th><th>Rolle</th><th>seit</th><th>von</th><th></th></tr></thead>
                <tbody>
                <?php if ($zuordnungen === []): ?><tr><td colspan="5" class="muted">Noch keine Gruppe zugeordnet.</td></tr><?php endif; ?>
                <?php foreach ($zuordnungen as $zu): ?>
                    <tr>
                        <td data-label="Gruppe"><code><?= View::e((string) $zu['gruppe']) ?></code></td>
                        <td data-label="Rolle"><?= View::e((string) $zu['ziel_name']) ?></td>
                        <td data-label="seit" class="small"><?= View::e(Zeit::anzeige((string) $zu['created_at'], 'd.m.Y')) ?></td>
                        <td data-label="von" class="small"><?= View::e((string) $zu['created_by']) ?></td>
                        <td class="right">
                            <?php if ($darfBenutzer): ?>
                                <form method="post" action="/system/sso/zuordnung/<?= (int) $zu['id'] ?>/loeschen"
                                      data-confirm="Zuordnung der Gruppe <?= View::e((string) $zu['gruppe']) ?> entfernen? Wer die Rolle nur darüber hat, verliert sie sofort.">
                                    <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Entfernen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body">
            <?php if (!$darfBenutzer): ?>
                <p class="muted mb0">Gruppen Rollen zuordnen darf nur, wer Benutzer verwalten darf.</p>
            <?php elseif ($waehlbar === []): ?>
                <p class="muted mb0">Es gibt keine Rolle, die du einer Gruppe zuordnen darfst.</p>
            <?php else: ?>
                <form method="post" action="/system/sso/zuordnung">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="art" value="rolle">
                    <div class="field-row">
                        <div class="field"><label for="sso-gruppe">Gruppe beim Anmeldedienst</label>
                            <input id="sso-gruppe" name="gruppe" maxlength="150" required spellcheck="false" placeholder="pm-planung"></div>
                        <div class="field"><label for="sso-rolle">Rolle</label>
                            <select id="sso-rolle" name="ziel_id" required>
                                <?php foreach ($waehlbar as $r): ?>
                                    <option value="<?= (int) $r['id'] ?>"><?= View::e($r['name']) ?><?= $r['freigaben'] > 0 ? ' (' . (int) $r['freigaben'] . ' Freigaben)' : '' ?></option>
                                <?php endforeach; ?>
                            </select></div>
                    </div>
                    <button class="btn btn-primary" type="submit">Zuordnen</button>
                </form>
                <p class="help mt mb0">Zur Wahl stehen nur Rollen, die du selbst vergeben darfst<?= in_array(true, array_column($rollen, 'verwaltend'), true) ? '; Rollen mit Verwaltungsrechten erst mit „Verwaltung per SSO“' : '' ?>.
                    Wer in <?= View::e($name) ?> Gruppen pflegt, vergibt damit Rollen hier. Freigaben setzt du weiter hier – an der Rolle (gilt für alle Gruppenmitglieder) oder am Konto.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" id="anfragen">
    <div class="card-head"><h2>Anmeldeanfragen</h2></div>
    <div class="card-body">
        <p class="small muted mb0">Jemand hat sich bei <?= View::e($name) ?> angemeldet, hier gibt es dafür aber noch kein Konto. Verknüpfen oder ein Konto anlegen –
            Name und E-Mail stammen vom Anmeldedienst und sind nur ein Hinweis; der Vorschlag ist nur vorausgewählt. Nach 30 Tagen räumt die Wartung auf.</p>
    </div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Name dort</th><th>Name</th><th>E-Mail</th><th>Gruppen</th><th>Grund</th><th>zuletzt</th><th></th></tr></thead>
            <tbody>
            <?php if ($anfragen === []): ?><tr><td colspan="7" class="muted">Keine offenen Anmeldeanfragen.</td></tr><?php endif; ?>
            <?php foreach ($anfragen as $a): ?>
                <?php $aid = (int) $a['id']; ?>
                <tr>
                    <td data-label="Name dort"><strong><?= View::e((string) $a['name_dort'] !== '' ? (string) $a['name_dort'] : '–') ?></strong></td>
                    <td data-label="Name"><?= View::e((string) $a['voller_name'] !== '' ? (string) $a['voller_name'] : '–') ?></td>
                    <td data-label="E-Mail" class="small"><?= View::e((string) $a['email'] !== '' ? (string) $a['email'] : '–') ?>
                        <?= (string) $a['email'] !== '' ? ((int) $a['email_bestaetigt'] === 1 ? ' <span class="badge ok">✓ bestätigt</span>' : ' <span class="badge">unbestätigt</span>') : '' ?></td>
                    <td data-label="Gruppen" class="small"><?= $gruppenListe($a['gruppen']) ?></td>
                    <td data-label="Grund" class="small"><?= View::e($grundText[(string) $a['grund']] ?? (string) $a['grund']) ?></td>
                    <td data-label="zuletzt" class="small"><?= View::e(Zeit::anzeige((string) $a['letzte_am'])) ?><?= (int) $a['anzahl'] > 1 ? ' · ' . (int) $a['anzahl'] . '×' : '' ?></td>
                    <td class="right">
                        <?php if ($darfBenutzer): ?>
                            <div class="btn-row">
                                <?php if ($konten !== []): ?>
                                    <form method="post" action="/system/sso/anfrage/<?= $aid ?>/freischalten" class="inline-form"
                                          data-confirm="Diese Anmeldung mit dem gewählten Konto verknüpfen? Wer sich bei <?= View::e($name) ?> so anmeldet, ist hier dann dieses Konto.">
                                        <?= Csrf::field() ?>
                                        <select name="user_id" aria-label="Konto zum Verknüpfen" required>
                                            <option value="">Konto wählen …</option>
                                            <?php foreach ($konten as $k): ?>
                                                <option value="<?= (int) $k['id'] ?>"<?= (int) $k['id'] === (int) ($a['vorschlag'] ?? 0) ? ' selected' : '' ?>><?= View::e((string) $k['username']) ?><?= (string) $k['full_name'] !== '' ? ' – ' . View::e((string) $k['full_name']) : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm btn-primary" type="submit">Verknüpfen</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="/system/sso/anfrage/<?= $aid ?>/verwerfen" data-confirm="Diese Anmeldeanfrage verwerfen?">
                                    <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Verwerfen</button>
                                </form>
                            </div>
                            <details class="mt sso-neu">
                                <summary>Neues Konto</summary>
                                <form method="post" action="/system/sso/anfrage/<?= $aid ?>/anlegen">
                                    <?= Csrf::field() ?>
                                    <div class="field"><label for="sso-neu-<?= $aid ?>">Benutzername</label>
                                        <input id="sso-neu-<?= $aid ?>" name="username" maxlength="64" required autocomplete="off" value="<?= View::e((string) $a['name_dort']) ?>"></div>
                                    <?php foreach ($waehlbar as $r): ?>
                                        <label class="check"><input type="checkbox" name="rollen[]" value="<?= (int) $r['id'] ?>"> <?= View::e($r['name']) ?></label>
                                    <?php endforeach; ?>
                                    <div class="field"><label for="sso-bis-<?= $aid ?>">Konto gültig bis (leer: unbefristet)</label>
                                        <input id="sso-bis-<?= $aid ?>" name="gueltig_bis" type="date"></div>
                                    <button class="btn btn-sm btn-primary" type="submit">Konto anlegen</button>
                                    <p class="help mb0">Das Konto meldet sich nur über <?= View::e($name) ?> an. Die Rollen sind von Hand vergeben.</p>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$darfBenutzer && $anfragen !== []): ?>
        <div class="card-body"><p class="muted mb0">Freischalten darf nur, wer Benutzer verwalten darf.</p></div>
    <?php endif; ?>
</div>

<div class="card" id="verknuepft">
    <div class="card-head"><h2>Verknüpfte Konten</h2></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Benutzer</th><th>Name dort</th><th>Weg</th><th>zuletzt</th><th>Gruppen</th><th></th></tr></thead>
            <tbody>
            <?php if ($verknuepfungen === []): ?><tr><td colspan="6" class="muted">Noch kein Konto verknüpft.</td></tr><?php endif; ?>
            <?php foreach ($verknuepfungen as $v): ?>
                <?php $uid = (int) $v['user_id']; ?>
                <tr>
                    <td data-label="Benutzer"><strong><?= View::e((string) $v['username']) ?></strong>
                        <?= (int) $v['nur_sso'] === 1 ? ' <span class="badge info">nur SSO</span>' : '' ?>
                        <?= $v['kunde_id'] !== null ? ' <span class="badge">Kundenzugang</span>' : '' ?>
                        <?= $v['anderer_aussteller'] ? ' <span class="badge warn">anderer Aussteller</span>' : '' ?>
                        <?php if ((string) $v['full_name'] !== ''): ?><div class="small muted"><?= View::e((string) $v['full_name']) ?></div><?php endif; ?></td>
                    <td data-label="Name dort"><?= View::e((string) $v['name_dort'] !== '' ? (string) $v['name_dort'] : '–') ?></td>
                    <td data-label="Weg" class="small"><?= View::e($wegText[(string) $v['weg']] ?? (string) $v['weg']) ?></td>
                    <td data-label="zuletzt" class="small"><?= $v['zuletzt_am'] !== null ? View::e(Zeit::anzeige((string) $v['zuletzt_am'])) : 'noch nie' ?></td>
                    <td data-label="Gruppen" class="small"><?= $gruppenListe($v['gruppen']) ?></td>
                    <td class="right">
                        <?php if ($v['verwaltbar']): ?>
                            <form method="post" action="/system/benutzer/<?= $uid ?>/sso" class="btn-row">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="zurueck" value="sso">
                                <button class="btn btn-sm" type="submit" name="aktion" value="sitzungen"
                                        data-confirm="Die laufenden Sitzungen von <?= View::e((string) $v['username']) ?> über <?= View::e($name) ?> beenden?">SSO-Sitzungen beenden</button>
                                <button class="btn btn-sm btn-ghost" type="submit" name="aktion" value="loesen"
                                        data-confirm="Die Verknüpfung von <?= View::e((string) $v['username']) ?> lösen? Seine Sitzungen über <?= View::e($name) ?> und Rollen aus Gruppen enden.">Lösen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

