/*
 * Bildschirmfotos für die Dokumentation – aus dem Demo-Gelände.
 *
 * Voraussetzung: eine Installation im Demo-Modus mit frischen Demo-Daten und
 * Playwright (etwa `npm i -g playwright`, Chromium dazu). Dann:
 *
 *   php bin/power demo:seed --reset
 *   php -S 127.0.0.1:8080 -t public &
 *   NPM_ROOT=$(npm root -g) node docs/bilder/aufnehmen.mjs http://127.0.0.1:8080
 *
 * Die Bilder landen neben diesem Skript und werden von docs/*.md und der
 * README eingebunden. Nach Änderungen an der Oberfläche einfach neu aufnehmen.
 *
 * Für den Verteiler-Abgleich braucht es ein Repository. Das Skript legt sich
 * dafür ein eigenes an (git init --bare im Temp-Ordner) – kein Token, kein
 * Netz, auf GitHub landet nichts. Deshalb muss der Server diese Arbeitskopie
 * ausliefern (php und git im PATH). Die Einstellungen und Abgleich-Vermerke
 * der Installation stehen danach wieder wie vorher; zwei Notizen im Inventar
 * bleiben geändert (der Teil läuft zuletzt).
 */
import { execFileSync } from 'child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'fs';
import { createRequire } from 'module';
import { tmpdir } from 'os';
import { dirname, join, resolve } from 'path';
import { fileURLToPath } from 'url';

const require = createRequire(import.meta.url);
const { chromium } = require((process.env.NPM_ROOT ? process.env.NPM_ROOT + '/' : '') + 'playwright');
const basis = (process.argv[2] || 'http://127.0.0.1:8080').replace(/\/$/, '');
const benutzer = process.env.PM_BENUTZER || 'admin';
const kennwort = process.env.PM_KENNWORT || 'PowerAdmin2026!';
const ziel = dirname(fileURLToPath(import.meta.url));
const wurzel = resolve(ziel, '../..');

const DESKTOP = { width: 1280, height: 820 };
const HANDY = { width: 390, height: 844 };

// Deutsche Oberfläche auch für Datums- und Zeitfelder des Browsers.
const browser = await chromium.launch({
    args: ['--lang=de-DE'], env: { ...process.env, LANGUAGE: 'de', LANG: 'de_DE.UTF-8' },
    ...(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {}),
});
const fehler = [];

async function sitzung(viewport, extra = {}) {
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, locale: 'de-DE', timezoneId: 'Europe/Berlin', ...extra });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => fehler.push(page.url() + ': ' + e.message));
    await page.goto(basis + '/login');
    await page.fill('#username', benutzer);
    await page.fill('#password', kennwort);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    return { ctx, page };
}

async function oeffnen(page, pfad, warten = 400) {
    await page.goto(basis + pfad, { waitUntil: 'networkidle' });
    await page.waitForTimeout(warten);
}

async function foto(page, name, optionen = {}) {
    await page.screenshot({ path: `${ziel}/${name}.png`, ...optionen });
    console.log('  ' + name + '.png');
}

/** Der obere Teil einer langen Seite – höchstens `hoehe` Pixel. */
async function oben(page, name, hoehe) {
    const gesamt = await page.evaluate(() => document.documentElement.scrollHeight);
    await foto(page, name, { fullPage: true, clip: { x: 0, y: 0, width: page.viewportSize().width, height: Math.min(hoehe, gesamt) } });
}

async function element(page, locator, name) {
    await locator.first().screenshot({ path: `${ziel}/${name}.png` });
    console.log('  ' + name + '.png');
}

/** Adresse des ersten Links, dessen Text passt. */
async function link(page, text) {
    return page.locator('a', { hasText: text }).first().getAttribute('href');
}

/*
 * Ein Katalog-Paket für das Bild der Import-Vorschau: der Demo-Katalog, darin
 * der PD 63 mit anderem Nennstrom und einer anderen Bezeichnung, davor zwei
 * neue Typen (einer bringt den Steckertyp Harting mit), dahinter einer, dessen
 * Steckertyp nirgends beschrieben ist.
 */
