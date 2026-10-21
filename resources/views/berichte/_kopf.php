<?php
use App\Berichte\Felder;
use App\Services\Eventworx;
use App\Support\View;
use App\Support\Zeit;

$kopf = $inhalt['kopf'];
$kunde = $kopf['kunde'];
$ewxDa = $eventworx['recht'] && $eventworx['eingerichtet'];
$verweis = (string) $bericht['ewx_id'] !== '';
?>
<section class="card bericht-abschnitt" id="abschnitt-kopf" data-abschnitt="kopf">
    <div class="card-head"><h2>Kopf &amp; Kunde</h2></div>
    <div class="card-body">
        <div class="feld-raster">
            <?= Felder::text('kopf.veranstaltung', $kopf['veranstaltung'], 'Veranstaltung', ['max' => 255, 'klasse' => 'breit']) ?>
            <?= Felder::text('kopf.zeitraum', $kopf['zeitraum'], 'Zeitraum', ['max' => 255, 'platzhalter' => 'z. B. Aufbau 12.06., Betrieb 13.–15.06.2026']) ?>
            <?= Felder::text('kopf.auftrag_nr', $kopf['auftrag_nr'], 'Auftrag-Nr.', ['max' => 64]) ?>
            <?= Felder::text('kopf.projekt_nr', $kopf['projekt_nr'], 'Projekt-Nr.', ['max' => 64]) ?>
            <?= Felder::text('kopf.pruefdatum', $kopf['pruefdatum'], 'Prüfdatum', ['typ' => 'date']) ?>
            <?= Felder::text('kopf.ort', $kopf['ort'], 'Ort der Prüfung', ['max' => 120]) ?>
        </div>
        <div class="pruefer-zeile" data-feld-ziel="kopf.qualifikation" tabindex="-1">
            <span class="form-label">Prüfer</span>
            <strong><?= View::e($pruefer['name']) ?></strong>
            <?php if ($pruefer['qualifikation'] !== ''): ?>
                <span class="small"><?= View::e($pruefer['qualifikation']) ?></span>
            <?php else: ?>
                <span class="small text-warn">Für dein Konto ist keine Prüfer-Qualifikation hinterlegt – ohne sie keine Prüfer-Unterschrift (Benutzerverwaltung).</span>
            <?php endif; ?>
            <?php if (!$pruefer['recht']): ?><span class="small muted">Unterschreiben wird jemand mit „als Prüfer unterschreiben“.</span><?php endif; ?>
        </div>

        <h3 class="mt">Kunde</h3>
        <div class="reiter" role="tablist" aria-label="Woher kommen die Kundendaten?">
            <button type="button" role="tab" class="reiter-knopf" aria-selected="true" data-reiter="freitext">Freitext</button>
            <button type="button" role="tab" class="reiter-knopf<?= $ewxDa ? '' : ' grau' ?>" aria-selected="false" data-reiter="eventworx">Eventworx</button>
            <button type="button" role="tab" class="reiter-knopf<?= $quellen !== [] ? '' : ' grau' ?>" aria-selected="false" data-reiter="bericht">Aus früherem Bericht</button>
        </div>
        <div class="reiter-feld" data-reiter-feld="freitext" role="tabpanel">
            <input type="hidden" data-feld="kopf.kunde.quelle" value="<?= View::e($kunde['quelle']) ?>">
            <div class="feld-raster">
                <?= Felder::text('kopf.kunde.firma', $kunde['firma'], 'Kunde (Firma oder Name)', ['max' => 255, 'klasse' => 'breit', 'auto' => 'organization']) ?>
                <?= Felder::text('kopf.kunde.anschrift', $kunde['anschrift'], 'Anschrift', ['mehrzeilig' => true, 'zeilen' => 3, 'max' => 1000]) ?>
                <?= Felder::text('kopf.kunde.kundennummer', $kunde['kundennummer'], 'Kundennummer', ['max' => 64]) ?>
                <?= Felder::text('kopf.kunde.ansprechpartner', $kunde['ansprechpartner'], 'Ansprechpartner', ['max' => 190]) ?>
                <?= Felder::text('kopf.kunde.telefon', $kunde['telefon'], 'Telefon', ['typ' => 'tel', 'max' => 64]) ?>
                <?= Felder::text('kopf.kunde.email', $kunde['email'], 'E-Mail', ['typ' => 'email', 'max' => 190]) ?>
            </div>
            <p class="help mb0" data-quelle-text><?= $kunde['quelle'] === 'eventworx' ? 'Übernommen aus Eventworx – änderbar.' : ($kunde['quelle'] === 'bericht' ? 'Übernommen aus einem früheren Bericht – änderbar.' : '') ?></p>
        </div>
        <div class="reiter-feld" data-reiter-feld="eventworx" role="tabpanel" hidden>
            <?php if (!$eventworx['recht']): ?>
                <p class="notice mb0">Für Eventworx fehlt dir das Recht „Kundendaten aus Eventworx“ – bitte die Kundendaten als Freitext eintragen.</p>
            <?php elseif (!$eventworx['eingerichtet']): ?>
                <p class="notice mb0">Eventworx ist nicht eingerichtet (Systemeinstellungen) – bitte die Kundendaten als Freitext eintragen.</p>
            <?php else: ?>
                <p class="small muted">Die Kundendaten kommen aus dem Vorgang in Eventworx – PowerManagement liest dort nur. Am sichersten findet die
                    Auftrags- oder Angebotsnummer. Übernommen wird, was du anhakst; danach bleibt alles hier als Text änderbar.</p>
                <div class="ewx-suche">
                    <input type="search" data-ewx-q aria-label="Auftrags- oder Angebotsnummer" placeholder="z. B. AU-1042" maxlength="64" autocomplete="off" enterkeyhint="search">
                    <button class="btn btn-primary" type="button" data-ewx-suchen>Suchen</button>
                </div>
                <p class="small" data-ewx-meldung role="status"></p>
                <ul class="ewx-treffer" data-ewx-treffer></ul>
            <?php endif; ?>
        </div>
        <div class="reiter-feld" data-reiter-feld="bericht" role="tabpanel" hidden>
            <?php if ($quellen === []): ?>
                <p class="notice mb0">Für <?= $bericht['site_id'] !== null ? 'diesen Standort' : 'einen Bericht ohne Standort' ?> gibt es keinen abgeschlossenen Bericht mit Kundendaten.</p>
            <?php else: ?>
                <p class="small muted">Übernimmt Kunde, Ansprechpartner und Projektleiter des Kunden – und den Eventworx-Verweis, falls der Bericht einen hat.
                    Das geht auch ohne Verbindung zu Eventworx.</p>
                <div class="ewx-suche">
                    <select data-quelle-wahl aria-label="Früherer Bericht">
                        <?php foreach ($quellen as $q): ?>
                            <option value="<?= (int) $q['id'] ?>"><?= View::e(($q['nummer'] ?? ('#' . $q['id'])) . ' · ' . $q['kunde_name'] . ($q['veranstaltung'] !== '' ? ' · ' . $q['veranstaltung'] : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary" type="button" data-quelle-uebernehmen>Übernehmen</button>
                </div>
                <p class="small" data-quelle-meldung role="status"></p>
            <?php endif; ?>
        </div>
        <div class="ewx-verweis" data-ewx-verweis data-ewx-id="<?= View::e((string) $bericht['ewx_id']) ?>"<?= $verweis ? '' : ' hidden' ?>>
            <span>Verknüpft mit Eventworx: <strong data-ewx-nummer><?= View::e(trim((Eventworx::ART_NAMEN[(string) $bericht['ewx_art']] ?? '') . ' ' . $bericht['ewx_nummer'])) ?></strong>
                <span class="small muted" data-ewx-am><?= $bericht['ewx_am'] ? 'geladen ' . View::e(Zeit::anzeige((string) $bericht['ewx_am'])) : '' ?></span></span>
            <span class="btn-row">
                <?php if ($ewxDa): ?><button class="btn btn-sm" type="button" data-ewx-neu-laden>Neu laden</button><?php endif; ?>
                <a class="btn btn-sm btn-ghost" data-ewx-link href="<?= View::e($eventworx['link']) ?>" target="_blank" rel="noopener noreferrer"<?= $eventworx['link'] === '' ? ' hidden' : '' ?>>In Eventworx öffnen ↗</a>
                <button class="btn btn-sm btn-ghost" type="button" data-ewx-entfernen>Verweis entfernen</button>
            </span>
        </div>

        <h3 class="mt">Verantwortliche</h3>
        <div class="feld-raster">
            <?= Felder::text('kopf.projektleiter_kunde', $kopf['projektleiter_kunde'], 'Projektleiter Kunde', ['max' => 190]) ?>
            <?= Felder::text('kopf.projektleiter_montage', $kopf['projektleiter_montage'], 'Projektleiter Montage', ['max' => 190]) ?>
            <?= Felder::text('kopf.anlagenverantwortlicher', $kopf['anlagenverantwortlicher'], 'Anlagenverantwortlicher', ['max' => 190]) ?>
            <?= Felder::text('kopf.bereitschaft', $kopf['bereitschaft'], 'Bereitschaft (Telefon)', ['typ' => 'tel', 'max' => 120]) ?>
        </div>
    </div>
</section>
