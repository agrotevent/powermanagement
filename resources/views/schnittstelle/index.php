<?php
/**
 * Schnittstelle (API): Zugänge, die diese Instanz an andere Instanzen
 * ausgibt – Liste und „Neuer Zugang“.
 *
 * @var list<array<string,mixed>> $tokens ApiZugaenge::alle() – ohne „alles“ nur sichtbare Standorte (der Rest als standorte_weitere)
 * @var array<string,string> $befugnisse
 * @var array<string,bool> $vergebbar
 * @var bool $darf Zugriff::alles() – anlegen, ändern, anzeigen, löschen
 * @var list<array<string,mixed>> $standorte
 * @var list<array{id:int,username:string,aktiv:bool}> $benutzer
 * @var string $adresse …/api/v1
 * @var bool $vermutet app.url fehlt – Adresse aus dieser Anfrage
 * @var string $einmal
 * @var array<string,mixed> $eingabe
 */
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Schnittstelle (API)';
$subtitle = 'Zugänge für andere Instanzen – Grundrisse, Maßstab, Georeferenz und Feststrom teilen';
$zustandBadge = ['aktiv' => 'ok', 'abgelaufen' => 'warn', 'widerrufen' => 'bad', 'ruht' => 'warn'];
$zustandText = ['aktiv' => 'aktiv', 'abgelaufen' => 'abgelaufen', 'widerrufen' => 'widerrufen', 'ruht' => 'ruht – Benutzer gesperrt'];
?>
<div class="card">
    <div class="card-body">
        <p>Eine andere Instanz – MasterAdmin oder PowerManagement – trägt hier ein Token ein und übernimmt dann die freigegebenen
            Standorte mit Etagen, Grundrissen, Maßstab und Georeferenz, mit „Feststrom“ auch Feststromanschlüsse und fest verlegte Kabel.
            Zurück kommen Änderungen als <strong>Vorschlag</strong>, den hier jemand bestätigt – oder mit „schreiben“ direkt, aber nur auf
            einem Stand, den die andere Seite kennt.</p>
        <p class="small mb0">Adresse für die andere Instanz:
            <code><?= View::e($adresse) ?></code> (dort ohne <code>/api/v1</code> eintragen)
            <?php if ($vermutet): ?><br><span class="badge warn">app.url ist nicht gesetzt</span> – die Adresse ist aus dieser Anfrage
                abgeleitet und stimmt hinter einem Proxy oft nicht. In <code>config/config.php</code> unter <code>app.url</code> die
                Adresse eintragen, unter der andere Instanzen diese erreichen.<?php endif; ?></p>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Zugänge</h2></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Name</th><th>Token</th><th>Standorte</th><th>Befugnisse</th><th>Zuletzt</th><th>Zustand</th><th></th></tr></thead>
            <tbody>
            <?php if ($tokens === []): ?><tr><td colspan="7" class="muted">Noch kein Zugang angelegt.</td></tr><?php endif; ?>
            <?php foreach ($tokens as $t): ?>
                <?php $id = (int) $t['id']; $zustand = (string) $t['zustand']; ?>
                <tr<?= $zustand === 'aktiv' ? '' : ' class="muted"' ?>>
                    <td data-label="Name"><strong><?= View::e((string) $t['name']) ?></strong>
                        <?php if ((string) $t['notiz'] !== ''): ?><div class="small muted"><?= View::e((string) $t['notiz']) ?></div><?php endif; ?>
                        <?php if (($t['user_name'] ?? null) !== null): ?><div class="small muted">gilt, solange <?= View::e((string) $t['user_name']) ?> aktiv ist</div>
                        <?php elseif ($t['user_id'] !== null): ?><div class="small muted">an ein gelöschtes Konto gebunden</div><?php endif; ?>
                    </td>
                    <td data-label="Token">
                        <span class="mono small"><?= View::e((string) $t['token_prefix']) ?>…</span>
                        <?php if ($darf && $t['abrufbar'] && $t['verwaltbar']): ?>
                            <form method="post" action="/system/schnittstelle/<?= $id ?>/anzeigen" class="api-anzeigen">
                                <?= Csrf::field() ?>
                                <input type="password" name="kennwort" required autocomplete="current-password" placeholder="eigenes Kennwort"
                                       aria-label="Eigenes Kennwort, um das Token von <?= View::e((string) $t['name']) ?> anzuzeigen">
                                <button class="btn btn-sm" type="submit">Anzeigen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td data-label="Standorte" class="small">
                        <?php $weitere = (int) ($t['standorte_weitere'] ?? 0); ?>
                        <?php if ($t['standorte'] === [] && $weitere === 0): ?><span class="badge warn">keine</span>
                        <?php else: ?><?= implode(', ', array_map(static fn (array $s): string
                            => View::e($s['name']) . ($s['bearbeiten'] ? ' <span class="muted">(bearbeiten)</span>' : ''), $t['standorte'])) ?>
                            <?php if ($weitere > 0): ?><span class="muted"><?= $t['standorte'] !== [] ? '+ ' : '' ?><?= $weitere ?> <?= $weitere === 1 ? 'weiterer, den' : 'weitere, die' ?> du nicht siehst</span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Befugnisse" class="small"><?= $t['befugnisse'] === [] ? '<span class="muted">–</span>'
                        : implode(', ', array_map(static fn (string $b): string => '<code>' . View::e($b) . '</code>', $t['befugnisse'])) ?></td>
                    <td data-label="Zuletzt" class="small">
                        <?php if ($t['last_used_at'] !== null): ?><?= View::e(Zeit::anzeige((string) $t['last_used_at'])) ?>
                            <div class="muted mono"><?= View::e((string) $t['last_ip']) ?></div>
                        <?php else: ?><span class="muted">nie</span><?php endif; ?>
                    </td>
                    <td data-label="Zustand">
                        <span class="badge <?= View::e($zustandBadge[$zustand] ?? '') ?>"><?= View::e($zustandText[$zustand] ?? $zustand) ?></span>
                        <?php if ($zustand === 'aktiv' && $t['expires_at'] !== null): ?><div class="small muted">bis <?= View::e(Zeit::anzeige((string) $t['expires_at'], 'd.m.Y')) ?></div><?php endif; ?>
                    </td>
                    <td class="right">
                        <div class="btn-row">
                            <?php if ($darf && $zustand !== 'widerrufen'): ?>
                                <a class="btn btn-sm" href="/system/schnittstelle/<?= $id ?>">Ändern</a>
                            <?php endif; ?>
                            <?php if ($zustand !== 'widerrufen'): ?>
                                <form method="post" action="/system/schnittstelle/<?= $id ?>/widerrufen"
                                      data-confirm="<?= View::e('Zugang „' . $t['name'] . '“ widerrufen? Die andere Instanz kommt danach nicht mehr herein, und das Token lässt sich nicht mehr anzeigen.') ?>">
                                    <?= Csrf::field() ?>
                                    <button class="btn btn-sm btn-ghost" type="submit">Widerrufen</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($darf && in_array($zustand, ['widerrufen', 'abgelaufen'], true)): ?>
                                <form method="post" action="/system/schnittstelle/<?= $id ?>/loeschen"
                                      data-confirm="<?= View::e('Zugang „' . $t['name'] . '“ löschen?') ?>">
                                    <?= Csrf::field() ?>
                                    <button class="btn btn-sm btn-ghost" type="submit">Löschen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($darf): ?>
    <form method="post" action="/system/schnittstelle/anlegen" class="card" id="neuer-zugang">
        <?= Csrf::field() ?>
        <input type="hidden" name="einmal" value="<?= View::e($einmal) ?>">
        <div class="card-head"><h2>Neuer Zugang</h2></div>
        <div class="card-body">
            <?= View::partial('schnittstelle/felder', [
                'eingabe'    => $eingabe,
                'befugnisse' => $befugnisse,
                'vergebbar'  => $vergebbar,
                'benutzer'   => $benutzer,
                'standorte'  => $standorte,
            ]) ?>
            <p class="small muted">Das Token erscheint nach dem Anlegen genau einmal. Später lässt es sich mit dem eigenen Kennwort
                noch einmal anzeigen; widerrufen löscht es endgültig.</p>
            <button class="btn btn-primary" type="submit">Zugang anlegen</button>
        </div>
    </form>
<?php else: ?>
    <div class="notice info">Zugänge anlegen, ändern und anzeigen darf nur, wer alle Standorte sieht (Recht
        „Alle Standorte, Anlagen und Messdaten“): was ein Token sieht, regeln nicht die eigenen Freigaben. Widerrufen geht auch so.</div>
<?php endif; ?>