const BEISPIELPAKET = `require 'src/bootstrap.php';
use App\\Services\\VerteilerBundle as V; use App\\Support\\Database as D;
$typen = [];
foreach (D::select('SELECT id, modell FROM objekte WHERE ist_vorlage = 1 ORDER BY name') as $r) {
    $t = V::typLesen((int) $r['id']);
    if ($r['modell'] === 'PD 63/32/16') { $t['typ']['nennstrom_a'] = 50; $t['typ']['anschluesse'][1]['bezeichnung'] = 'Bühne rechts'; }
    $typen[] = $t;
}
$s = fn ($ref, $art, $bez, $a, $vor = null, $mehr = []) => ['ref' => $ref, 'art' => $art, 'bezeichnung' => $bez, 'nennstrom_a' => $a, 'pole' => 3, 'vor' => $vor] + $mehr;
$an = fn ($richtung, $stecker, $nr, $schutz = null) => ['richtung' => $richtung, 'stecker' => $stecker, 'nr' => $nr, 'bezeichnung' => ($richtung === 'eingang' ? 'E' : 'X') . $nr, 'schutz' => $schutz];
$typ = fn ($schluessel, $typ, $stecker = []) => ['format' => 1, 'art' => 'verteiler-typ', 'schluessel' => $schluessel, 'typ' => $typ + ['fest' => false, 'spannung_v' => 400, 'phasen' => 3], 'steckertypen' => $stecker];
$neu = [
    $typ('bss-pd-32-16-h', ['art' => 'verteilung', 'name' => 'PD 32/16 mit Harting', 'hersteller' => 'BSS', 'modell' => 'PD 32/16 H', 'nennstrom_a' => 32,
        'eigenschaften' => ['artikelnummer' => '40.032.16', 'gehaeuse' => 'Gummi, gelb', 'schutzart' => 'IP44', 'zuleitung' => '2,0 m 5G6', 'gewicht_kg' => 18.5],
        'schutzorgane' => [$s('s1', 'hauptschalter', 'Q1', 32), $s('s2', 'rcd', 'F1', 40, 's1', ['fehlerstrom_ma' => 30, 'rcd_typ' => 'A']),
            $s('s3', 'ls', 'Q2', 16, 's2', ['charakteristik' => 'C']), $s('s4', 'ls', 'Q3', 16, 's2', ['charakteristik' => 'B'])],
        'anschluesse' => [$an('eingang', 'cee32', 1), $an('abgang', 'cee16', 1, 's3'), $an('abgang', 'harting-16', 2, 's3'), $an('abgang', 'schuko', 3, 's4'), $an('abgang', 'schuko', 4, 's4')]],
        [['schluessel' => 'harting-16', 'name' => 'Harting 16 A', 'strom_a' => 16, 'phasen' => 3, 'spannung_v' => 400, 'farbe' => '#7c3aed']]),
    $typ('bss-sg-60', ['art' => 'generator', 'name' => 'Stromaggregat 60 kVA', 'hersteller' => 'BSS', 'modell' => 'SG 60', 'nennleistung_kva' => 60,
        'eigenschaften' => ['artikelnummer' => '60.060.01', 'kraftstoff' => 'Diesel', 'tank_l' => 160, 'verbrauch_l_h' => 12.5, 'gewicht_kg' => 1450, 'abmessungen' => '2800 × 1100 × 1600'],
        'schutzorgane' => [$s('s1', 'hauptschalter', 'Q1', 100), $s('s2', 'rcd', 'F1', 63, 's1', ['fehlerstrom_ma' => 300, 'rcd_typ' => 'B'])],
        'anschluesse' => [$an('abgang', 'powerlock', 1, 's1'), $an('abgang', 'cee63', 2, 's2'), $an('abgang', 'cee32', 3, 's2'), $an('abgang', 'cee16', 4, 's2'), $an('abgang', 'schuko', 5, 's2')]]),
];
$fehlt = $typ('bss-pd-32-soca', ['art' => 'verteilung', 'name' => 'PD 32 Socapex', 'modell' => 'PD 32 S', 'anschluesse' => [$an('eingang', 'cee32', 1), $an('abgang', 'socapex-19', 1)]]);
echo json_encode(['format' => 1, 'art' => 'verteiler-typen', 'quelle' => 'BSS Strombibel (Beispiel)', 'typen' => array_merge($neu, $typen, [$fehlt])], JSON_UNESCAPED_UNICODE);`;

