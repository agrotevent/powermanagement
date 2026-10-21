<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Audit;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Programm;
use App\Support\Session;
use App\Support\Settings;

/**
 * Abgleich der Locations mit dem gemeinsamen Git-Repository – dasselbe
 * Repository, das MasterAdmin für seine Grundrisse benutzt.
 *
 * Übernommen aus MasterAdmin (InventorySyncService), zugeschnitten auf das,
 * was PowerManagement braucht:
 *
 *   locations/<schlüssel>/location.json   gehört MasterAdmin: Etagen, Plan,
 *                                          Maßstab, Dosen, Racks. Wird hier
 *                                          gelesen und nur ergänzt (neue
 *                                          Etagen, fehlender Plan/Maßstab).
 *   locations/<schlüssel>/strom.json      gehört PowerManagement: die
 *                                          Feststromanschlüsse der Location
 *                                          mit Anschlüssen, Schutzorganen
 *                                          und Messgeräten, dazu die fest
 *                                          verlegten Kabel und Kabelwege.
 *                                          Mobile Anlagen und Kabel bleiben
 *                                          in der Installation, die sie plant.
 *
 * So pflegt jedes Werkzeug seinen Teil, und beide sehen denselben Grundriss.
 *
 * Warum Git und nicht eine Datei zum Herunterladen: es geht um Bestände,
 * die über Jahre gepflegt werden. Wer wissen will, wann eine Dose
 * dazukam, findet es dort; wer etwas kaputt macht, kann es zurückholen.
 * Ein Zip-Archiv kann beides nicht.
 *
 * **Das Token steht nicht in der Datenbank und nicht in der Oberfläche.**
 * Es kommt aus der Umgebung des Dienstes (POWERMANAGEMENT_REPO_TOKEN).
 * Zwei Fallen werden dabei umgangen:
 *
 * * Es landet **nicht in der Adresse** des Repositories – sonst stünde es
 *   im Klartext in `.git/config` und in jeder Sicherung.
 * * Es landet **nicht auf der Kommandozeile** – sonst stünde es in der
 *   Prozessliste, die auf einem LXC jeder lesen kann.
 *
 * Stattdessen fragt Git über `GIT_ASKPASS` ein kleines Skript, das es aus
 * der Umgebung ausgibt. Die Umgebung eines Prozesses gehört ihm allein.
 */
final class LocationSync
{
    /** Name der Umgebungsvariablen mit dem Zugangstoken. */
    public const TOKEN_VARIABLE = 'POWERMANAGEMENT_REPO_TOKEN';

    /** Wie lange ein Git-Aufruf höchstens dauern darf. */
    private const ZEITGRENZE = 120;

    /** Die git-Befehle, die das Repository fragen – auf die wird gewartet, ohne die Sitzung zu halten. */
    private const UEBERS_NETZ = ['clone', 'fetch', 'ls-remote', 'push'];

    /** Was ein zweiter Abgleich meldet, während einer läuft. */
    public const BELEGT = 'Das Repository wird gerade schon abgeglichen (anderer Reiter oder anderer Benutzer) – nichts doppelt gemacht. Gleich die Seite neu laden.';

    /** Wie tief diese Anfrage in gesperrt() steckt – darin geht es ohne zweite Sperre weiter. */
    private static int $sperrTiefe = 0;

    // ---------------------------------------------------------- Einrichtung

    public static function repoUrl(): string
    {
        return trim((string) Config::get('inventory.repo_url', ''));
    }

    /** Die Übertragungswege, die git hier benutzen darf. */
    private const PROTOKOLLE = 'https:ssh:file';

