<?php
use App\Services\UpdateService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

/**
 * System → Update. Mobil zuerst: Karten untereinander, Tabellen als Karten.
 * Ausgegeben wird nur über View::e – Betreffe und Hinweise kommen aus
 * Commits. Das Verhalten (Fortschritt abfragen) steckt in update.js, die
 * Formulare gehen ohne JavaScript ganz normal.
 */
$title = 'Update';
$subtitle = 'PowerManagement auf den neuesten Stand bringen';

$z = $zustand['zustand'];
$lauf = $zustand['lauf'];
$wurzel = APP_ROOT;
$benutzer = 'www-data';
if (function_exists('posix_getpwuid')) {
    $besitzer = @fileowner($wurzel . '/storage');
    $name = $besitzer !== false && $besitzer !== 0 ? (posix_getpwuid($besitzer)['name'] ?? '') : '';
    $benutzer = preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $name) === 1 ? $name : 'www-data';
}
$kurz = static fn (?string $sha): string => $sha !== null ? substr($sha, 0, 7) : '–';
$zeit = static fn (?int $ts, string $format = 'd.m.Y H:i'): string => $ts !== null ? Zeit::anzeige(Zeit::iso($ts), $format) : '–';
$vor = static fn (?int $ts): string => $ts !== null ? Zeit::abstand(Zeit::iso($ts)) : 'nie';
$commitLink = static function (string $sha) use ($stand): string {
    $text = '<span class="mono">' . View::e(substr($sha, 0, 7)) . '</span>';
    if (($stand['quelle'] ?? null) === null || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
        return $text;
    }
    return '<a href="' . View::e($stand['quelle'] . '/commit/' . $sha) . '" rel="noopener noreferrer" target="_blank">' . $text . '</a>';
};
$ausloeserText = static fn (array $e): string => match ($e['ausloeser'] ?? '') {
    'hand'      => 'von Hand' . (($e['von'] ?? '') !== '' ? ': ' . $e['von'] : ''),
    'automatik' => 'automatisch',
    'system'    => 'Update-Dienst',
    default     => 'Konsole',
};

// Das letzte echte Update (ohne „nicht begonnen“) – für Banner und Fortschritt.
$letztes = null;
foreach ($verlauf as $e) {
    if ($e['ergebnis'] !== 'nicht_begonnen') {
        $letztes = $e;
        break;
    }
}
$unvollstaendig = $zustand['offen'] === 'aufgegeben' || ($letztes !== null && $letztes['ergebnis'] === 'unvollstaendig');
$befehlUmstieg = "systemd-run --unit=pm-umstieg --collect --setenv=HOME=/root \\\n  /bin/bash -c '"
    . $wurzel . '/deploy/update-debian.sh && ' . $wurzel . "/deploy/update-debian.sh'\njournalctl -a -f -u pm-umstieg";
$befehlNotfall = "journalctl -a -u powermanagement-update -n 200 --no-pager\ncat /var/lib/powermanagement/update/offen\n"
    . $wurzel . '/deploy/update-debian.sh --wiederaufnehmen';
$befehlJournal = 'journalctl -a -u powermanagement-update -n 200 --no-pager';
$befehlHaengt = "systemctl reset-failed powermanagement-update.service powermanagement-update.path\nsystemctl restart powermanagement-update.path";

