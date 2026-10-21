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
 * Die Übergabeberichte kommen aus dem Demo-Gelände: der abgeschlossene für
 * das PDF (Seite 1 über `pdftoppm` aus poppler-utils), der Entwurf für
 * Formular und Eventworx-Suche (Demo-Vorgang, nichts wird übernommen), der
 * vom Prüfer unterschriebene für den Kundenmodus – der wird dabei nur
 * geöffnet und mit dem Kennwort wieder entsperrt (im Lebenslauf steht dann
 * „an den Kunden übergeben“).
 *
 * Für den Verteiler-Abgleich braucht es ein Repository. Das Skript legt sich
 * dafür ein eigenes an (git init --bare im Temp-Ordner) – kein Token, kein
 * Netz, auf GitHub landet nichts. Deshalb muss der Server diese Arbeitskopie
 * ausliefern (php und git im PATH). Die Einstellungen und Abgleich-Vermerke
 * der Installation stehen danach wieder wie vorher; zwei Notizen im Inventar
 * bleiben geändert (der Teil läuft zuletzt).
 *
 * Für die Schnittstelle (andere Instanzen) startet das Skript eine zweite
 * Instanz B: derselbe Code mit eigener Konfiguration und Datenbank im
 * Temp-Ordner (POWERMANAGEMENT_CONFIG), ein eigener PHP-Server auf einem
 * freien Port. Die Instanz unter der angegebenen Adresse ist A: sie gibt B
 * einen Zugang auf das Demo-Gelände und zwei Standorte, die nur für die
 * Bilder angelegt werden. B übernimmt, ändert und schickt einen Vorschlag
 * zurück. Danach ist alles wieder weg – Zugänge, Vorschlag, die beiden
 * Standorte, B samt Datenbank und die Plandateien, die B im gemeinsamen
 * storage/plans abgelegt hat; die Abgleich-Schlüssel des Geländes stehen
 * wie vorher. Gezeigt wird nie ein Token im Klartext.
 */
import { execFileSync, spawn } from 'child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'fs';
import { createRequire } from 'module';
import { createServer } from 'net';
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
const TABLET_QUER = { width: 1180, height: 820 };

// Deutsche Oberfläche auch für Datums- und Zeitfelder des Browsers.
const browser = await chromium.launch({
    args: ['--lang=de-DE'], env: { ...process.env, LANGUAGE: 'de', LANG: 'de_DE.UTF-8' },
    ...(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {}),
});
const fehler = [];

/**
 * Angemeldet im Browser – als `bei.name` (Vorgabe: das Konto aus der
 * Kommandozeile), bei dieser Instanz oder (`bei.url`) einer anderen.
 */
async function sitzung(viewport, extra = {}, bei = {}) {
    const { url = basis, name = benutzer, wort = kennwort } = bei;
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, locale: 'de-DE', timezoneId: 'Europe/Berlin', ...extra });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => fehler.push(page.url() + ': ' + e.message));
    await page.goto(url + '/login');
    await page.fill('#username', name);
    await page.fill('#password', wort);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    return { ctx, page };
}

async function oeffnen(page, pfad, warten = 400, url = basis) {
    await page.goto(url + pfad, { waitUntil: 'networkidle' });
    await page.waitForTimeout(warten);
}

/** Ein freier Port auf 127.0.0.1. */
function freierPort() {
    return new Promise((fertig, fehlschlag) => {
        const s = createServer();
        s.on('error', fehlschlag);
        s.listen(0, '127.0.0.1', () => {
            const { port } = s.address();
            s.close(() => fertig(port));
        });
    });
}