    /**
     * Taugt die Adresse als Repository? null = ja, sonst der Grund.
     *
     * Erlaubt sind https://, SSH (ssh://… oder git@host:pfad) und file:// für
     * ein Repository auf derselben Maschine. Nicht erlaubt: http:// (das Token
     * ginge im Klartext über das Netz), Zugangsdaten in der Adresse (sie
     * stünden in der Oberfläche, im Audit-Log und in .git/config), und alles,
     * was git als Option lesen könnte.
     */
    public static function adresseFehler(string $adresse): ?string
    {
        if ($adresse === '') {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7f]/', $adresse) === 1 || str_starts_with($adresse, '-')) {
            return 'Die Adresse darf keine Leer- oder Steuerzeichen enthalten und nicht mit „-“ beginnen.';
        }
        if (preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[^/][^\s]*$#', $adresse) === 1) {
            return null; // git@github.com:firma/locations.git
        }
        $teile = parse_url($adresse);
        $schema = strtolower((string) ($teile['scheme'] ?? ''));
        if (!in_array($schema, ['https', 'ssh', 'file'], true)) {
            return 'Erlaubt sind https://, ssh:// (oder git@server:pfad) und file://.';
        }
        if (isset($teile['pass']) || ($schema === 'https' && isset($teile['user']))) {
            return 'Zugangsdaten gehören nicht in die Adresse – das Token hat ein eigenes Feld.';
        }
        if ($schema !== 'file' && (string) ($teile['host'] ?? '') === '') {
            return 'In der Adresse fehlt der Server.';
        }
        return null;
    }

    /** Ein Zweigname, den git nicht als Option liest. */
    public static function zweigGueltig(string $zweig): bool
    {
        return preg_match('#^(?!-)(?!.*\.\.)[A-Za-z0-9._/-]{1,100}$#', $zweig) === 1 && !str_ends_with($zweig, '/') && !str_ends_with($zweig, '.lock');
    }

    /** Der Server einer Adresse (klein geschrieben) – für „ist das noch derselbe?“. */
    public static function server(string $adresse): string
    {
        if (preg_match('#^[A-Za-z0-9._-]+@([A-Za-z0-9.-]+):#', $adresse, $treffer) === 1) {
            return strtolower($treffer[1]);
        }
        $teile = parse_url($adresse);
        return strtolower((string) ($teile['host'] ?? ($teile['scheme'] ?? '')));
    }

    /**
     * Das Zugangstoken.
     *
     * Zwei Quellen, in dieser Reihenfolge:
     *
     * 1. Was in der Systemverwaltung eingetragen wurde – verschlüsselt
     *    abgelegt wie die Gerätekennwörter und nie wieder angezeigt.
     * 2. Die Umgebungsvariable, für Installationen, die es lieber gar
     *    nicht in der Datenbank haben.
     *
     * Das Eingabefeld gewinnt: wer dort etwas einträgt, will, dass es
     * gilt. Ein hinterlegtes Token liegt damit auch in jeder Sicherung –
     * verschlüsselt, aber es liegt dort. Wem das zu viel ist, lässt das
     * Feld leer und setzt die Umgebungsvariable.
     */
    public static function token(): string
    {
        $abgelegt = Settings::all()[Settings::GEHEIM_REPO_TOKEN] ?? '';
        if (is_string($abgelegt) && $abgelegt !== '') {
            $klar = Crypto::decrypt($abgelegt);
            if ($klar !== '') {
                return trim($klar);
            }
        }

        $wert = getenv(self::TOKEN_VARIABLE);
        return is_string($wert) ? trim($wert) : '';
    }

    /** Steht ein Token in der Systemverwaltung? */
    public static function tokenHinterlegt(): bool
    {
        $abgelegt = Settings::all()[Settings::GEHEIM_REPO_TOKEN] ?? '';
        return is_string($abgelegt) && $abgelegt !== '';
    }

    /** Woher das Token kommt – für die Anzeige, ohne es zu zeigen. */
    public static function tokenQuelle(): string
    {
        if (self::tokenHinterlegt()) {
            return 'eingetragen';
        }
        $wert = getenv(self::TOKEN_VARIABLE);
        return is_string($wert) && trim($wert) !== '' ? 'umgebung' : 'fehlt';
    }

    /** Token eintragen oder entfernen. */
    public static function tokenSetzen(string $klartext): void
    {
        $klartext = trim($klartext);
        if ($klartext === '') {
            Settings::forget(Settings::GEHEIM_REPO_TOKEN);
            return;
        }
        Settings::put(Settings::GEHEIM_REPO_TOKEN, Crypto::encrypt($klartext));
    }

    public static function zweig(): string
    {
        $zweig = trim((string) Config::get('inventory.branch', 'main'));
        return $zweig !== '' ? $zweig : 'main';
    }

    /** Wo die Arbeitskopie liegt. */
    public static function arbeitsKopie(): string
    {
        return dirname(__DIR__, 2) . '/storage/inventar/repo';
    }

    /**
     * Was der Einrichtung noch fehlt – oder null, wenn alles da ist.
     *
     * Wie bei den Grundrissen gilt: die Oberfläche soll sagen, **was**
     * fehlt und wie es zu beheben ist, statt einen Knopf anzubieten, der
     * nichts bewirken kann.
     */
    public static function hindernis(): ?string
    {
        if (self::gitPfad() === null) {
            return 'Auf dem Server fehlt das Paket git – ohne das lässt sich kein Repository '
                . 'abgleichen. Nachinstallieren mit: apt-get install -y git. '
                . 'Das Update-Skript (deploy/update-debian.sh) zieht es beim nächsten Lauf '
                . 'ebenfalls nach.';
        }
        if (self::repoUrl() !== '' && self::adresseFehler(self::repoUrl()) !== null) {
            return 'Die eingetragene Repository-Adresse wird nicht benutzt: ' . self::adresseFehler(self::repoUrl())
                . ' Bitte unter System → Einstellungen → Location- und Verteiler-Abgleich korrigieren.';
        }
        if (!self::zweigGueltig(self::zweig())) {
            return 'Der eingetragene Zweig „' . self::zweig() . '“ ist kein gültiger Zweigname.';
        }
        if (self::repoUrl() === '') {
            /*
             * Die Adresse steht in der Oberfläche, nicht in einer Datei.
             * Hier stand bis eben der Weg über config/config.php – also
             * genau die Stelle, an die auf einem LXC niemand herankommt,
             * und an der die Adresse seit dem Umzug in die Einstellungen
             * auch gar nicht mehr gelesen wird.
             */
            return 'Es ist kein Location-Repository eingetragen. Die Adresse steht unter '
                . 'System → Einstellungen → Location- und Verteiler-Abgleich (dasselbe Repository wie in MasterAdmin).';
        }
        /*
         * Ein Token braucht nur, wer über das Netz geht. Ein Repository
         * auf derselben Maschine (oder über SSH mit einem Schlüssel) kommt
         * ohne aus – und die Prüfung darf das nicht verlangen, sonst
         * bleibt eine funktionierende Einrichtung abgeschaltet.
         */
        if (self::token() === '' && str_starts_with(self::repoUrl(), 'http')) {
            return 'Es ist kein Zugangstoken hinterlegt. Unter System → Einstellungen → '
                . 'Location- und Verteiler-Abgleich eintragen (es wird verschlüsselt abgelegt und nie wieder '
                . 'angezeigt) – oder die Umgebungsvariable ' . self::TOKEN_VARIABLE . ' im '
                . 'PHP-FPM-Pool setzen (/etc/php/<Version>/fpm/pool.d/powermanagement.conf, Zeile '
                . 'env[' . self::TOKEN_VARIABLE . '] = …, danach php-fpm neu laden).';
        }
        return null;
    }

    public static function bereit(): bool
    {
        return self::hindernis() === null;
    }

    /**
     * $arbeit unter der Sperre der Arbeitskopie: Holen, Lesen, Einchecken
     * und Hochladen laufen nur einmal zur Zeit – Location- und
     * Verteiler-Abgleich teilen sich die eine Arbeitskopie. Seit git auf das
     * Repository wartet, ohne die Sitzung zu halten (git()), liefen zwei
     * Klicks aus demselben Browser sonst nebeneinander: zweimal dieselbe
     * Location übernommen (im ungünstigen Fall als zwei Standorte), ein
     * `reset --hard` mitten in einem Hochladen, ein Klonen, das ein anderes
     * wegräumt.
     *
     * Nicht blockierend wie PartnerAbgleich::gesperrt(): Wer die Sperre
     * nicht bekommt, hört mit BELEGT auf. Warten ginge nicht – die zweite
     * Anfrage hielte dabei die Sitzung, und die erste bekäme sie nach dem
     * nächsten git-Aufruf nicht wieder. Innerhalb einer gesperrten Arbeit
     * (uebernehmen() ruft holen()) geht es ohne zweite Sperre weiter.
     *
     * @param callable():array<string,mixed> $arbeit
     * @return array<string,mixed> das Ergebnis von $arbeit – oder ['ok' => false, 'message' => …]
     */
    public static function gesperrt(callable $arbeit): array
    {
        if (self::$sperrTiefe > 0) {
            return $arbeit();
        }
        $pfad = dirname(self::arbeitsKopie()) . '/abgleich.sperre';
        if (!is_dir(dirname($pfad))) {
            @mkdir(dirname($pfad), 0775, true);
        }
        $griff = @fopen($pfad, 'c');
        if ($griff === false) {
            Logger::error('Inventar-Abgleich: Sperrdatei ließ sich nicht anlegen: ' . $pfad);
            return ['ok' => false, 'message' => 'storage/inventar ist nicht beschreibbar – nichts abgeglichen.'];
        }
        if (!flock($griff, LOCK_EX | LOCK_NB)) {
            fclose($griff);
            return ['ok' => false, 'message' => self::BELEGT];
        }
        self::$sperrTiefe++;
        try {
            return $arbeit();
        } finally {
            self::$sperrTiefe--;
            flock($griff, LOCK_UN);
            fclose($griff);
        }
    }

    // ------------------------------------------------------------- Holen

    /**
     * Die Arbeitskopie auf den Stand des Repositories bringen.
     *
     * Beim ersten Mal wird geklont, danach geholt und hart auf den Zweig
     * gesetzt. Hart, weil die Arbeitskopie kein Arbeitsplatz ist, sondern
     * ein Spiegel: was hier von Hand geändert wurde, war ein Versehen.
     *
     * Unter der Sperre der Arbeitskopie (gesperrt()). Wer danach noch darin
     * liest oder schreibt, nimmt die Sperre selbst vorher – sonst setzte ein
     * anderer Abgleich sie zwischendurch zurück.
     *
     * @return array{ok:bool,message:string,commit:string}
     */
    public static function holen(): array
    {
        return self::gesperrt(static fn (): array => self::holenGesperrt()) + ['commit' => ''];
    }

    /** holen() unter der Sperre der Arbeitskopie. */
    private static function holenGesperrt(): array
    {
        $fehlt = self::hindernis();
        if ($fehlt !== null) {
            return ['ok' => false, 'message' => $fehlt, 'commit' => ''];
        }

        $kopie = self::arbeitsKopie();
        if (!is_dir(dirname($kopie)) && !mkdir(dirname($kopie), 0775, true) && !is_dir(dirname($kopie))) {
            return ['ok' => false, 'message' => 'Der Ordner für die Arbeitskopie ließ sich nicht anlegen.', 'commit' => ''];
        }

        if (is_dir($kopie . '/.git')) {
            /*
             * Steht in den Einstellungen inzwischen ein anderes Repository,
             * gehört die Arbeitskopie zum alten. Sie ist ein Spiegel, also
             * wird sie neu geholt. Sonst liefen Abfragen und Hochladen still
             * weiter gegen die alte Adresse – und ein noch leeres neues
             * Repository bekäme beim ersten Hochladen die Geschichte des
             * alten mit.
             */
            $herkunft = self::git(['remote', 'get-url', 'origin'], $kopie);
            if (!$herkunft['ok'] || trim($herkunft['ausgabe']) !== self::repoUrl()) {
                self::verzeichnisWeg($kopie);
            }
        }

        if (!is_dir($kopie . '/.git')) {
            /*
             * Reste eines abgebrochenen Klonens wegräumen. Git legt das
             * Zielverzeichnis an, bevor es scheitert, und weigert sich
             * beim nächsten Versuch, in ein nicht leeres Verzeichnis zu
             * klonen – „destination path already exists". Gemessen in
             * GitHub Actions, wo genau das den ganzen Abgleich lahmlegte.
             */
            if (is_dir($kopie)) {
                self::verzeichnisWeg($kopie);
            }

            /*
             * Ohne --branch geklont. Ein leeres Repository hat den Zweig
             * noch nicht, und `clone --branch main` scheitert daran – das
             * ist aber kein Fehler, sondern der erste Tag.
             */
            $ergebnis = self::git(['clone', '--depth', '50', '--', self::repoUrl(), $kopie], dirname($kopie));
            if (!$ergebnis['ok']) {
                return ['ok' => false, 'message' => 'Das Repository ließ sich nicht holen: ' . $ergebnis['message'], 'commit' => ''];
            }
        }

        $stand = self::zweigHerstellen($kopie);
        if (!$stand['ok']) {
            return ['ok' => false, 'message' => $stand['message'], 'commit' => self::kopfCommit()];
        }

        return ['ok' => true, 'message' => $stand['message'], 'commit' => self::kopfCommit()];
    }

    /**
     * Die Arbeitskopie auf den gewünschten Zweig bringen.
     *
     * Drei Fälle, und alle drei kommen vor:
     *
     * 1. Den Zweig gibt es im Repository – holen und hart darauf setzen.
     *    Hart, weil die Arbeitskopie ein Spiegel ist und kein
     *    Arbeitsplatz.
     * 2. Es gibt ihn dort nicht, aber hier liegen Commits (etwa weil das
     *    Repository `master` führt und wir `main` wollen) – dann den
     *    Zweig hier anlegen.
     * 3. Das Repository ist leer – dann gibt es nichts zu holen, und HEAD
     *    zeigt einfach auf den künftigen Zweig. Der entsteht beim ersten
     *    Hochladen.
     *
     * @return array{ok:bool,message:string}
     */
    private static function zweigHerstellen(string $kopie): array
    {
        $fern = self::git(['ls-remote', '--heads', 'origin', self::zweig()], $kopie);
        if (!$fern['ok']) {
            return ['ok' => false, 'message' => 'Das Repository ist nicht erreichbar: ' . $fern['message']];
        }

        if (trim($fern['ausgabe']) !== '') {
            /*
             * Mit ausdrücklicher Refspec holen, damit `origin/<zweig>`
             * in jedem Fall entsteht.
             *
             * Ohne sie schrieb `git fetch origin <zweig>` nur FETCH_HEAD.
             * Das genügt, solange die Arbeitskopie eine Refspec
             * konfiguriert hat – und genau die fehlt, wenn beim ersten
             * Klonen noch nichts im Repository stand: `clone --depth`
             * oben bedeutet `--single-branch`, und ein leeres
             * Repository hat keinen Zweig, den git dafür einsetzen
             * könnte. `remote.origin.fetch` blieb dann leer.
             *
             * Die Folge war still und dauerhaft: `origin/<zweig>` gab es
             * nie, `reset --hard origin/<zweig>` scheiterte, der
             * Rückgabewert wurde nicht angesehen – und diese Funktion
             * meldete „Auf dem Stand des Repositories", während die
             * Arbeitskopie auf dem ersten Commit stehen blieb. Eine
             * Installation, die ihren Abgleich gegen ein noch leeres
             * Repository eingerichtet hat, bekam also nie wieder etwas
             * von den anderen zu sehen.
             */
            $zweig = self::zweig();
            $holen = self::git([
                'fetch', 'origin',
                '+refs/heads/' . $zweig . ':refs/remotes/origin/' . $zweig,
            ], $kopie);
            if (!$holen['ok']) {
                return ['ok' => false, 'message' => 'Holen fehlgeschlagen: ' . $holen['message']];
            }

            // Ab hier werden die Rückgabewerte angesehen. Ein Scheitern
            // an dieser Stelle ist kein Schönheitsfehler: es bedeutet,
            // dass die Arbeitskopie nicht das zeigt, was im Repository
            // steht – und alles Weitere rechnet mit dem falschen Stand.
            $wechsel = self::git(['checkout', '-B', $zweig, 'origin/' . $zweig], $kopie);
            if (!$wechsel['ok']) {
                return ['ok' => false, 'message' => 'Der Wechsel auf ' . $zweig . ' scheiterte: ' . $wechsel['message']];
            }
            $setzen = self::git(['reset', '--hard', 'origin/' . $zweig], $kopie);
            if (!$setzen['ok']) {
                return ['ok' => false, 'message' => 'Die Arbeitskopie ließ sich nicht auf den Stand bringen: ' . $setzen['message']];
            }

            return ['ok' => true, 'message' => 'Auf dem Stand des Repositories.'];
        }

        $hatCommits = self::git(['rev-parse', '--verify', 'HEAD'], $kopie)['ok'];
        if ($hatCommits) {
            self::git(['checkout', '-B', self::zweig()], $kopie);
        } else {
            self::git(['symbolic-ref', 'HEAD', 'refs/heads/' . self::zweig()], $kopie);
        }

        return ['ok' => true, 'message' => 'Der Zweig ' . self::zweig() . ' entsteht beim ersten Hochladen.'];
    }

    /** Ein Verzeichnis samt Inhalt entfernen – nur innerhalb von storage. */
    private static function verzeichnisWeg(string $pfad): void
    {
        $basis = dirname(__DIR__, 2) . '/storage/';
        if (!is_dir($pfad) || !str_starts_with($pfad, $basis)) {
            return;
        }
        $lauf = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($lauf as $eintrag) {
            $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
        }
        @rmdir($pfad);
    }

    /** Auf welchem Commit die Arbeitskopie steht. */
    public static function kopfCommit(): string
    {
        $ergebnis = self::git(['rev-parse', '--short', 'HEAD'], self::arbeitsKopie());
        return $ergebnis['ok'] ? trim($ergebnis['ausgabe']) : '';
    }

    // ------------------------------------------------------------ Lesen

    /**
     * Die Locations im Repository – mit der Angabe, ob und wie aktuell sie
     * hier eingespielt sind, und wie viele Feststromanschlüsse dazu gehören.
     *
     * Holt vorher den Stand des Repositories; das kostet einen Netzzugriff
     * und wird deshalb nur auf ausdrücklichen Klick gerufen.
     *
     * Status je Location: neu | aktuell | neuer | eingespielt (wie MasterAdmin).
     *
     * Unter der Sperre der Arbeitskopie (gesperrt()): vom Holen bis zum Lesen.
     *
     * @return array{ok:bool,message:string,zweig:string,commit:string,hinweis:string,locations:list<array<string,mixed>>}
     */
    public static function locationsImRepo(): array
    {
        return self::gesperrt(static fn (): array => self::locationsImRepoGesperrt())
            + ['zweig' => self::zweig(), 'commit' => '', 'hinweis' => '', 'locations' => []];
    }

    /** locationsImRepo() unter der Sperre der Arbeitskopie. */
    private static function locationsImRepoGesperrt(): array
    {
        $antwort = ['ok' => false, 'message' => '', 'zweig' => self::zweig(), 'commit' => '', 'hinweis' => '', 'locations' => []];
        $holen = self::holen();
        if (!$holen['ok']) {
            $antwort['message'] = $holen['message'];
            return $antwort;
        }
        $antwort['ok'] = true;
        $antwort['commit'] = $holen['commit'];
        $antwort['hinweis'] = trim(self::zweigHinweis());

        $hier = [];
        foreach (Database::select("SELECT id, name, sync_key, synced_at, synced_commit FROM sites WHERE sync_key <> ''") as $site) {
            $hier[(string) $site['sync_key']] = $site;
        }

        foreach (glob(self::arbeitsKopie() . '/locations/*/location.json') ?: [] as $pfad) {
            $daten = json_decode((string) file_get_contents($pfad), true);
            if (!is_array($daten)) {
                continue;
            }
            $schluessel = (string) ($daten['schluessel'] ?? basename(dirname($pfad)));
            $strom = self::stromLesen($schluessel);
            $site = $hier[$schluessel] ?? null;
            $antwort['locations'][] = [
                'schluessel' => $schluessel,
                'name'       => (string) ($daten['name'] ?? $schluessel),
                'adresse'    => (string) ($daten['adresse'] ?? ''),
                'etagen'     => count((array) ($daten['etagen'] ?? [])),
                'plaene'     => count(array_filter((array) ($daten['etagen'] ?? []), static fn ($e) => !empty($e['plan']['datei']))),
                'fest'       => $strom !== null ? count(LocationBundle::festeAnlagen($strom)) : 0,
                'fest_leitungen' => $strom !== null ? count(LocationBundle::festeLeitungen($strom)) : 0,
                'hat_strom'  => $strom !== null,
                'status'     => $site === null ? 'neu' : self::aktualitaet('locations/' . $schluessel, (string) ($site['synced_commit'] ?? '')),
                'site_id'    => $site !== null ? (int) $site['id'] : 0,
                'site_name'  => $site !== null ? (string) $site['name'] : '',
                'synced_at'  => $site !== null ? (string) ($site['synced_at'] ?? '') : '',
            ];
        }
        usort($antwort['locations'], static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $antwort;
    }

    /**
     * Ist der Stand, der hier eingespielt wurde, noch der neueste dort?
     * Verglichen wird der letzte Commit, der den Ordner geändert hat.
     */
    private static function aktualitaet(string $pfad, string $abgeglichen): string
    {
        if ($abgeglichen === '') {
            return 'eingespielt';
        }
        $letzte = self::git(['log', '-1', '--format=%H', '--', $pfad], self::arbeitsKopie());
        $letzte = $letzte['ok'] ? trim($letzte['ausgabe']) : '';
        if ($letzte === '' || self::commitVon($abgeglichen) === '') {
            return 'eingespielt';
        }
        return self::git(['merge-base', '--is-ancestor', $letzte, $abgeglichen], self::arbeitsKopie())['ok']
            ? 'aktuell'
            : 'neuer';
    }

    /** location.json einer Location aus der Arbeitskopie. */
    public static function locationLesen(string $schluessel): ?array
    {
        $pfad = self::bestandPfad($schluessel, 'location.json');
        if ($pfad === null || !is_file($pfad)) {
            return null;
        }
        $daten = json_decode((string) file_get_contents($pfad), true);
        return is_array($daten) ? $daten : null;
    }

    /** strom.json einer Location aus der Arbeitskopie – oder null. */
    public static function stromLesen(string $schluessel): ?array
    {
        $pfad = self::bestandPfad($schluessel, 'strom.json');
        if ($pfad === null || !is_file($pfad)) {
            return null;
        }
        $daten = json_decode((string) file_get_contents($pfad), true);
        return is_array($daten) ? $daten : null;
    }

    // -------------------------------------------------------- Übernehmen

    /**
     * Eine Location aus dem Repository übernehmen: Etagen, Grundrisse und
     * Maßstab aus location.json, die Feststromanschlüsse aus strom.json.
     *
     * Unter der Sperre der Arbeitskopie (gesperrt()): Ein zweites Übernehmen
     * – Doppelklick, zweiter Reiter – legte sonst, während beide auf das
     * Repository warten, einen zweiten Standort an.
     *
     * @return array{ok:bool,message:string}
     */
    public static function uebernehmen(string $schluessel): array
    {
        return self::gesperrt(static fn (): array => self::uebernehmenGesperrt($schluessel));
    }

    /** uebernehmen() unter der Sperre der Arbeitskopie – vom Holen bis zum Eintragen. */
    private static function uebernehmenGesperrt(string $schluessel): array
    {
        $holen = self::holen();
        if (!$holen['ok']) {
            return ['ok' => false, 'message' => $holen['message']];
        }
        $daten = self::locationLesen($schluessel);
        if ($daten === null) {
            return ['ok' => false, 'message' => 'Diese Location gibt es im Repository nicht.'];
        }
        $ordner = dirname((string) self::bestandPfad($schluessel, 'location.json'));
        // Der Ordner selbst darf kein Verweis sein – sonst läge die Grenze
        // unten, wo der Verweis hinzeigt.
        $basis = realpath($ordner);
        if ($basis === false || $basis !== realpath(self::arbeitsKopie()) . '/locations/' . $schluessel) {
            return ['ok' => false, 'message' => 'Der Ordner dieser Location im Repository ist kein gewöhnlicher Ordner.'];
        }
        $datei = static function (string $imRepo) use ($basis): ?string {
            // Kein Ausbruch aus dem Ordner der Location: was im Repository
            // steht, kommt von außen.
            $ziel = realpath($basis . '/' . $imRepo);
            return ($ziel !== false && str_starts_with($ziel, $basis . '/') && is_file($ziel)) ? $ziel : null;
        };

        $ergebnis = LocationBundle::locationAnwenden($daten, $datei);
        if (!$ergebnis['ok']) {
            return $ergebnis;
        }
        $meldung = $ergebnis['message'];
        $strom = self::stromLesen($schluessel);
        if ($strom !== null) {
            $teil = LocationBundle::stromAnwenden((int) $ergebnis['site_id'], $strom);
            $meldung .= ' ' . $teil['message'];
        }
        Database::execute(
            'UPDATE sites SET synced_at = ?, synced_commit = ? WHERE id = ?',
            [gmdate('c'), self::kopfCommit(), (int) $ergebnis['site_id']]
        );
        Audit::log('location.uebernehmen', 'site', (string) $ergebnis['site_id'], ['schluessel' => $schluessel, 'strom' => $strom !== null]);
        return ['ok' => true, 'message' => $meldung];
    }

    // --------------------------------------------------------- Hochladen

    /**
     * Eine Location hochladen.
     *
     * strom.json wird immer ganz geschrieben – sie gehört uns und enthält
     * nur die Feststromanschlüsse und fest verlegten Leitungen; mobile
     * Anlagen und Kabel bleiben hier.
     * location.json
     * wird nur angelegt, wenn es sie noch nicht gibt, und sonst höchstens
     * ergänzt (neue Etagen, ein Plan oder Maßstab, der dort fehlt). Dosen,
     * Racks und Accesspoints aus MasterAdmin bleiben unberührt.
     *
     * Unter der Sperre der Arbeitskopie (gesperrt()): vom Holen über das
     * Schreiben der Dateien bis zum Hochladen.
     *
     * @return array{ok:bool,message:string}
     */
    public static function locationHochladen(int $siteId, string $wer): array
    {
        return self::gesperrt(static fn (): array => self::locationHochladenGesperrt($siteId, $wer));
    }

    /** locationHochladen() unter der Sperre der Arbeitskopie. */
    private static function locationHochladenGesperrt(int $siteId, string $wer): array
    {
        $site = SiteService::find($siteId);
        if ($site === null) {
            return ['ok' => false, 'message' => 'Diese Location gibt es nicht.'];
        }
        $holen = self::holen();
        if (!$holen['ok']) {
            return ['ok' => false, 'message' => $holen['message']];
        }
        $schluessel = LocationBundle::schluesselSichern('sites', $siteId, (string) $site['name'], 'standort-' . $siteId);
        if (!LocationBundle::schluesselGueltig($schluessel)) {
            return ['ok' => false, 'message' => 'Der Schlüssel dieses Standorts ist ungültig.'];
        }
        $ordnerImRepo = 'locations/' . $schluessel;
        $ziel = self::arbeitsKopie() . '/' . $ordnerImRepo;
        if (!is_dir($ziel) && !mkdir($ziel, 0775, true) && !is_dir($ziel)) {
            return ['ok' => false, 'message' => 'Der Ordner im Repository ließ sich nicht anlegen.'];
        }
        $geruest = self::grundgeruestAnlegen();

        $vorhanden = self::locationLesen($schluessel);
        $location = LocationBundle::locationFuerRepo($siteId, $vorhanden);
        foreach ($location['dateien'] as $imRepo => $aufPlatte) {
            $dateiZiel = $ziel . '/' . $imRepo;
            if (!is_dir(dirname($dateiZiel))) {
                mkdir(dirname($dateiZiel), 0775, true);
            }
            copy($aufPlatte, $dateiZiel);
        }
        if ($location['geaendert']) {
            file_put_contents($ziel . '/location.json', self::json($location['daten']));
        }
        file_put_contents($ziel . '/strom.json', self::json(LocationBundle::stromLesen($siteId)));

        $ergebnis = self::abschliessen(
            array_merge([$ordnerImRepo], $geruest),
            $ordnerImRepo . '/strom.json',
            'Feststromanschlüsse ' . $schluessel . ' aus ' . (string) Config::get('app.name', 'PowerManagement'),
            'Hochgeladen von ' . $wer
        );
        if (!$ergebnis['ok']) {
            return ['ok' => false, 'message' => $ergebnis['message']];
        }
        Database::execute('UPDATE sites SET synced_at = ?, synced_commit = ? WHERE id = ?', [gmdate('c'), self::kopfCommit(), $siteId]);
        if (!$ergebnis['hochgeladen']) {
            return ['ok' => true, 'message' => 'Im Repository stand schon derselbe Stand – nichts zu tun. ' . $ergebnis['wo'] . self::zweigHinweis()];
        }
        Audit::log('location.hochladen', 'site', (string) $siteId, ['schluessel' => $schluessel, 'location_json' => $location['geaendert']]);
        return ['ok' => true, 'message' => $site['name'] . ' ist hochgeladen'
            . ($location['geaendert'] ? ' (Etagen/Grundriss in location.json ergänzt)' : '') . '. ' . $ergebnis['wo']
            . ($geruest !== [] ? ' Das Repository war leer und ist jetzt angelegt.' : '')
            . self::zweigHinweis()];
    }

    /** JSON so, wie es im Repository steht – lesbar und mit Zeilenende. */
    public static function json(array $daten): string
    {
        return json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * Einchecken, hochladen und nachsehen, ob es angekommen ist.
     *
     * Vorher entschied allein `git status`, ob es etwas zu tun gibt – und
     * das sagt nur, ob die Arbeitskopie vom **lokalen** Commit abweicht.
     * War ein Push einmal gescheitert (Token, Netz, Rechte), lag der
     * Commit danach lokal fest; der nächste Klick fand keine Änderung und
     * meldete „nichts zu tun“, ohne je hochzuladen. Im Repository stand
     * nichts, und keine Meldung sagte es.
     *
     * Jetzt zählt der Stand **dort**: hochgeladen wird, solange der Zweig
     * im Repository nicht auf dem lokalen Commit steht, und danach wird
     * nachgesehen, ob er es tut.
     *
     * Auch für den Verteiler-Abgleich: der schreibt seine Dateien in
     * dieselbe Arbeitskopie. Entfernte Dateien werden mit eingecheckt
     * (git add nimmt auch das Löschen auf); dann gibt es keinen Beleg.
     *
     * @param list<string> $pfade was eingecheckt wird
     * @param string|null $beleg eine Datei, die danach im Repository stehen muss
     * @return array{ok:bool,message:string,hochgeladen:bool,wo:string}
     */
    public static function abschliessen(array $pfade, ?string $beleg, string $nachricht, string $zusatz = ''): array
    {
        $kopie = self::arbeitsKopie();
        $zweig = self::zweig();
        $fehler = static fn (string $text): array => ['ok' => false, 'message' => $text, 'hochgeladen' => false, 'wo' => ''];

        $add = self::git(array_merge(['add', '--'], $pfade), $kopie);
        if (!$add['ok']) {
            // Etwa eine .gitignore im Repository, die den Ordner ausschließt.
            return $fehler('Die Dateien ließen sich nicht einchecken: ' . $add['message']);
        }

        $stand = self::git(array_merge(['status', '--porcelain', '--'], $pfade), $kopie);
        if (!$stand['ok']) {
            return $fehler('Der Stand der Arbeitskopie ließ sich nicht lesen: ' . $stand['message']);
        }
        if (trim($stand['ausgabe']) !== '') {
            $commit = self::git(array_merge([
                '-c', 'user.name=' . self::gitName(),
                '-c', 'user.email=' . self::gitMail(),
                'commit', '-m', $nachricht,
            ], $zusatz !== '' ? ['-m', $zusatz] : [], ['--'], $pfade), $kopie);
            if (!$commit['ok']) {
                return $fehler('Der Commit scheiterte: ' . $commit['message']);
            }
        }

        $hier = self::commitVon('HEAD');
        if ($hier === '') {
            return $fehler('In der Arbeitskopie gibt es keinen Commit – es gibt nichts hochzuladen.');
        }
        if ($beleg !== null && !self::git(['cat-file', '-e', 'HEAD:' . $beleg], $kopie)['ok']) {
            return $fehler($beleg . ' ist nicht im Commit gelandet. Schließt eine .gitignore im Repository den Ordner aus?');
        }

        $dort = self::fernerStand();
        $hochgeladen = false;
        if ($dort !== $hier) {
            $push = self::git(['push', 'origin', 'HEAD:' . $zweig], $kopie);
            if (!$push['ok']) {
                return $fehler('Das Hochladen scheiterte: ' . $push['message']);
            }
            // Nachsehen statt glauben: steht der Zweig dort jetzt auf
            // genau diesem Commit?
            $dort = self::fernerStand();
            if ($dort !== $hier) {
                return $fehler('git meldete Erfolg, im Repository steht auf ' . $zweig . ' aber '
                    . ($dort === null ? 'kein Stand' : 'Commit ' . substr($dort, 0, 8))
                    . ' statt ' . substr($hier, 0, 8) . '.');
            }
            $hochgeladen = true;
        }

        return [
            'ok'          => true,
            'message'     => '',
            'hochgeladen' => $hochgeladen,
            'wo'          => 'Im Repository' . ($beleg !== null ? ' unter ' . $beleg : '') . ', Zweig „' . $zweig . '“, Commit ' . substr($hier, 0, 8) . '.',
        ];
    }

    /** Der volle Commit einer Referenz in der Arbeitskopie – oder leer. */
    private static function commitVon(string $referenz): string
    {
        $ergebnis = self::git(['rev-parse', '--verify', '--quiet', $referenz . '^{commit}'], self::arbeitsKopie());
        return $ergebnis['ok'] ? trim($ergebnis['ausgabe']) : '';
    }

    /** Auf welchem Commit der Zweig im Repository steht – null, wenn es ihn dort nicht gibt. */
    public static function fernerStand(): ?string
    {
        $ergebnis = self::git(['ls-remote', '--heads', 'origin', 'refs/heads/' . self::zweig()], self::arbeitsKopie());
        if (!$ergebnis['ok'] || trim($ergebnis['ausgabe']) === '') {
            return null;
        }
        return strtok(trim($ergebnis['ausgabe']), "\t") ?: null;
    }

    /**
     * Der Zweig, den das Repository von sich aus zeigt (GitHub: „default
     * branch“) – oder null, wenn es keinen hat oder er nicht zu lesen ist.
     */
    public static function standardZweig(): ?string
    {
        $ergebnis = self::git(['ls-remote', '--symref', 'origin', 'HEAD'], self::arbeitsKopie());
        if (!$ergebnis['ok'] || preg_match('#^ref:\s+refs/heads/(\S+)\s+HEAD#m', $ergebnis['ausgabe'], $treffer) !== 1) {
            return null;
        }
        return $treffer[1];
    }

    /**
     * Ein Hinweis, wenn hier auf einen anderen Zweig geschrieben wird, als
     * das Repository zeigt.
     *
     * Das ist der zweite Weg, auf dem ein Hochladen „nicht ankommt“: auf
     * GitHub steht das Repository auf `master`, MasterAdmin schreibt nach
     * `main` – beides ist da, gesehen wird nur das eine.
     */
    public static function zweigHinweis(): string
    {
        $standard = self::standardZweig();
        if ($standard === null || $standard === self::zweig()) {
            return '';
        }
        return ' Achtung: Das Repository zeigt von sich aus den Zweig „' . $standard . '“, hochgeladen wird nach „'
            . self::zweig() . '“ – auf der Startseite des Repositories ist es deshalb nicht zu sehen. Unter '
            . 'Einstellungen → Inventar-Abgleich den Zweig auf „' . $standard . '“ stellen, wenn dort gesucht wird.';
    }

    // --------------------------------------------------------- Grundgerüst

    /** Was ein Repository für den Abgleich braucht – ein Ordner je Bestandsart. */
    public const GERUEST_ORDNER = [
        'locations'   => 'Gebäude und Gelände mit Etagen, Grundrissen (MasterAdmin: location.json) und Feststromanschlüssen (PowerManagement: strom.json)',
        'verteiler'   => 'Standard-Verteiler aus dem Katalog (typen/) und Verteiler und Zwischenstecker aus dem Inventar mit ihren Shellys (inventar/) – PowerManagement',
    ];

    /** Was beim Anlegen des Gerüsts gemeldet wird – mit der Datei, die es wirklich wurde. */
    private static function geruestMeldung(array $angelegt): string
    {
        $beschreibung = array_values(array_filter($angelegt, static fn (string $p): bool => str_ends_with($p, '.md')));
        return 'Das Repository war leer und ist jetzt passend angelegt: '
            . ($beschreibung[0] ?? 'README.md') . ' mit einer Beschreibung und je ein Ordner für '
            . implode(', ', array_keys(self::GERUEST_ORDNER)) . '.';
    }

    /**
     * Ist das Repository (noch) leer?
     *
     * Leer heißt: keiner der Ordner, die der Abgleich führt. Ein
     * Repository, das beim Anlegen auf GitHub schon eine README oder eine
     * Lizenz bekommen hat, ist für den Abgleich genauso leer wie eines
     * ganz ohne Commit – und soll genauso sein Gerüst bekommen.
     */
    public static function istLeer(): bool
    {
        /*
         * Gefragt wird git, nicht das Dateisystem: die Wunschliste legt
         * ihren Ordner an, bevor sie hochlädt – ein Blick auf die Platte
         * hielte das Repository dann schon für eingerichtet.
         */
        $gefuehrt = self::git(array_merge(['ls-files', '--'], array_keys(self::GERUEST_ORDNER)), self::arbeitsKopie());
        return $gefuehrt['ok'] && trim($gefuehrt['ausgabe']) === '';
    }

    /**
     * Ein leeres Repository passend anlegen.
     *
     * Die Ordner je Bestandsart (mit `.gitkeep`, denn leere Ordner kennt
     * git nicht) und eine README, die sagt, was hier liegt und dass es von
     * MasterAdmin gepflegt wird. Eine vorhandene README bleibt, wie sie
     * ist – dann kommt die Beschreibung in eine eigene Datei.
     *
     * Wer das Repository später im Browser öffnet, soll nicht raten müssen,
     * was die JSON-Dateien sind und ob er sie von Hand ändern darf.
     *
     * @return list<string> angelegte Pfade, relativ zur Arbeitskopie
     */
    public static function grundgeruestAnlegen(): array
    {
        if (!self::istLeer()) {
            return [];
        }
        $kopie = self::arbeitsKopie();
        $angelegt = [];

        foreach (array_keys(self::GERUEST_ORDNER) as $ordner) {
            if (!is_dir($kopie . '/' . $ordner) && !mkdir($kopie . '/' . $ordner, 0775, true) && !is_dir($kopie . '/' . $ordner)) {
                continue;
            }
            if (!is_file($kopie . '/' . $ordner . '/.gitkeep')) {
                file_put_contents($kopie . '/' . $ordner . '/.gitkeep', '');
            }
            $angelegt[] = $ordner . '/.gitkeep';
        }

        $readme = is_file($kopie . '/README.md') ? 'POWERMANAGEMENT.md' : 'README.md';
        file_put_contents($kopie . '/' . $readme, self::grundgeruestText());
        $angelegt[] = $readme;

        return $angelegt;
    }

    /** Die Beschreibung für ein neu angelegtes Repository. */
    public static function grundgeruestText(): string
    {
        $name = (string) Config::get('app.name', 'PowerManagement');
        $zeilen = [
            '# Locations für ' . $name,
            '',
            'Dieses Repository gleicht Locations zwischen MasterAdmin- und PowerManagement-',
            'Installationen ab, dazu die Verteiler von PowerManagement. Gepflegt wird es über',
            'die Oberflächen (**Location-Abgleich**, **Verteiler-Abgleich**), nicht von Hand.',
            '',
            '| Ordner | Inhalt |',
            '|--------|--------|',
        ];
        foreach (self::GERUEST_ORDNER as $ordner => $inhalt) {
            $zeilen[] = '| `' . $ordner . '/` | ' . $inhalt . ' |';
        }
        array_push(
            $zeilen,
            '',
            'Je Location gibt es einen Unterordner mit ihrem Schlüssel: `location.json`',
            '(Etagen, Grundrisse unter `plaene/`, Dosen, Racks) und `strom.json`',
            '(die Feststromanschlüsse der Location – Hausanschluss, Energiesäulen,',
            'Wandverteiler – mit Anschlüssen und Sicherungen, dazu fest verlegte Kabel;',
            'mobile Verteiler und Kabel gehören zur Veranstaltung und stehen nicht hier).',
            '',
            'Unter `verteiler/` liegt je Verteiler eine Datei: `typen/<schlüssel>.json`',
            '(Standard-Verteiler mit Anschlüssen und Sicherungen) und',
            '`inventar/<schlüssel>.json` (ein Verteiler oder Zwischenstecker aus dem Lager',
            'mit seinen Shellys). Wo ein Stück gerade eingesetzt ist, steht nicht hier.',
            '',
            '**Jeder Stand ist ein Commit.** Wer hier umschreibt (rebase, force-push),',
            'nimmt allen Installationen die Historie.',
            ''
        );
        return implode("\n", $zeilen);
    }

    private static function bestandPfad(string $schluessel, string $datei): ?string
    {
        // Der Schlüssel kommt aus der Oberfläche – er darf kein Pfad sein.
        $sauber = LocationBundle::schluessel($schluessel, '');
        if ($sauber === '' || $sauber !== $schluessel || !in_array($datei, ['location.json', 'strom.json'], true)) {
            return null;
        }
        return self::arbeitsKopie() . '/locations/' . $sauber . '/' . $datei;
    }

    private static function gitName(): string
    {
        return (string) Config::get('app.name', 'PowerManagement');
    }

    private static function gitMail(): string
    {
        return 'powermanagement@' . (string) (parse_url(self::repoUrl(), PHP_URL_HOST) ?: 'local');
    }

    /**
     * Wo git liegt.
     *
     * Gesucht wird im PATH **und** an den üblichen Orten. Der PATH allein
     * genügt nicht: php-fpm räumt die Umgebung in der Vorgabe leer
     * (`clear_env = yes`), und ein Pool, in dem jemand ein `env[PATH]`
     * gesetzt hat, kann jeden beliebigen Inhalt haben. Dann hieße es „auf
     * dem Server fehlt git", obwohl es unter /usr/bin liegt.
     */
    private static function gitPfad(): ?string
    {
        /*
         * Nicht nachsehen, sondern finden lassen: im PHP-FPM-Pool gilt
         * open_basedir, und dort lieferte is_file('/usr/bin/git') false.
         * Die Oberfläche meldete „auf dem Server fehlt das Paket git“,
         * während der Abgleich aus den Diensten heraus lief.
         */
        return Programm::finden('git', ['--version']);
    }

    /**
     * Das Skript, das Git nach dem Kennwort fragt.
     *
     * Git ruft es auf, wenn es Zugangsdaten braucht; es gibt schlicht die
     * Umgebungsvariable aus. So steht das Token weder in der Adresse noch
     * auf der Kommandozeile – nur in der Umgebung des Prozesses, und die
     * gehört ihm allein.
     */
    private static function askpass(): string
    {
        $pfad = dirname(self::arbeitsKopie()) . '/askpass.sh';
        if (!is_dir(dirname($pfad))) {
            mkdir(dirname($pfad), 0775, true);
        }
        if (!is_file($pfad)) {
            file_put_contents($pfad, "#!/bin/sh\nprintf '%s' \"\$" . self::TOKEN_VARIABLE . "\"\n");
        }
        @chmod($pfad, 0700);
        return $pfad;
    }

    /**
     * Git aufrufen – ohne Shell.
     *
     * @param list<string> $argumente
     * @return array{ok:bool,ausgabe:string,message:string}
     */
    private static function git(array $argumente, string $verzeichnis): array
    {
        $git = self::gitPfad();
        if ($git === null) {
            return ['ok' => false, 'ausgabe' => '', 'message' => 'git ist nicht vorhanden.'];
        }

        $umgebung = [
            'PATH'                 => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME'                 => dirname(self::arbeitsKopie()),
            'GIT_TERMINAL_PROMPT'  => '0',
            // Nur diese Wege – kein ext::, kein Umweg über andere Helfer.
            'GIT_ALLOW_PROTOCOL'   => self::PROTOKOLLE,
            'GIT_ASKPASS'          => self::askpass(),
            self::TOKEN_VARIABLE   => self::token(),
            // Ohne das fragt Git bei einem abgelaufenen Token endlos nach.
            'GIT_CONFIG_NOSYSTEM'  => '1',
        ];

        /*
         * Holen, Hochladen und Nachsehen dort warten auf das Repository – bis
         * zu ZEITGRENZE Sekunden. Solange stünden sonst alle anderen Anfragen
         * desselben Browsers an der Sitzung an: andere Reiter, Live-Abfragen,
         * das Speichern eines Formulars. Also ohne Sitzungssperre wie
         * PartnerService::anfrage(); die Aufrufer schreiben ihre Meldungen
         * erst danach, und zwei Abgleiche hält gesperrt() auseinander.
         */
        $lauf = static fn (): array => self::starten($git, $argumente, $verzeichnis, $umgebung);
        return in_array($argumente[0] ?? '', self::UEBERS_NETZ, true) ? Session::ohneSperre($lauf) : $lauf();
    }

    /**
     * git starten und auf sein Ende warten – höchstens ZEITGRENZE Sekunden.
     *
     * @param list<string> $argumente
     * @param array<string,string> $umgebung
     * @return array{ok:bool,ausgabe:string,message:string}
     */
    private static function starten(string $git, array $argumente, string $verzeichnis, array $umgebung): array
    {
        $prozess = @proc_open(
            array_merge([$git], $argumente),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $rohre,
            is_dir($verzeichnis) ? $verzeichnis : null,
            $umgebung
        );
        if (!is_resource($prozess)) {
            return ['ok' => false, 'ausgabe' => '', 'message' => 'git ließ sich nicht starten.'];
        }

        stream_set_blocking($rohre[1], false);
        stream_set_blocking($rohre[2], false);
        $ausgabe = '';
        $fehler  = '';
        $ende    = microtime(true) + self::ZEITGRENZE;
        $rc      = -1;

        while (true) {
            $ausgabe .= (string) stream_get_contents($rohre[1]);
            $fehler  .= (string) stream_get_contents($rohre[2]);
            $stand = proc_get_status($prozess);
            if (!$stand['running']) {
                // Genau hier steht der Rückgabewert, und nur hier: mit
                // diesem Aufruf ist das Kind abgeholt. Ein proc_close()
                // danach liefert bis PHP 8.2 nur noch -1 – erst ab 8.3
                // merkt sich PHP den Wert. Der Unterschied fällt sonst
                // nicht auf der Entwicklungsmaschine auf, sondern erst
                // im Prüflauf mit der älteren Fassung.
                $rc = (int) $stand['exitcode'];
                break;
            }
            if (microtime(true) > $ende) {
                proc_terminate($prozess, 9);
                fclose($rohre[1]);
                fclose($rohre[2]);
                proc_close($prozess);
                return ['ok' => false, 'ausgabe' => $ausgabe, 'message' => 'git hat zu lange gebraucht.'];
            }
            usleep(50_000);
        }

        $ausgabe .= (string) stream_get_contents($rohre[1]);
        $fehler  .= (string) stream_get_contents($rohre[2]);
        fclose($rohre[1]);
        fclose($rohre[2]);
        proc_close($prozess);

        if ($rc !== 0) {
            Logger::info('Inventar-Abgleich: git ' . ($argumente[0] ?? '') . ' rc=' . $rc . ' ' . self::ohneToken(trim($fehler)));
            return [
                'ok'      => false,
                'ausgabe' => $ausgabe,
                // Sollte das Token je in einer Meldung auftauchen, geht es
                // hier nicht weiter.
                'message' => self::ohneToken(trim($fehler) !== '' ? trim($fehler) : 'Rückgabewert ' . $rc),
            ];
        }

        return ['ok' => true, 'ausgabe' => $ausgabe, 'message' => ''];
    }

    /** Ein Token, das doch einmal in einer Meldung landet, wird unkenntlich. */
    private static function ohneToken(string $text): string
    {
        $token = self::token();
        return $token !== '' ? str_replace($token, '***', $text) : $text;
    }
}