// ------------------------------------------------------------------ Desktop
{
    const { ctx, page } = await sitzung(DESKTOP);
    await oeffnen(page, '/dashboard', 1200);
    // bis einschließlich der Tanks
    await oben(page, 'uebersicht', await page.evaluate(() => {
        const h = [...document.querySelectorAll('h2')].find((e) => e.textContent.trim() === 'Schalten');
        return h ? Math.round(h.getBoundingClientRect().top + window.scrollY) - 24 : 820;
    }));

    await oeffnen(page, '/karte', 1800);
    await foto(page, 'karte');
    // Wert neben dem Hauptverteiler antippen: die Werte je Phase im Plan
    await page.locator('.pm-anlage', { hasText: 'Hauptverteiler' }).locator('[data-wert]').click();
    await page.waitForTimeout(900);
    await element(page, page.locator('#karte'), 'karte-phasen');
    await page.keyboard.press('Escape');
    // Ein Kabel antippen: Länge, Belastbarkeit, Spannungsfall
    await oeffnen(page, '/karte', 1500);
    await page.locator('.pm-anlage', { hasText: 'Foodtruck-Verteiler' }).locator('.pm-symbol').click();
    await page.waitForTimeout(900);
    await foto(page, 'karte-anlage');
    // Den Tank des Aggregats antippen: Füllstand, Verbrauch, Restlaufzeit, Peilung und Betankung
    await oeffnen(page, '/karte', 1500);
    await page.locator('.pm-anlage', { hasText: 'Tank Aggregat 1' }).locator('.pm-symbol').click();
    await page.waitForTimeout(1200);
    await foto(page, 'karte-tank');
    // Georeferenz
    await page.click('[data-modus="geo"]');
    await page.waitForTimeout(500);
    await foto(page, 'georeferenz');

    await oeffnen(page, '/anlagen');
    const hv = await link(page, 'Hauptverteiler');
    await oeffnen(page, hv, 1200);
    await foto(page, 'anlage');
    await element(page, page.locator('.card', { has: page.locator('h2', { hasText: 'Verteilerplan' }) }), 'verteilerplan');
    await oeffnen(page, '/anlagen?art=tank');
    await oeffnen(page, await link(page, 'Tank Aggregat 1'), 2200);
    await oben(page, 'tank', 1900);
    await oeffnen(page, '/anlagen/katalog');
    await foto(page, 'katalog');
    // Katalog-Import: Vorschau eines Beispielpakets aus dem Demo-Katalog –
    // zwei neue Typen, ein hier anderer, einer mit fehlendem Steckertyp.
    // Übernommen wird nichts, die Vorschau wird danach verworfen.
    const paket = execFileSync('php', ['-r', BEISPIELPAKET], { cwd: wurzel, encoding: 'utf8' });
    await oeffnen(page, '/anlagen/katalog/import');
    await page.setInputFiles('input[name=datei]', { name: 'bss-strombibel-beispiel.json', mimeType: 'application/json', buffer: Buffer.from(paket) });
    await Promise.all([page.waitForNavigation(), page.locator('form[enctype] button[type=submit]').click()]);
    await page.waitForTimeout(400);
    await page.locator('details.import-unterschiede summary').first().click();
    // Die Knopfleiste klebt am unteren Rand des Fensters – für das ganze Bild
    // also ein Fenster so hoch wie die Seite, statt fullPage (dort stünde sie
    // mitten im Bild).
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.setViewportSize({ ...DESKTOP, height: await page.evaluate(() => document.documentElement.scrollHeight) });
    await page.waitForTimeout(300);
    await foto(page, 'katalog-import');
    await page.setViewportSize(DESKTOP);
    await Promise.all([page.waitForNavigation(), page.locator('button', { hasText: 'Vorschau verwerfen' }).click()]);
    await oeffnen(page, '/statistik?anlage=' + hv.split('/').pop(), 2200);
    await oben(page, 'statistik', 1500);

    await oeffnen(page, '/bereiche');
    const bereich = await link(page, 'Foodtruck-Meile');
    await oeffnen(page, bereich, 2200);
    await oben(page, 'bereich', 1300);
    await oeffnen(page, bereich + '?reiter=material');
    await foto(page, 'material', { fullPage: true });

    await oeffnen(page, '/inventar');
    await foto(page, 'inventar');
    await oeffnen(page, await link(page, 'PD63-07'));
    await foto(page, 'inventar-stueck');
    await oeffnen(page, '/inventar');
    await oeffnen(page, await link(page, 'TS-01'));
    await foto(page, 'inventar-tanksensor');
    await oeffnen(page, '/anlagen');
    await oeffnen(page, (await link(page, 'Foodtruck-Verteiler')) + '?reiter=messung', 800);
    await foto(page, 'einsetzen');

    await oeffnen(page, '/aufbau');
    const aufbau = await link(page, 'Sommerfestival');
    await oeffnen(page, aufbau);
    await foto(page, 'aufbau');
    await oeffnen(page, aufbau + '/abgleich', 600);
    await oben(page, 'abgleich', 1400);

    await oeffnen(page, '/system/benutzer');
    // Die Zeile des Demo-Kontos „veranstalter“ (nicht die Rolle „Kunde / Veranstalter“)
    const zeile = page.locator('tr').filter({ has: page.locator('td', { hasText: /^\s*veranstalter\s*$/ }) })
        .or(page.locator('tr').filter({ has: page.locator('strong', { hasText: /^veranstalter$/ }) }));
    await oeffnen(page, await zeile.locator('a[href*="freigaben"]').first().getAttribute('href'));
    await foto(page, 'freigaben');
    await oeffnen(page, '/location');
    await foto(page, 'location');
    await oeffnen(page, '/datenquellen');
    await foto(page, 'datenquellen');
    await oeffnen(page, '/datenquellen/mqtt-zugaenge');
    await oben(page, 'mqtt-zugaenge', 1250);
    await ctx.close();
}