// ------------------------------------------------------------ Zustand als Satz
$laufText = static function (array $zustand) use ($kurz): string {
    $lauf = $zustand['lauf'];
    return match (true) {
        $zustand['zustand'] === 'demo'               => 'Im Demo-Modus läuft kein Update.',
        $zustand['zustand'] === 'nicht_eingerichtet' => 'Die Update-Funktion ist noch nicht eingerichtet.',
        $zustand['zustand'] === 'angefordert'        => $zustand['haengt']
            ? 'Der Auftrag wird nicht übernommen – der Update-Dienst reagiert nicht.'
            : 'Auftrag liegt bereit – der Update-Dienst übernimmt ihn gleich.',
        $zustand['zustand'] === 'laeuft'             => ($zustand['keine_rueckmeldung']
            ? 'Keine Rückmeldung vom Update-Dienst seit ' . UpdateService::dauerText($zustand['puls_alter']) . ' – '
            : '') . match ($lauf['aktion'] ?? null) {
                'pruefen'         => 'Suche läuft …',
                'wiederaufnehmen' => 'Ein unterbrochenes Update wird zurückgerollt …',
                default           => 'Update läuft …',
            },
        $zustand['verschwunden']                     => 'Der Update-Dienst meldet sich seit über 30 Minuten nicht mehr – ein unterbrochenes Update rollt er beim nächsten Start zurück.',
        $lauf !== null && $lauf['ergebnis'] !== null => UpdateService::ergebnisText($lauf['ergebnis'])
            . ($lauf['grund'] !== null ? '. ' . UpdateService::grundText($lauf['grund']) : '')
            . ($lauf['neu'] !== null && $lauf['alt'] !== null && $lauf['neu'] !== $lauf['alt'] ? ' (' . $kurz($lauf['alt']) . ' → ' . $kurz($lauf['neu']) . ')' : ''),
        default                                      => 'Noch kein Lauf.',
    };
};
$aktiv = UpdateService::aktiv($zustand);
$fehlerLauf = $lauf !== null && $lauf['zustand'] === 'fehler' && !$aktiv;
// Läuft gerade etwas (oder endete eben), steht der Fortschritt oben – auf dem Handy sonst weit unten.
$fortschrittOben = $aktiv || ($lauf !== null && $lauf['ende'] !== null && time() - $lauf['ende'] < 3600);
?>
<?php // ------------------------------------------------------------ Fortschritt (gepuffert: oben oder an seinem Platz) ?>
<?php
ob_start();
$schritt = $lauf['schritt'] ?? 0;
$gesamt = max(1, (int) ($lauf['gesamt'] ?? 12));
$prozent = $lauf === null ? 0 : ($lauf['zustand'] === 'laeuft' ? (int) round(100 * min($schritt, $gesamt) / $gesamt) : 100);
$stufe = $aktiv ? '' : ($lauf !== null ? UpdateService::ergebnisStufe($lauf['ergebnis']) : '');
?>
<div class="card" id="fortschritt" data-update-seite data-stand-url="/system/update/stand"
     data-installiert="<?= View::e((string) ($installiert['commit'] ?? '')) ?>" data-zustand="<?= View::e($z) ?>"
     data-protokoll-stand="<?= View::e((string) ($protokoll['stand'] ?? '')) ?>">
    <div class="card-head"><h2>Fortschritt</h2><span class="spacer"></span><span class="hint" data-update-laufzeit><?php
        if ($lauf !== null && $lauf['beginn'] !== null): ?><?= View::e(UpdateService::dauerText(max(0, ($lauf['zustand'] === 'laeuft' ? time() : (int) $lauf['ende']) - $lauf['beginn']))) ?><?php
        endif; ?></span></div>
    <div class="card-body">
        <div class="update-fortschritt">
            <div class="update-fortschritt-kopf">
                <span data-update-text role="status"><?= View::e($laufText($zustand)) ?></span>
                <span class="mono" data-update-schritt><?= $aktiv && $lauf !== null && ($lauf['zustand'] === 'laeuft') ? (int) $schritt . ' / ' . $gesamt : '' ?></span>
            </div>
            <div class="progress gross update-balken<?= $stufe === 'ok' ? ' ok' : ($stufe === 'bad' ? ' bad' : '') ?><?= $z === 'angefordert' ? ' unbestimmt' : '' ?>"
                 role="progressbar" aria-label="Fortschritt" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $prozent ?>" data-update-balken>
                <span style="width: <?= $prozent ?>%"></span>
            </div>
            <div class="small muted" data-update-schrittname><?= $lauf !== null && $lauf['zustand'] === 'laeuft' ? View::e($lauf['schritt_name']) : '' ?></div>
        </div>
        <div class="small" data-update-hinweis hidden></div>
        <?php if (!$aktiv && $lauf !== null && $lauf['warnungen'] !== []): ?>
            <ul class="small" data-update-warnungen>
                <?php foreach ($lauf['warnungen'] as $w): ?><li><?= View::e(UpdateService::warnungText($w)) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php $zeilen = $protokoll !== null ? implode("\n", array_slice(explode("\n", rtrim($protokoll['text'], "\n")), -200)) : ''; ?>
        <pre class="update-protokoll mono" data-update-protokoll<?= $zeilen === '' ? ' hidden' : '' ?>><?= View::e($zeilen) ?></pre>
        <?php if ($protokoll !== null && $protokoll['text'] !== '' && !$aktiv): ?>
            <details class="update-einzelheiten">
                <summary>Ganzes Protokoll</summary>
                <pre class="update-protokoll lang mono"><?= View::e($protokoll['text']) ?></pre>
            </details>
        <?php endif; ?>
        <div data-update-konsole<?= $fehlerLauf || $zustand['verschwunden'] ? '' : ' hidden' ?>>
            <p class="small">Mehr steht im Journal des Update-Dienstes (als root auf der Konsole):</p>
            <pre class="update-befehl mono"><?= View::e($befehlJournal) ?></pre>
        </div>
    </div>
