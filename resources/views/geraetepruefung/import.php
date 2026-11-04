<?php
/*
 * Import einer Datenbank des Prüfgeräts: Datei wählen (Vorschau folgt auf
 * derselben Seite), Vorlage für den Export, die letzten Importe.
 * Erwartet $letzte (GeraeteImport::letzte), $vorlage (BenningVorlage::aktiv oder null).
 */
use App\Geraetepruefung\BenningDatei;
use App\Services\SiteService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Geräteprüfung: Import';
$subtitle = 'Datenbank des BENNING ST 755+ lesen – erst Vorschau, dann übernehmen';
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="/geraetepruefung">← Geräteprüfung</a>
    <a class="btn btn-sm btn-ghost" href="/geraetepruefung/export">Export für das Prüfgerät</a>
    <a class="btn btn-sm btn-ghost" href="/geraetepruefung/pruefablaeufe">Prüfabläufe</a>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Datei hochladen</h2><span class="hint">.db oder Sicherung (…_Backup.001), höchstens <?= View::e(SiteService::mb(BenningDatei::MAX_BYTES)) ?></span></div>
        <form method="post" action="/geraetepruefung/import/vorschau" enctype="multipart/form-data" data-laeuft="liest …"
              data-laeuft-hinweis="Die Datenbank wird gelesen und mit dem Bestand verglichen. Bitte nicht noch einmal klicken.">
            <?= Csrf::field() ?>
            <div class="card-body">
                <div class="field">
                    <label for="gp-datei">Datenbank des Prüfgeräts</label>
                    <input id="gp-datei" type="file" name="datei" required
                           accept=".db,.001,.002,.003,.004,.005,.006,.007,.008,.009,application/vnd.sqlite3,application/x-sqlite3"
                           data-max-bytes="<?= (int) BenningDatei::MAX_BYTES ?>" data-max-text="<?= View::e(SiteService::mb(BenningDatei::MAX_BYTES)) ?>"
                           data-max-rat="Das reicht für rund 10 000 Prüfungen – eine Sicherung mit gut 1 200 Prüfungen hat etwa 2 MB. Ist es die richtige Datei?">
                    <div class="help">Die Datei liegt auf der SD-Karte des Geräts (die <code>.db</code> oder eine Sicherung aus dem Ordner
                        „Backups“). Sie wird nur gelesen; übernommen wird erst nach der Vorschau.</div>
                </div>
            </div>
            <div class="card-footer btn-row">
                <button class="btn btn-primary" type="submit">Vorschau</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-head"><h2>Vorlage für den Export</h2></div>
        <div class="card-body small">
            <?php if ($vorlage === null): ?>
                <p class="mb0 muted">Noch keine Vorlage. Beim ersten Import lassen sich die Einstellungen des Geräts (Prüfabläufe,
                    Grenzwerte, Fragen der Sichtprüfung) als Vorlage übernehmen – ohne Kunden, Geräte und Prüfungen. Aus ihr entsteht
                    die Datei, die PowerManagement für das Prüfgerät schreibt.</p>
            <?php else: ?>
                <dl class="kv">
                    <dt>Übernommen</dt><dd><?= View::e(Zeit::anzeige((string) $vorlage['created_at'])) ?></dd>
                    <dt>Prüfgerät</dt><dd><?= View::e(trim($vorlage['messgeraet'] . ' ' . $vorlage['messgeraet_sn']) ?: '–') ?></dd>
                    <dt>DB-Version</dt><dd><?= View::e($vorlage['db_version'] ?: '–') ?></dd>
                    <dt>Größe</dt><dd><?= View::e(number_format($vorlage['groesse'] / 1024, 0, ',', '.')) ?> KB · <span class="mono"><?= View::e(substr((string) $vorlage['sha256'], 0, 12)) ?></span></dd>
                </dl>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Letzte Importe</h2></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Wann</th><th>Datei</th><th>Prüfgerät</th><th class="num">Prüflinge neu</th><th class="num">Prüfungen neu</th><th>Wer</th></tr></thead>
            <tbody>
            <?php if ($letzte === []): ?><tr><td colspan="6" class="muted">Noch kein Import.</td></tr><?php endif; ?>
            <?php foreach ($letzte as $i): ?>
                <tr>
                    <td data-label="Wann"><?= View::e(Zeit::anzeige((string) $i['created_at'])) ?></td>
                    <td data-label="Datei" class="small"><?= View::e((string) $i['dateiname'] ?: '–') ?></td>
                    <td data-label="Prüfgerät" class="small"><?= View::e(trim($i['messgeraet'] . ' ' . $i['messgeraet_sn']) ?: '–') ?></td>
                    <td data-label="Prüflinge neu" class="num"><?= (int) ($i['zahlen']['prueflinge_neu'] ?? 0) ?></td>
                    <td data-label="Prüfungen neu" class="num"><?= (int) ($i['zahlen']['pruefungen_neu'] ?? 0) ?></td>
                    <td data-label="Wer" class="small"><?= View::e((string) ($i['username'] ?? '–')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>So geht der Import</h2></div>
    <div class="card-body small">
        <p>Die Vorschau zeigt je <strong>Kunde im Prüfgerät</strong>, wohin er gehört: zum eigenen Bestand, zu einem Kunden hier, als
            neuer Kunde – oder gar nicht. Vorgeschlagen wird nur, was sicher ist (die Kundennummer, die PowerManagement beim Export
            schreibt, eine gleiche Kundennummer, die Zuordnung vom letzten Import); alles andere steht auf „nicht übernehmen“.
            <strong>Zugeordnet wird nie still.</strong></p>
        <p>Je <strong>Prüfling</strong> steht da, ob er neu ist, schon genauso da oder <strong>hier anders</strong> – dann bleibt er,
            wie er ist, außer du hakst <em>ersetzen</em> an. <strong>Prüfungen</strong> sind Beweise: Jede kommt genau einmal, auch wenn
            dieselbe Datei oder eine Sicherung noch einmal gelesen wird.</p>
        <p class="mb0">Bewertet hat das Prüfgerät. PowerManagement leitet das Ergebnis nur ab: Ein Schritt, ein Wert oder eine
            Antwort der Sichtprüfung, die nicht in Ordnung ist, macht die Prüfung „nicht bestanden“ – auch wenn das Gerät insgesamt
            „bestanden“ meldet.</p>
    </div>
</div>
