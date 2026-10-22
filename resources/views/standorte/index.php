<?php
use App\Services\PlanTileService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Standorte & Etagen';
$subtitle = 'Gelände und Gebäude mit ihren Grundrissen';
?>
<div class="grid grid-sidebar">
    <div>
        <?php if ($standorte === []): ?>
            <div class="card"><div class="card-body muted">Noch kein Standort. Rechts anlegen oder über den <a href="/location">Location-Abgleich</a> aus MasterAdmin übernehmen.</div></div>
        <?php endif; ?>
        <?php foreach ($standorte as $s): ?>
            <div class="card">
                <div class="card-head">
                    <h2><?= View::e($s['name']) ?></h2>
                    <?php if ((string) $s['sync_key'] !== ''): ?><span class="badge info" title="Schlüssel im Repository">⇅ <?= View::e($s['sync_key']) ?></span><?php endif; ?>
                    <span class="spacer"></span>
                    <span class="hint"><?= (int) $s['objekt_count'] ?> Anlagen</span>
                </div>
                <div class="card-body">
                    <?php if ((string) $s['address'] !== ''): ?><p class="small muted"><?= View::e($s['address']) ?></p><?php endif; ?>
                    <table class="tabelle-karten">
                        <thead><tr><th>Etage</th><th>Grundriss</th><th class="num">Anlagen</th><th class="num">Leitungen</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($s['floors'] as $f): ?>
                            <tr>
                                <td data-label="Etage"><strong><?= View::e($f['name']) ?></strong> <span class="small muted">Ebene <?= (int) $f['level_no'] ?></span></td>
                                <td data-label="Grundriss" class="small"><?= $f['plan_file'] !== '' ? View::e($f['plan_original_name']) . '<div class="muted">' . View::e(Zeit::anzeige($f['plan_uploaded_at'])) . '</div>' : '<span class="muted">keiner</span>' ?>
                                    <?php $k = $kacheln[(int) $f['id']] ?? null; ?>
                                    <?php if ($k !== null): ?>
                                        <?php $laeuft = in_array($k['zustand'], PlanTileService::OFFEN, true); ?>
                                        <div class="muted plan-kacheln" data-plan-zustand="<?= View::e($k['zustand']) ?>">
                                            <?php if ($k['gekachelt']): ?>Kacheln <?= View::e(number_format($k['breite'], 0, ',', '.')) ?> × <?= View::e(number_format($k['hoehe'], 0, ',', '.')) ?> px · Fassung <?= (int) $k['fassung'] ?><?= $k['qualitaet'] === 'fein' ? ' · fein' : '' ?><?php else: ?>noch ohne Kacheln<?php endif; ?>
                                            <?php if ($laeuft): ?> · <span class="badge info">wird gerechnet</span>
                                            <?php elseif ($k['veraltet']): ?> · <span class="badge warn">neu rechnen empfohlen</span>
                                            <?php elseif ($k['zustand'] === 'fehler' || $k['zustand'] === 'abgebrochen'): ?> · <span class="badge bad" title="<?= View::e($k['zustand_text']) ?>"><?= $k['zustand'] === 'fehler' ? 'gescheitert' : 'abgebrochen' ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($k['neu_rechenbar'] && !$laeuft && ($darfNeu[(int) $f['id']] ?? false)): ?>
                                            <form method="post" action="/grundriss/<?= (int) $f['id'] ?>/neu-rechnen" class="plan-neu-form" data-laeuft="wird angestoßen …">
                                                <?= Csrf::field() ?>
                                                <button class="btn btn-sm btn-ghost" type="submit" title="Den Grundriss im Hintergrund noch einmal in Kacheln rechnen – die Karte bleibt so lange benutzbar"><?= $k['gekachelt'] ? 'Neu rechnen' : 'Aufbereiten' ?></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Anlagen" class="num"><?= (int) $f['objekt_count'] ?></td>
                                <td data-label="Leitungen" class="num"><?= (int) $f['leitung_count'] ?></td>
                                <td class="right">
                                    <a class="btn btn-sm" href="/karte?etage=<?= (int) $f['id'] ?>">Karte</a>
                                    <form method="post" action="/standorte/etage/loeschen" style="display:inline" data-confirm="Etage „<?= View::e($f['name']) ?>“ löschen? Kabel und Kabelwege darauf gehen mit, Anlagen bleiben.">
                                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                                        <button class="btn btn-sm btn-ghost" type="submit">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <details class="mt">
                        <summary class="btn btn-sm">Grundriss hochladen</summary>
                        <form method="post" action="/standorte/grundriss" enctype="multipart/form-data" class="mt"
                              data-laeuft="wird hochgeladen …"
                              data-laeuft-hinweis="Die Datei wird hochgeladen und aufbereitet – bei großen Plänen über eine langsame Leitung einige Minuten. Bitte die Seite offen lassen.">
                            <?= Csrf::field() ?><input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
                            <div class="field"><label for="plan-<?= (int) $s['id'] ?>">Datei (PDF, PNG, JPEG, SVG – bis <?= View::e($uploadText) ?>)</label>
                                <input id="plan-<?= (int) $s['id'] ?>" type="file" name="plan" accept=".pdf,.png,.jpg,.jpeg,.svg" required
                                       data-max-bytes="<?= (int) $uploadGrenze ?>" data-max-text="<?= View::e($uploadText) ?>"></div>
                            <?php if ($s['floors'] !== []): ?>
                                <div class="field"><label>Für Etage(n)</label>
                                    <?php foreach ($s['floors'] as $f): ?>
                                        <label class="check"><input type="checkbox" name="floor_ids[]" value="<?= (int) $f['id'] ?>"> <?= View::e($f['name']) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <label class="check"><input type="checkbox" name="neue_etagen" value="1"<?= $s['floors'] === [] ? ' checked' : '' ?>> neue Etage(n) anlegen (je PDF-Seite eine)</label>
                            <button class="btn btn-primary btn-sm" type="submit">Hochladen</button>
                        </form>
                    </details>
                    <details class="mt">
                        <summary class="btn btn-sm">Etage hinzufügen</summary>
                        <form method="post" action="/standorte/etage" class="mt">
                            <?= Csrf::field() ?><input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
                            <div class="field-row">
                                <div class="field"><label>Name</label><input name="name" required placeholder="z. B. Gelände, EG, Halle 2"></div>
                                <div class="field"><label>Ebene</label><input name="level_no" type="number" value="0"></div>
                            </div>
                            <button class="btn btn-primary btn-sm" type="submit">Anlegen</button>
                        </form>
                    </details>
                    <details class="mt">
                        <summary class="btn btn-sm btn-ghost">Standort bearbeiten</summary>
                        <form method="post" action="/standorte/speichern" class="mt">
                            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <div class="field"><label>Name</label><input name="name" required value="<?= View::e($s['name']) ?>"></div>
                            <div class="field"><label>Adresse</label><input name="address" value="<?= View::e($s['address']) ?>"></div>
                            <div class="field"><label>Notizen</label><textarea name="notes" rows="2"><?= View::e((string) $s['notes']) ?></textarea></div>
                            <button class="btn btn-primary btn-sm" type="submit">Speichern</button>
                        </form>
                        <?php if (View::can('anlagen.scope.all')): ?>
                            <form method="post" action="/standorte/loeschen" data-confirm="Standort „<?= View::e($s['name']) ?>“ mit allen Etagen löschen?">
                                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <button class="btn btn-sm btn-danger mt" type="submit">Standort löschen</button>
                            </form>
                        <?php endif; ?>
                    </details>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="card">
        <div class="card-head"><h2>Neuer Standort</h2></div>
        <form method="post" action="/standorte/speichern" class="card-body">
            <?= Csrf::field() ?>
            <div class="field"><label for="neu-name">Name</label><input id="neu-name" name="name" required placeholder="z. B. Festivalgelände Nordwiese"></div>
            <div class="field"><label for="neu-adresse">Adresse</label><input id="neu-adresse" name="address"></div>
            <button class="btn btn-primary" type="submit">Anlegen</button>
            <p class="help">Wird der Standort später hochgeladen, landet er im selben Repository wie die Locations aus MasterAdmin.</p>
        </form>
    </div>
</div>