// ------------------------------------------------------------------ Handy
{
    // GPS mitten auf dem Gelände: aus der Georeferenz der Etage gerechnet.
    const { ctx, page } = await sitzung(HANDY);
    await oeffnen(page, '/karte', 1500);
    await foto(page, 'karte-handy');
    const etage = await page.locator('#karte').getAttribute('data-etage');
    const geo = await page.evaluate(async (e) => (await (await fetch(`/karte/${e}/stand`, { headers: { Accept: 'application/json' } })).json()).geo, etage);
    await ctx.close();
    if (geo) {
        const R = 6371008.8;
        const k = R * Math.PI / 180;
        const kx = k * Math.cos(geo.a.lat * Math.PI / 180);
        const px = geo.b.x - geo.a.x;
        const py = -(geo.b.y - geo.a.y) * geo.verhaeltnis;
        const ost = (geo.b.lon - geo.a.lon) * kx;
        const nord = (geo.b.lat - geo.a.lat) * k;
        const n = px * px + py * py;
        const sr = (ost * px + nord * py) / n;
        const si = (nord * px - ost * py) / n;
        const qx = 50 - geo.a.x;
        const qy = -(60 - geo.a.y) * geo.verhaeltnis;
        const lat = geo.a.lat + (sr * qy + si * qx) / k;
        const lon = geo.a.lon + (sr * qx - si * qy) / kx;
        const gps = await sitzung(HANDY, { geolocation: { latitude: lat, longitude: lon, accuracy: 6 }, permissions: ['geolocation'] });
        await oeffnen(gps.page, '/karte', 1500);
        await gps.page.click('[data-aktion="position"]');
        await gps.page.waitForTimeout(1500);
        await foto(gps.page, 'gps-handy');
        await gps.ctx.close();
    }
    const auf = await sitzung(HANDY);
    await oeffnen(auf.page, '/karte?modus=aufbau', 1500);
    await auf.page.locator('.pm-anlage', { hasText: 'Kasse Einlass' }).locator('.pm-symbol').click();
    await auf.page.waitForTimeout(1200);
    await foto(auf.page, 'aufbau-handy', { fullPage: true });
    await oeffnen(auf.page, '/bereiche', 800);
    await foto(auf.page, 'bereiche-handy');
    await auf.ctx.close();
}

