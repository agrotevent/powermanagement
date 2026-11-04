<?php
/**
 * Reiter Stammdaten: Name, Kundennummer, aktiv, Ende, Notizen, dazu
 * Anschrift und Kontakt (freiwillig, für Prüfberichte der Geräteprüfung und
 * die Datei des Prüfgeräts – nie im Kundenbereich) – ändern und löschen nur
 * mit „kunden.manage“. Gelöscht wird nur ein beendeter Kunde ohne Prüflinge
 * der Geräteprüfung, mit seinem Namen als Bestätigung.
 *
 * @var array<string,mixed> $kunde
 * @var array<string,int> $zahlen
 * @var bool $pflegen
 * @var bool $prueflingeSehen „geraetepruefung.view“: Link auf die Prüflinge des Kunden
 * @var array<string,mixed> $eingabe
 */
use App\Kunden\KundenService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $kunde['id'];
$e = ($eingabe['form'] ?? '') === 'stammdaten' ? $eingabe : [];
$wert = static fn (string $feld): string => (string) ($e[$feld] ?? $kunde[$feld] ?? '');
$bisDatum = array_key_exists('gueltig_bis', $e) ? (string) $e['gueltig_bis'] : Zeit::anzeige($kunde['gueltig_bis'] ?? null, 'Y-m-d');
$bisDatum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $bisDatum) === 1 ? $bisDatum : '';
$aktiv = array_key_exists('aktiv', $e) ? !empty($e['aktiv']) : (int) $kunde['aktiv'] === 1;
$prueflinge = (int) ($zahlen['prueflinge'] ?? 0);
// Anschrift und Kontakt im Formular: je Gruppe eine Zeile.
$gruppen = [
    'Anschrift' => ['strasse', 'plz', 'ort'],
    'Kontakt'   => ['telefon', 'fax', 'email'],
    'Ansprechpartner' => ['ansprechpartner', 'ansprechpartner_abteilung', 'ansprechpartner_telefon', 'ansprechpartner_email'],
];
// E-Mail als Text mit passender Tastatur: der Browser lehnte sonst Adressen ab, die aus dem Prüfgerät kommen.
$typen = ['telefon' => 'tel', 'fax' => 'tel', 'ansprechpartner_telefon' => 'tel'];
$kontaktDa = array_filter(array_keys(KundenService::KONTAKT), static fn (string $f): bool => (string) ($kunde[$f] ?? '') !== '') !== [];
?>
<div class="grid grid-2">
<?php if ($pflegen): ?>
    <form method="post" action="/kunden/speichern" class="card">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="card-head"><h2>Stammdaten</h2></div>
        <div class="card-body">
            <div class="field"><label for="s-name">Name</label>
                <input id="s-name" name="name" required minlength="2" maxlength="255" value="<?= View::e($wert('name')) ?>"></div>
            <div class="field-row">
                <div class="field"><label for="s-nummer">Kundennummer</label>
                    <input id="s-nummer" name="kundennummer" maxlength="64" value="<?= View::e($wert('kundennummer')) ?>"></div>
                <div class="field"><label for="s-bis">Zugang endet am</label>
                    <input id="s-bis" name="gueltig_bis" type="date" value="<?= View::e($bisDatum) ?>"></div>
            </div>
            <label class="check"><input type="checkbox" name="aktiv" value="1"<?= $aktiv ? ' checked' : '' ?>> aktiv – ausgeschaltet sind alle Zugänge sofort zu (Daten bleiben)</label>
            <div class="field mt"><label for="s-notizen">Notizen (nur für uns, nie im Kundenbereich)</label>
                <textarea id="s-notizen" name="notizen" rows="4" maxlength="5000"><?= View::e($wert('notizen')) ?></textarea></div>
            <details class="mt"<?= $kontaktDa || array_intersect_key($e, KundenService::KONTAKT) !== [] ? ' open' : '' ?>>
                <summary>Anschrift und Kontakt (freiwillig)</summary>
                <p class="help">Für Prüfberichte der Geräteprüfung und die Datei des Prüfgeräts – nie im Kundenbereich, nie im Audit.</p>
                <?php foreach ($gruppen as $gruppe => $felder): ?>
                    <div class="field-row" role="group" aria-label="<?= View::e($gruppe) ?>">
                        <?php foreach ($felder as $f): ?>
                            <div class="field"><label for="s-<?= View::e($f) ?>"><?= View::e(KundenService::KONTAKT_NAMEN[$f]) ?></label>
                                <input id="s-<?= View::e($f) ?>" name="<?= View::e($f) ?>" type="<?= View::e($typen[$f] ?? 'text') ?>"<?= str_ends_with($f, 'email') ? ' inputmode="email"' : '' ?> maxlength="<?= (int) KundenService::KONTAKT[$f] ?>"
                                       value="<?= View::e($wert($f)) ?>" autocomplete="off"></div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </details>
        </div>
        <div class="card-footer"><button class="btn btn-primary" type="submit">Speichern</button></div>
    </form>