</div>
<?php $fortschrittKarte = (string) ob_get_clean(); ?>

<?php if ($aktiv): ?><noscript><meta http-equiv="refresh" content="5"></noscript><?php endif; ?>

<?php // ------------------------------------------------------------ höchstens ein Banner ?>
<?php if ($z === 'demo'): ?>
    <div class="notice">Demo-Modus: Updates sind hier abgeschaltet. Die Seite zeigt, wie es auf einem eingerichteten System aussieht.</div>
<?php elseif ($z === 'nicht_eingerichtet'): ?>
    <div class="notice warn">Die Update-Funktion ist auf diesem System noch nicht eingerichtet. Einmal als root auf der Konsole umsteigen
        (dauert 2–5 Minuten, Messung und Schalten sind dabei kurz unterbrochen):
        <pre class="update-befehl mono"><?= View::e($befehlUmstieg) ?></pre></div>
<?php elseif ($unvollstaendig): ?>
    <div class="notice danger">Das letzte Update ließ sich nicht vollständig zurückrollen – Updates bleiben gesperrt, bis jemand auf der Konsole nachsieht:
        <pre class="update-befehl mono"><?= View::e($befehlNotfall) ?></pre></div>
<?php elseif ($zustand['haengt']): ?>
    <div class="notice warn">Der Auftrag wird nicht übernommen. Als root auf der Konsole:
        <pre class="update-befehl mono"><?= View::e($befehlHaengt) ?></pre></div>
<?php elseif ($zustand['still']): ?>
    <div class="notice warn">Der Update-Dienst meldet sich seit <?= View::e(UpdateService::dauerText($zustand['helfer_alter'])) ?> nicht. Als root auf der Konsole:
        <pre class="update-befehl mono">systemctl status powermanagement-update.path powermanagement-update.timer</pre></div>
<?php elseif ($zustand['abgeschaltet']): ?>
    <div class="notice">Updates aus der Oberfläche sind auf diesem System abgeschaltet (<span class="mono">/etc/powermanagement/update.conf</span>) – nur über die Konsole.</div>
<?php endif; ?>

<?php if ($fortschrittOben): ?><?= $fortschrittKarte ?><?php endif; ?>

<?php // ------------------------------------------------------------ Stand ?>
<div class="card" id="stand">
    <div class="card-head"><h2>Stand</h2></div>
    <div class="card-body">
        <dl class="kv">
            <dt>Installiert</dt>
            <dd>
                <?php if ($installiert['commit'] !== null): ?>
                    <span class="mono"><?= View::e($installiert['kurz']) ?></span><?php if ($installiert['zeit'] !== null): ?> vom <?= View::e($zeit($installiert['zeit'])) ?><?php endif; ?>
                    <?php if ($installiert['betreff'] !== ''): ?><div class="small muted"><?= View::e($installiert['betreff']) ?></div><?php endif; ?>
                <?php else: ?><span class="muted">unbekannt</span><?php endif; ?>
            </dd>
            <dt>Zweig</dt>
            <dd><?= $installiert['zweig'] !== null ? '<span class="mono">' . View::e($installiert['zweig']) . '</span>' : '<span class="muted">–</span>' ?></dd>
            <dt>Verfügbar</dt>
            <dd>
                <?php if ($stand === null): ?>
                    <span class="muted">noch nicht gesucht</span>
                <?php else: ?>
                    <?php if (in_array($stand['abruf'], ['abruf', 'kein_zugang'], true)): ?>
                        <span class="badge warn">Suche fehlgeschlagen</span>
                        <div class="small muted"><?= View::e(UpdateService::grundText($stand['abruf'])) ?></div>
                    <?php endif; ?>
                    <?php if ($stand['zweig'] === null): ?><span class="badge bad">kein Zweig ausgecheckt</span>
                    <?php elseif ($stand['lokal_geaendert']): ?><span class="badge bad">Lokale Änderungen im Code</span> <span class="small muted">nur über die Konsole</span>
                    <?php elseif (!$stand['linear'] && $stand['verfuegbar'] !== null): ?><span class="badge bad">Verlauf nicht linear</span> <span class="small muted">nur über die Konsole</span>
                    <?php elseif ($stand['neu'] > 0): ?><span class="badge primary"><?= (int) $stand['neu'] ?> Änderung<?= $stand['neu'] === 1 ? '' : 'en' ?></span>
                        <span class="small">bis <span class="mono"><?= View::e($kurz($stand['verfuegbar']['commit'] ?? null)) ?></span><?php if (($stand['verfuegbar']['zeit'] ?? null) !== null): ?> vom <?= View::e($zeit($stand['verfuegbar']['zeit'])) ?><?php endif; ?></span>
                    <?php elseif ($stand['abruf'] !== 'nie'): ?><span class="badge ok">Aktuell</span>
                    <?php endif; ?>
                <?php endif; ?>
            </dd>
            <dt>Zuletzt gesucht</dt>
            <dd><?= View::e($vor($stand['abruf_zeit'] ?? null)) ?></dd>
        </dl>
        <form method="post" action="/system/update/suchen" class="mt">
            <?= Csrf::field() ?>
            <button class="btn" type="submit"<?= $sperreSuchen !== null ? ' disabled' : '' ?>>Jetzt suchen</button>
            <?php if ($sperreSuchen !== null && $z !== 'demo'): ?><span class="help"><?= View::e($sperreSuchen) ?></span><?php endif; ?>
        </form>
    </div>