// ------------------------------------------------------------ Verteiler-Abgleich
{
    const php = (code, ...argumente) => execFileSync('php', ['-r', `require 'src/bootstrap.php'; ${code}`, ...argumente], { cwd: wurzel, encoding: 'utf8' });
    const tmp = mkdtempSync(join(tmpdir(), 'pm-bilder-'));
    const repo = 'file://' + join(tmp, 'repo.git');
    const git = (...a) => execFileSync('git', ['-c', 'user.name=Lager', '-c', 'user.email=lager@local', ...a], { cwd: tmp, encoding: 'utf8' });
    const sicherung = join(tmp, 'vorher.json');
    // Einstellungen und Abgleich-Vermerke der Installation festhalten
    php(`use App\\Support\\Database as D; use App\\Support\\Settings as S;
        file_put_contents($argv[1], json_encode(['url' => S::get('inventory.repo_url'), 'zweig' => S::get('inventory.branch'),
            'typen' => D::select('SELECT id, sync_key, synced_hash, synced_at FROM objekte WHERE ist_vorlage = 1'),
            'inventar' => D::select('SELECT id, sync_key, synced_hash, synced_at FROM inventar')]));
        S::put('inventory.repo_url', $argv[2]); S::put('inventory.branch', 'main');`, sicherung, repo);
    try {
        git('init', '-q', '--bare', '-b', 'main', 'repo.git');
        const { ctx, page } = await sitzung(DESKTOP);
        page.on('dialog', (d) => d.accept());
        await oeffnen(page, '/verteiler-abgleich?holen=1');
        await Promise.all([page.waitForNavigation(), page.locator('button', { hasText: 'Alle hochladen' }).click()]);

        // Eine zweite Installation (das Lager) ändert im Repository: Shelly
        // getauscht, ein neuer Verteiler, eine Seriennummer nachgetragen.
        git('clone', '-q', 'repo.git', 'lager');
        const datei = (k) => join(tmp, 'lager/verteiler/inventar', k + '.json');
        const lesen = (k) => JSON.parse(readFileSync(datei(k), 'utf8'));
        const schreiben = (k, d) => writeFileSync(datei(k), JSON.stringify({ ...d, stand: new Date().toISOString(), von: 'PowerManagement Lager' }, null, 2));
        const pd63 = lesen('pd63-07');
        pd63.stueck.messgeraete[0].geraet += '-tausch';
        schreiben('pd63-07', pd63);
        const neu = structuredClone(pd63);
        Object.assign(neu, { schluessel: 'pd63-11' });
        Object.assign(neu.stueck, { kennung: 'PD63-11', name: '', seriennummer: '', notizen: '',
            messgeraete: [{ geraet: 'shellypro3em-pd63-11', kanal: 'em:0', stelle: 'einspeisung', bezeichnung: '' }] });
        schreiben('pd63-11', neu);
        const pd32 = lesen('pd32-15');
        pd32.stueck.seriennummer = 'SN-2026-0415';
        schreiben('pd32-15', pd32);
        git('-C', 'lager', 'add', '-A');
        git('-C', 'lager', 'commit', '-q', '-m', 'Lager: Shelly getauscht, PD63-11 neu, Seriennummer');
        git('-C', 'lager', 'push', '-q', 'origin', 'main');

        // Hier: eine Notiz am PD32-15 (damit auf beiden Seiten geändert) und am ZS-32-05
        for (const [kennung, notiz] of [['PD32-15', 'Deckel ersetzt'], ['ZS-32-05', 'Zuleitung erneuert']]) {
            await oeffnen(page, '/inventar');
            await oeffnen(page, (await link(page, kennung)) + '/bearbeiten');
            await page.fill('textarea[name=notizen]', notiz);
            await Promise.all([page.waitForNavigation(), page.locator('button[type=submit]', { hasText: 'Speichern' }).click()]);
        }
        await oeffnen(page, '/verteiler-abgleich?holen=1', 600);
        await foto(page, 'verteiler-abgleich', { fullPage: true });
        await ctx.close();
    } finally {
        php(`use App\\Support\\Database as D; use App\\Support\\Settings as S; $v = json_decode(file_get_contents($argv[1]), true);
            $v['url'] === null ? S::forget('inventory.repo_url') : S::put('inventory.repo_url', $v['url']);
            $v['zweig'] === null ? S::forget('inventory.branch') : S::put('inventory.branch', $v['zweig']);
            foreach (['objekte' => $v['typen'], 'inventar' => $v['inventar']] as $t => $zeilen) { foreach ($zeilen as $z) {
                D::execute("UPDATE $t SET sync_key = ?, synced_hash = ?, synced_at = ? WHERE id = ?", [$z['sync_key'], $z['synced_hash'], $z['synced_at'], $z['id']]); } }
            // Die Arbeitskopie gehört zum Aufnahme-Repository – weg damit.
            $k = App\\Services\\LocationSync::arbeitsKopie();
            if (is_dir($k) && trim((string) shell_exec('git -C ' . escapeshellarg($k) . ' remote get-url origin 2>/dev/null')) === $argv[2]) {
                exec('rm -rf ' . escapeshellarg($k));
            }`, sicherung, repo);
        rmSync(tmp, { recursive: true, force: true });
    }
}

await browser.close();
if (fehler.length) {
    console.error('Fehler auf den Seiten:\n' + fehler.join('\n'));
    process.exit(1);
}