/** Text für ein PHP-Literal in einfachen Anführungszeichen. */
const phpText = (t) => "'" + String(t).replace(/[\\']/g, '\\$&') + "'";

/** Was ein `php -r` hinter „@@“ als JSON ausgibt (davor stehen ggf. Meldungen). */
const ergebnis = (aus) => JSON.parse(aus.slice(aus.lastIndexOf('@@') + 2));

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

// ------------------------------------------------- Schnittstelle (andere Instanzen)
{
    const wurzel = resolve(ziel, '../..');
    const tmp = mkdtempSync(join(tmpdir(), 'pm-bilder-api-'));
    const bConfig = join(tmp, 'b.php');
    const umgebungB = { ...process.env, POWERMANAGEMENT_CONFIG: bConfig };
    const inA = (code, ...a) => execFileSync('php', ['-r', `require 'src/bootstrap.php'; ${code}`, ...a], { cwd: wurzel, encoding: 'utf8' });
    const inB = (code, ...a) => execFileSync('php', ['-r', `require 'src/bootstrap.php'; ${code}`, ...a], { cwd: wurzel, encoding: 'utf8', env: umgebungB });
    const portB = await freierPort();
    const b = { url: `http://127.0.0.1:${portB}`, name: 'planung', wort: 'Planung-Bilder-2026!' };
    // Ein einfacher Hallenplan je Etage – die Bilder zeigen nur, dass es ihn gibt.
    const halle = (titel, staende) => `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500" width="800" height="500">
  <rect width="800" height="500" fill="#f8fafc"/><rect x="40" y="40" width="720" height="420" fill="#fff" stroke="#334155" stroke-width="6"/>
  ${Array.from({ length: staende }, (_, i) => `<rect x="${80 + (i % 6) * 110}" y="${90 + Math.floor(i / 6) * 120}" width="90" height="80" fill="#e2e8f0" stroke="#64748b"/>`).join('')}
  <text x="400" y="440" font-family="sans-serif" font-size="22" text-anchor="middle" fill="#334155">${titel}</text>
</svg>`;
    let server = null;
    try {
        writeFileSync(join(tmp, 'eg.svg'), halle('Messehalle 3 – Erdgeschoss', 18));
        writeFileSync(join(tmp, 'og.svg'), halle('Messehalle 3 – Obergeschoss', 12));
        writeFileSync(bConfig, `<?php
$c = require ${phpText(join(wurzel, 'config/config.php'))};
$c['database'] = ['driver' => 'sqlite', 'path' => ${phpText(join(tmp, 'b.sqlite'))}] + $c['database'];
$c['app']['name'] = 'Planungsbüro Süd';
$c['app']['url'] = ${phpText(b.url)};
return $c;
`);

        // A: vorher festhalten (laufend in angelegt.json, damit das Aufräumen
        // auch nach einem Abbruch weiß, was weg muss), zwei Standorte, zwei Zugänge
        const a = ergebnis(inA(`use App\\Auth\\Auth; use App\\Services\\{ApiZugaenge, LocationBundle, PlanMassstabService, SiteService}; use App\\Support\\Database as D;
            [, $name, $tmp] = $argv;
            Auth::alsBenutzer((int) D::scalar('SELECT id FROM users WHERE username = ?', [$name]));
            $plaene = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SiteService::planDir(), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
                $plaene[] = $f->getPathname();
            }
            $gelaende = (int) D::scalar("SELECT id FROM sites WHERE sync_key = 'demo-festival'");
            $a = ['plaene' => $plaene, 'sites' => [], 'tokens' => [],
                'objekte' => D::select('SELECT id, sync_key FROM objekte WHERE site_id = ?', [$gelaende]),
                'leitungen' => D::select('SELECT l.id, l.sync_key FROM leitungen l JOIN floors f ON f.id = l.floor_id WHERE f.site_id = ?', [$gelaende])];
            $merken = static function () use (&$a, $tmp): void { file_put_contents($tmp . '/angelegt.json', json_encode($a)); };
            $merken();
            $halle = SiteService::saveSite(null, ['name' => 'Messehalle 3', 'address' => 'Messering 3, Musterstadt']);
            $a['sites'][] = $halle; $merken();
            foreach (['eg' => ['Erdgeschoss', 0], 'og' => ['Obergeschoss', 1]] as $datei => [$etage, $ebene]) {
                $f = SiteService::saveFloor(null, ['site_id' => $halle, 'name' => $etage, 'level_no' => $ebene, 'sort_order' => $ebene]);
                $pfad = $tmp . '/' . $datei . '.svg';
                SiteService::uploadPlan($halle, ['name' => 'halle3-' . $datei . '.svg', 'type' => 'image/svg+xml', 'tmp_name' => $pfad, 'error' => 0, 'size' => filesize($pfad)], ['floor_ids' => [$f]]);
                PlanMassstabService::speichern($f, ['x' => 5.0, 'y' => 92.0], ['x' => 95.0, 'y' => 92.0], 90.0, 0.625);
            }
            $parkplatz = SiteService::saveSite(null, ['name' => 'Parkplatz P2', 'address' => 'Nordwiese 3, Musterstadt']);
            $a['sites'][] = $parkplatz; $merken();
            $flaeche = SiteService::saveFloor(null, ['site_id' => $parkplatz, 'name' => 'Fläche', 'notes' => 'Einfahrt über Tor 3.']);
            $t = ApiZugaenge::anlegen(['name' => 'Planungsbüro Süd', 'notiz' => 'Stromplanung Sommerfestival',
                'befugnisse' => ['plaene.vorschlagen', 'feststrom'], 'sehen' => [$gelaende, $halle, $parkplatz], 'bearbeiten' => [$gelaende],
                'ablauf' => App\\Support\\Zeit::anzeige(gmdate('c', time() + ApiZugaenge::ABLAUF_TAGE * 86400), 'Y-m-d')]);
            if (!$t['ok']) { throw new RuntimeException($t['message']); }
            $a['tokens'][] = (int) $t['id']; $merken();
            $alt = ApiZugaenge::anlegen(['name' => 'MasterAdmin Netzplanung', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$halle]]);
            $a['tokens'][] = (int) $alt['id']; $merken();
            ApiZugaenge::widerrufen((int) $alt['id']);
            $a += ['gelaende' => $gelaende, 'flaeche' => $flaeche, 'token' => (int) $t['id'], 'klartext' => $t['token'],
                'halle_key' => LocationBundle::schluesselSichern('sites', $halle, 'Messehalle 3'),
                'parkplatz_key' => LocationBundle::schluesselSichern('sites', $parkplatz, 'Parkplatz P2')];
            $merken();
            echo '@@', json_encode($a);`, benutzer, tmp));

        // B: eigene Datenbank, ein Konto, eine eigene „Messehalle 3“ (passt zu),
        // A eintragen, Gelände (mit Feststrom) und Parkplatz übernehmen, am
        // Gelände eine Säule versetzen und eine dazusetzen, als Vorschlag zurück
        execFileSync('php', ['bin/power', 'migrate'], { cwd: wurzel, env: umgebungB, stdio: 'ignore' });
        const bStand = ergebnis(inB(`use App\\Auth\\Auth; use App\\Services\\{AnlagenService, BenutzerService, PartnerAbgleich, PartnerService, SchutzService, SiteService}; use App\\Support\\Database as D;
            [, $name, $wort, $adresse, $token, $parkplatz] = $argv;
            AnlagenService::grundbestand();
            Auth::alsBenutzer(BenutzerService::anlegen($name, $wort, 'admin', false));
            $halle = SiteService::saveSite(null, ['name' => 'Messehalle 3', 'address' => 'Messering 3']);
            SiteService::saveFloor(null, ['site_id' => $halle, 'name' => 'Erdgeschoss', 'level_no' => 0, 'sort_order' => 0]);
            SiteService::saveFloor(null, ['site_id' => $halle, 'name' => 'Obergeschoss', 'level_no' => 1, 'sort_order' => 1]);
            $p = PartnerService::anlegen('Betreiber Nordwiese', $adresse, $token);
            if (!$p['ok']) { throw new RuntimeException($p['message']); }
            $partner = PartnerService::find((int) $p['id']);
            $site = [];
            foreach ([['demo-festival', true], [$parkplatz, false]] as [$k, $fest]) {
                $u = PartnerAbgleich::uebernehmen($partner, $k, null, null, $fest);
                if (!$u['ok']) { throw new RuntimeException($u['message']); }
                $site[$k] = (int) $u['site_id'];
            }
            $s = $site['demo-festival'];
            $etage = D::first('SELECT * FROM floors WHERE site_id = ? ORDER BY sort_order, id LIMIT 1', [$s]);
            $f = (int) $etage['id'];
            AnlagenService::position((int) D::scalar("SELECT id FROM objekte WHERE site_id = ? AND name = 'Energiesäule 2'", [$s]), $f, 58.0, 84.0);
            $neu = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'fest' => 1, 'name' => 'Energiesäule 3', 'kennung' => 'ES-03', 'nennstrom_a' => 63,
                'site_id' => $s, 'floor_id' => $f, 'plan_x' => 86.0, 'plan_y' => 64.0, 'status' => 'in_betrieb', 'ohne_anschluesse' => true,
                'eigenschaften' => ['anschlussart' => 'Energiesäule', 'absicherung_a' => 63]])['id'];
            $typ = array_column(AnlagenService::steckertypen(), 'id', 'schluessel');
            AnlagenService::anzahlSetzen($neu, ['abgang' => [$typ['cee32'] => 2, $typ['cee16'] => 2]]);
            SchutzService::vorschlagen($neu);
            SiteService::saveFloor($f, ['site_id' => $s, 'name' => $etage['name'], 'level_no' => $etage['level_no'], 'sort_order' => $etage['sort_order'],
                'scale_note' => $etage['scale_note'], 'notes' => 'Säule 3 für die Foodtruck-Meile, Säule 2 an den Ostzaun.']);
            $z = PartnerAbgleich::zurueckschicken($s, 'Säule 2 steht jetzt am Ostzaun (Zuleitung 12 m kürzer), Säule 3 ist für die Foodtrucks dazugekommen.');
            if (!$z['ok']) { throw new RuntimeException($z['message']); }
            echo '@@', json_encode(['partner' => (int) $p['id']]);`, b.name, b.wort, basis, a.klartext, a.parkplatz_key));

        // A: am Parkplatz ändert sich etwas (dort geändert), und der Zugang
        // darf jetzt auch schreiben – B sieht nach „Verbindung prüfen“ Hochladen
        const vorschlag = ergebnis(inA(`use App\\Auth\\Auth; use App\\Services\\{ApiZugaenge, SiteService}; use App\\Support\\Database as D;
            [, $name, $tmp] = $argv;
            $a = json_decode(file_get_contents($tmp . '/angelegt.json'), true);
            Auth::alsBenutzer((int) D::scalar('SELECT id FROM users WHERE username = ?', [$name]));
            $f = D::first('SELECT * FROM floors WHERE id = ?', [$a['flaeche']]);
            SiteService::saveFloor((int) $f['id'], ['site_id' => (int) $f['site_id'], 'name' => $f['name'], 'notes' => 'Einfahrt ab 1. Juli nur über Tor 4.']);
            $t = ApiZugaenge::find($a['token']);
            $r = ApiZugaenge::speichern($a['token'], ['name' => $t['name'], 'notiz' => $t['notiz'], 'befugnisse' => ['plaene.schreiben', 'feststrom'],
                'sehen' => array_map(static fn ($s) => $s['id'], $t['standorte']), 'bearbeiten' => [$a['gelaende']],
                'ablauf' => $t['expires_at'] === null ? '' : App\\Support\\Zeit::anzeige((string) $t['expires_at'], 'Y-m-d')]);
            if (!$r['ok']) { throw new RuntimeException($r['message']); }
            echo '@@', json_encode((int) D::scalar("SELECT id FROM api_vorschlaege WHERE token_id = ? AND status = 'offen' ORDER BY id DESC", [$a['token']]));`, benutzer, tmp));
        inB(`use App\\Services\\PartnerService; $r = PartnerService::pruefen((int) $argv[1]); echo '@@', json_encode($r['ok']);`, String(bStand.partner));

        server = spawn('php', ['-S', `127.0.0.1:${portB}`, '-t', 'public'], { cwd: wurzel, env: umgebungB, stdio: 'ignore' });
        for (let i = 0; i < 100; i++) {
            try {
                if ((await fetch(b.url + '/login')).ok) {
                    break;
                }
            } catch {
                // noch nicht da
            }
            await new Promise((r) => setTimeout(r, 100));
        }

        // A: Zugänge und der Vorschlag
        const sa = await sitzung(DESKTOP);
        await oeffnen(sa.page, '/system/schnittstelle');
        await foto(sa.page, 'schnittstelle');
        await oeffnen(sa.page, '/vorschlaege/' + vorschlag);
        await foto(sa.page, 'vorschlag', { fullPage: true });
        await sa.ctx.close();

        // B: die Locations bei A und „Zuordnen“ für die Halle
        const sb = await sitzung(DESKTOP, {}, b);
        await oeffnen(sb.page, '/instanzen/' + bStand.partner, 600, b.url);
        const text = await sb.page.locator('main').innerText();
        for (const z of ['hier geändert', 'passt zu', 'dort geändert', 'Hochladen']) {
            if (!text.includes(z)) {
                fehler.push('Andere Instanzen: „' + z + '“ fehlt auf der Seite');
            }
        }
        await foto(sb.page, 'instanz', { fullPage: true });
        await oeffnen(sb.page, `/instanzen/${bStand.partner}/zuordnen?schluessel=${encodeURIComponent(a.halle_key)}`, 400, b.url);
        await foto(sb.page, 'instanz-zuordnen', { fullPage: true });
        await sb.ctx.close();
    } finally {
        if (server !== null) {
            server.kill('SIGTERM');
        }
        // A wieder wie vorher: Vorschläge und Zugänge, die beiden Standorte,
        // Abgleich-Schlüssel des Geländes, neue Plandateien (die von B)
        inA(`use App\\Auth\\Auth; use App\\Services\\{ApiZugaenge, SiteService, VorschlagService}; use App\\Support\\Database as D;
            [, $name, $tmp] = $argv;
            $a = json_decode((string) @file_get_contents($tmp . '/angelegt.json'), true);
            if (!is_array($a)) { return; }
            Auth::alsBenutzer((int) D::scalar('SELECT id FROM users WHERE username = ?', [$name]));
            $weg = static function (string $pfad) use (&$weg): void {
                if (is_dir($pfad) && !is_link($pfad)) {
                    foreach (array_diff((array) scandir($pfad), ['.', '..']) as $e) { $weg($pfad . '/' . $e); }
                    @rmdir($pfad);
                } elseif (file_exists($pfad) || is_link($pfad)) {
                    @unlink($pfad);
                }
            };
            foreach ($a['tokens'] as $id) {
                foreach (D::select('SELECT id FROM api_vorschlaege WHERE token_id = ?', [$id]) as $v) {
                    $weg(VorschlagService::ordner((int) $v['id']));
                    D::execute('DELETE FROM api_vorschlaege WHERE id = ?', [(int) $v['id']]);
                }
                ApiZugaenge::widerrufen((int) $id);
                ApiZugaenge::loeschen((int) $id);
            }
            foreach ($a['sites'] as $id) {
                if (SiteService::find((int) $id) !== null) { SiteService::deleteSite((int) $id); }
            }
            foreach (['objekte', 'leitungen'] as $tabelle) {
                foreach ($a[$tabelle] as $z) { D::execute("UPDATE $tabelle SET sync_key = ? WHERE id = ?", [$z['sync_key'], $z['id']]); }
            }
            $benutzt = array_flip(array_map('strval', array_column(D::select("SELECT plan_file FROM floors WHERE plan_file <> ''"), 'plan_file')));
            $vorher = array_flip($a['plaene']);
            $neu = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SiteService::planDir(), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
                if (!isset($vorher[$f->getPathname()]) && !isset($benutzt[$f->getFilename()])) { $neu[] = $f->getPathname(); }
            }
            foreach ($neu as $p) { $weg($p); }
            echo '@@', json_encode(true);`, benutzer, tmp);
        rmSync(tmp, { recursive: true, force: true });
    }
}

// ------------------------------------------------------------ Übergabeberichte
{
    // Als Prüfer: das Demo-Konto „technik“ hat Qualifikation und „bearbeiten“ auf dem Gelände.
    const PRUEFER = { name: 'technik', wort: 'Technik2026!!' };
    const wurzel = resolve(ziel, '../..');
    const admin = await sitzung(DESKTOP);
    await oeffnen(admin.page, '/system/einstellungen');
    await element(admin.page, admin.page.locator('.card', { has: admin.page.locator('h2', { hasText: /^Eventworx$/ }) }), 'einstellungen-eventworx');
    await admin.ctx.close();

    const { ctx, page } = await sitzung(DESKTOP, {}, PRUEFER);
    await oeffnen(page, '/berichte');
    await foto(page, 'berichte-liste');
    const zeile = (status) => page.locator('tr').filter({ has: page.locator('.badge', { hasText: status }) }).locator('a[href^="/berichte/"]').first();
    const entwurf = await zeile('Entwurf').getAttribute('href');
    const geprueft = await zeile('vom Prüfer unterschrieben').getAttribute('href');
    const nummer = (await zeile(/^abgeschlossen$/).textContent()).trim();
    await ctx.close();

    // Tablet quer: das Formular des Entwurfs, dann die Eventworx-Suche (Demo-Vorgang, nicht übernommen)
    const tab = await sitzung(TABLET_QUER, { hasTouch: true }, PRUEFER);
    tab.page.on('dialog', (d) => d.accept());
    await oeffnen(tab.page, entwurf, 800);
    await foto(tab.page, 'bericht-formular');
    await tab.page.click('[data-reiter="eventworx"]');
    await tab.page.fill('[data-ewx-q]', 'AU-1042');
    await tab.page.click('[data-ewx-suchen]');
    await tab.page.locator('[data-ewx-treffer] button').first().click();
    await tab.page.waitForSelector('dialog[data-ewx-dialog][open]');
    await tab.page.waitForTimeout(400);
    await foto(tab.page, 'bericht-eventworx');
    await tab.page.keyboard.press('Escape');

    // Kundenmodus: übergeben, Foto, mit dem Kennwort wieder entsperren
    await oeffnen(tab.page, geprueft);
    await Promise.all([tab.page.waitForNavigation(), tab.page.locator('form[action$="/kundenmodus"] button').click()]);
    await tab.page.waitForLoadState('networkidle');
    await tab.page.waitForTimeout(400);
    await foto(tab.page, 'bericht-kundenmodus');
    await tab.page.locator('.kunde-entsperren summary').click();
    await tab.page.fill('input[name=kennwort]', PRUEFER.wort);
    await Promise.all([tab.page.waitForNavigation(), tab.page.locator('form[action$="/kunde/beenden"] button').click()]);
    await tab.ctx.close();

    // Handy: die Stromkreise des Entwurfs
    const handy = await sitzung(HANDY, { hasTouch: true, isMobile: true }, PRUEFER);
    await oeffnen(handy.page, entwurf, 800);
    await handy.page.locator('[data-kreise] .kreis').first().scrollIntoViewIfNeeded();
    await handy.page.evaluate(() => window.scrollBy(0, -70));
    await handy.page.waitForTimeout(300);
    await foto(handy.page, 'bericht-messprotokoll-handy');
    await handy.ctx.close();

    // Seite 1 des gespeicherten PDF – über die Kommandozeile, wie es ein Betreiber herausholt
    const tmp = mkdtempSync(join(tmpdir(), 'pm-bilder-'));
    try {
        execFileSync('php', ['bin/power', 'berichte:pdf', nummer, join(tmp, 'bericht.pdf')], { cwd: wurzel });
        execFileSync('pdftoppm', ['-png', '-r', '90', '-f', '1', '-l', '1', '-singlefile', join(tmp, 'bericht.pdf'), join(ziel, 'bericht-pdf-1')]);
        console.log('  bericht-pdf-1.png');
    } finally {
        rmSync(tmp, { recursive: true, force: true });
    }
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