</div>

<?php // ------------------------------------------------------------ Was neu ist ?>
<?php if ($stand !== null && $stand['neu'] > 0 && $stand['linear'] && $stand['commits'] !== []): ?>
<div class="card" id="neu">
    <div class="card-head"><h2>Was neu ist</h2><span class="hint"><?= (int) $stand['neu'] ?> Änderung<?= $stand['neu'] === 1 ? '' : 'en' ?></span></div>
    <div class="card-body">
        <?php $kennzeichen = array_filter([
            $stand['migrationen'] ? 'Die Datenbank wird erweitert (zurück nur mit der Sicherung).' : null,
            $stand['einrichtung'] ? 'Einrichtung und Dienste ändern sich – das Update dauert etwas länger.' : null,
            $stand['flows'] ? 'Die Node-RED-Flows ändern sich (im Editor geänderte bleiben, die neue Fassung liegt daneben).' : null,
        ]); ?>
        <?php foreach ($kennzeichen as $k): ?><div class="notice"><?= View::e($k) ?></div><?php endforeach; ?>
        <?php if ($stand['hinweise'] !== []): ?>
            <div class="notice warn"><strong>Hinweise zu diesem Update</strong>
                <ul class="mb0">
                <?php foreach ($stand['hinweise'] as $h): ?><li><span class="mono"><?= View::e($h['kurz']) ?></span> <?= View::e($h['text']) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Datum</th><th>Stand</th><th>Änderung</th></tr></thead>
            <tbody>
            <?php foreach ($stand['commits'] as $c): ?>
                <tr>
                    <td data-label="Datum" class="nowrap"><?= View::e($zeit($c['zeit'], 'd.m.Y')) ?></td>
                    <td data-label="Stand"><?= $commitLink($c['commit']) ?></td>
                    <td data-label="Änderung"><?= View::e($c['betreff']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($stand['weitere'] > 0): ?>
                <tr><td colspan="3" class="muted">… und <?= (int) $stand['weitere'] ?> weitere</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php // ------------------------------------------------------------ Aktualisieren ?>
<div class="card" id="aktualisieren">
    <div class="card-head"><h2>Aktualisieren</h2></div>
    <div class="card-body">
        <p>Vorher werden Datenbank und Konfiguration gesichert. Messung und Schalten sind etwa 1–3 Minuten unterbrochen, die Oberfläche ist kurz
            nicht erreichbar, und Änderungen sind solange gesperrt. Geht etwas schief, kommt der alte Stand von selbst zurück.
            <?php if ($dauerZuletzt !== null): ?><span class="muted">Das letzte Update dauerte <?= View::e(UpdateService::dauerText($dauerZuletzt)) ?>.</span><?php endif; ?></p>
        <form method="post" action="/system/update/starten"
              data-confirm="<?= View::e('PowerManagement jetzt auf ' . substr((string) $ziel, 0, 7) . ' aktualisieren? Messung und Schalten sind dabei 1–3 Minuten unterbrochen.') ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="ziel" value="<?= View::e((string) $ziel) ?>">
            <?php if ($hindernisse !== [] && $sperreStarten === null): ?>
                <div class="notice warn">
                    <ul class="mb0"><?php foreach ($hindernisse as $h): ?><li><?= View::e($h['text']) ?></li><?php endforeach; ?></ul>
                    Ein Update trennt die Shellys kurz und unterbricht Messung und Schalten.
                </div>
                <label class="check"><input type="checkbox" name="trotz_betrieb" value="1"> Trotzdem jetzt aktualisieren</label>
            <?php endif; ?>
            <div class="btn-row">
                <button class="btn btn-primary" type="submit"<?= $sperreStarten !== null ? ' disabled' : '' ?>>Jetzt aktualisieren</button>
            </div>
            <?php if ($sperreStarten !== null && $z !== 'demo'): ?><p class="help"><?= View::e($sperreStarten) ?></p><?php endif; ?>
        </form>
    </div>
</div>

<?php if (!$fortschrittOben): ?><?= $fortschrittKarte ?><?php endif; ?>

<?php // ------------------------------------------------------------ Verlauf ?>
<div class="card" id="verlauf">
    <div class="card-head"><h2>Verlauf</h2><span class="hint">die letzten Updates, ohne reine Suchen</span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Zeit</th><th>Auslöser</th><th>Ergebnis</th><th>Stand</th><th>Dauer</th></tr></thead>
            <tbody>
            <?php if ($verlauf === []): ?>
                <tr><td colspan="5" class="muted">Noch kein Update.</td></tr>
            <?php endif; ?>
            <?php foreach ($verlauf as $e): ?>
                <tr>
                    <td data-label="Zeit" class="nowrap"><?= View::e($zeit($e['beginn'])) ?></td>
                    <td data-label="Auslöser"><?= View::e($e['aktion'] === 'wiederaufnehmen' ? 'Wiederanlauf' : $ausloeserText($e)) ?></td>
                    <td data-label="Ergebnis">
                        <span class="badge <?= View::e(UpdateService::ergebnisStufe($e['ergebnis'])) ?>"><?= View::e(UpdateService::ergebnisText($e['ergebnis'])) ?></span>
                        <?php if ($e['grund'] !== null): ?><div class="small muted"><?= View::e(UpdateService::grundText($e['grund'])) ?></div><?php endif; ?>
                    </td>
                    <td data-label="Stand" class="mono nowrap"><?= View::e($kurz($e['alt'])) ?><?= $e['neu'] !== null && $e['neu'] !== $e['alt'] ? ' → ' . View::e($kurz($e['neu'])) : '' ?></td>
                    <td data-label="Dauer" class="nowrap"><?= View::e(UpdateService::dauerText($e['dauer'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php // ------------------------------------------------------------ Konsole ?>
<details class="card update-konsole" id="konsole"<?= $z === 'nicht_eingerichtet' ? ' open' : '' ?>>
    <summary class="card-head"><h2>Auf der Konsole</h2></summary>
    <div class="card-body">
        <?php if ($z === 'nicht_eingerichtet'): ?>
            <p>Einmal umsteigen auf die Fassung mit Update-Funktion (als root im Container):</p>
            <pre class="update-befehl mono"><?= View::e($befehlUmstieg) ?></pre>
        <?php endif; ?>
        <p>Derselbe Weg wie hier – ein Auftrag an den Update-Dienst, Fortschritt im Terminal. Strg+C oder ein Verbindungsabbruch beenden nur die Anzeige:</p>
        <pre class="update-befehl mono"><?= View::e('cd ' . $wurzel . ' && runuser -u ' . $benutzer . ' -- php bin/power update:starten --warten') ?></pre>
        <p>Nachsehen, mitlesen, letzten Lauf ansehen:</p>
        <pre class="update-befehl mono"><?= View::e('cd ' . $wurzel . ' && runuser -u ' . $benutzer . " -- php bin/power update:stand\n"
            . "journalctl -a -f -u powermanagement-update\njournalctl -a -u powermanagement-update -I") ?></pre>
        <p class="small muted"><span class="mono">bin/power</span> nie als root aufrufen – immer als Anwendungsbenutzer (<span class="mono">runuser -u <?= View::e($benutzer) ?> -- …</span>).</p>
    </div>
</details>