<?php else: ?>
    <section class="card">
        <div class="card-head"><h2>Stammdaten</h2></div>
        <div class="card-body">
            <dl class="zugangsdaten">
                <dt>Name</dt><dd><?= View::e((string) $kunde['name']) ?></dd>
                <dt>Kundennummer</dt><dd><?= View::e((string) $kunde['kundennummer'] !== '' ? (string) $kunde['kundennummer'] : '–') ?></dd>
                <dt>Zugang endet</dt><dd><?= View::e(!empty($kunde['gueltig_bis']) ? Zeit::anzeige((string) $kunde['gueltig_bis']) : '–') ?></dd>
                <dt>Aktiv</dt><dd><?= (int) $kunde['aktiv'] === 1 ? 'ja' : 'nein' ?></dd>
                <?php foreach (KundenService::KONTAKT_NAMEN as $f => $name): ?>
                    <?php if ((string) ($kunde[$f] ?? '') !== ''): ?><dt><?= View::e($name) ?></dt><dd><?= View::e((string) $kunde[$f]) ?></dd><?php endif; ?>
                <?php endforeach; ?>
            </dl>
            <?php if ((string) ($kunde['notizen'] ?? '') !== ''): ?><p class="small"><?= nl2br(View::e((string) $kunde['notizen'])) ?></p><?php endif; ?>
        </div>
    </section>
<?php endif; ?>

    <section class="card">
        <div class="card-head"><h2>Herkunft</h2></div>
        <div class="card-body">
            <dl class="zugangsdaten">
                <dt>Angelegt</dt><dd><?= View::e(Zeit::anzeige($kunde['created_at'] ?? null)) ?></dd>
                <dt>Geändert</dt><dd><?= View::e(Zeit::anzeige($kunde['updated_at'] ?? null)) ?></dd>
                <dt>Eventworx</dt><dd><?= (string) ($kunde['ewx_kunde_ref'] ?? '') !== ''
                    ? View::e((string) $kunde['ewx_kunde_name']) . ' <span class="small muted mono">' . View::e((string) $kunde['ewx_host']) . '</span>'
                    : '<span class="muted">nicht verknüpft</span>' ?></dd>
                <?php if ($prueflingeSehen ?? false): ?>
                    <dt>Geräteprüfung</dt><dd><a href="/geraetepruefung/prueflinge?besitzer=<?= $id ?>&amp;betrieb=alle">Prüflinge dieses Kunden</a>
                        <span class="small muted">(<?= $prueflinge ?>)</span></dd>
                <?php endif; ?>
            </dl>
        </div>
    </section>

<?php if ($pflegen): ?>
    <form method="post" action="/kunden/<?= $id ?>/loeschen" class="card" id="loeschen"
          data-confirm="Kunde mit allen Zugängen, Freigaben und Prüfnachweisen endgültig löschen?">
        <?= Csrf::field() ?>
        <div class="card-head"><h2>Kunde löschen</h2></div>
        <div class="card-body">
            <?php if ((int) $kunde['aktiv'] === 1): ?>
                <p class="muted">Löschen lässt sich nur ein beendeter Kunde: erst „aktiv“ ausschalten und speichern.
                    Beenden schließt alle Zugänge sofort, die Daten bleiben.</p>
            <?php elseif ($prueflinge > 0): ?>
                <p class="muted">Der Kunde hat <strong><?= $prueflinge ?></strong> <?= $prueflinge === 1 ? 'Prüfling' : 'Prüflinge' ?> der Geräteprüfung –
                    gelöscht wird er erst, wenn sie entfernt sind. Prüflinge ohne Prüfungen lassen sich löschen; geprüfte bleiben mit ihren
                    Prüfberichten (Aufbewahrung), der Kunde bleibt dann beendet.</p>
                <?php if ($prueflingeSehen ?? false): ?><p><a class="btn btn-sm" href="/geraetepruefung/prueflinge?besitzer=<?= $id ?>&amp;betrieb=alle">Prüflinge dieses Kunden</a></p><?php endif; ?>
            <?php else: ?>
                <p>Weg sind danach: <strong><?= (int) $zahlen['zugaenge'] ?></strong> Zugänge, <strong><?= (int) $zahlen['freigaben'] ?></strong> Freigaben,
                    <strong><?= (int) $zahlen['vorgaenge'] ?></strong> Eventworx-Vorgänge, die Prüfnachweise des Kunden und
                    <strong><?= (int) $zahlen['abrufe'] ?></strong> Abrufe. <strong><?= (int) $zahlen['berichte'] ?></strong> Übergabeberichte bleiben
                    (Aufbewahrung), nur ohne Zuordnung zum Kunden.</p>
                <div class="field"><label for="l-name">Zur Bestätigung den Namen des Kunden eintragen</label>
                    <input id="l-name" name="bestaetigung" required autocomplete="off" placeholder="<?= View::e((string) $kunde['name']) ?>"></div>
                <button class="btn btn-danger" type="submit">Endgültig löschen</button>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>
</div>
