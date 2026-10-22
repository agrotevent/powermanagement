<?php
declare(strict_types=1);

/**
 * Eine eigene Instanz für ein Prüfskript – ohne Bootstrap.
 *
 * Prüfskripte, die Einstellungen ändern (etwa sso.*), laufen nicht in der
 * Datenbank der Arbeitskopie, sondern in einer eigenen: Temp-Ordner, eigene
 * SQLite-Datenbank, eigenes storage/update, eigene config.php (aus
 * config/config.php bzw. der Vorlage, mit frischem app.key, ohne Demo-Modus
 * und mit den Überschreibungen des Skripts). Gesichert oder zurückgeschrieben wird nichts – die Instanz wird
 * am Ende weggeräumt. Gewählt wird sie über POWERMANAGEMENT_CONFIG (nur auf
 * der Kommandozeile und im eingebauten Server, siehe src/bootstrap.php).
 *
 * Erster Aufruf eines Skripts (vor pruefung.php, das den Bootstrap lädt):
 *
 *   require __DIR__ . '/hilfen/instanz.php';
 *   inEigenerInstanz(__FILE__, ['app' => ['url' => 'https://pm.example.test']]);
 *   require __DIR__ . '/hilfen/pruefung.php';
 */

/**
 * Eigene Instanz anlegen: Temp-Ordner, SQLite, config.php, `bin/power migrate`.
 * Wird beim Ende des Skripts weggeräumt.
 *
 * @param array<string,mixed> $ueberschreiben wird in die Konfiguration gemischt (array_replace_recursive)
 * @return array{dir:string, config:string, env:array<string,string>}
 */
function eigeneInstanz(string $kennung, array $ueberschreiben = []): array
{
    $wurzel = dirname(__DIR__, 2);
    if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $kennung) !== 1) {
        throw new InvalidArgumentException('eigeneInstanz: Kennung nur aus Buchstaben, Ziffern und ._-');
    }
    $dir = sys_get_temp_dir() . '/pm-instanz-' . $kennung . '-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0700, true)) {
        throw new RuntimeException('eigeneInstanz: Ordner ' . $dir . ' ließ sich nicht anlegen.');
    }
    register_shutdown_function(static function () use ($dir): void {
        instanzWegraeumen($dir);
    });
    $vorlage = is_file($wurzel . '/config/config.php') ? $wurzel . '/config/config.php' : $wurzel . '/config/config.example.php';
    // Auch storage/update der Instanz (Schreibsperre, Anmeldung der Anfragen):
    // ein Prüfskript, das ein Update spielt, legt nie etwas in die Arbeitskopie.
    $eigene = [
        'app'      => ['key' => 'base64:' . base64_encode(random_bytes(32)), 'demo' => false],
        'database' => ['driver' => 'sqlite', 'path' => $dir . '/datenbank.sqlite'],
        'update'   => ['verzeichnis' => $dir . '/update'],
    ];
    $config = $dir . '/config.php';
    file_put_contents($config, "<?php\n// Eigene Instanz eines Prüfskripts – wird am Ende gelöscht.\n"
        . '$c = require ' . var_export($vorlage, true) . ";\n"
        . 'return array_replace_recursive($c, ' . var_export(array_replace_recursive($eigene, $ueberschreiben), true) . ");\n");
    chmod($config, 0600);
    $env = ['POWERMANAGEMENT_CONFIG' => $config];
    $p = proc_open([PHP_BINARY, $wurzel . '/bin/power', 'migrate'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . '/migrate.txt', 'w'],
        2 => ['file', $dir . '/migrate.txt', 'a']], $rohre, $wurzel, $env + getenv());
    $rc = is_resource($p) ? proc_close($p) : -1;
    if ($rc !== 0) {
        throw new RuntimeException('eigeneInstanz: bin/power migrate scheiterte: ' . trim((string) @file_get_contents($dir . '/migrate.txt')));
    }
    return ['dir' => $dir, 'config' => $config, 'env' => $env];
}

/**
 * Das Skript $skript in einer eigenen Instanz noch einmal starten (Umgebung:
 * POWERMANAGEMENT_CONFIG, PM_INSTANZ=1), Ausgabe und Rückgabewert
 * durchreichen, aufräumen und mit dessen Rückgabewert enden. Läuft das Skript
 * schon in seiner Instanz (PM_INSTANZ gesetzt), kehrt die Funktion einfach
 * zurück.
 *
 * @param array<string,mixed> $ueberschreiben
 */
function inEigenerInstanz(string $skript, array $ueberschreiben = []): void
{
    if (getenv('PM_INSTANZ') === '1') {
        return;
    }
    $name = pathinfo($skript, PATHINFO_FILENAME);
    try {
        $instanz = eigeneInstanz((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), $ueberschreiben);
    } catch (Throwable $e) {
        fwrite(STDERR, '  ✗ ' . $e->getMessage() . "\n");
        fwrite(STDOUT, $name . ": eigene Instanz ließ sich nicht anlegen\n");
        exit(1);
    }
    $argumente = array_slice($_SERVER['argv'] ?? [], 1);
    $p = proc_open(array_merge([PHP_BINARY, $skript], $argumente), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $rohre,
        dirname(__DIR__, 2), $instanz['env'] + ['PM_INSTANZ' => '1', 'PM_INSTANZ_ORDNER' => $instanz['dir']] + getenv());
    $rc = is_resource($p) ? proc_close($p) : 1;
    exit($rc);
}

/** Den Ordner einer eigenen Instanz löschen – nur ihn, nur unter dem Temp-Ordner. */
function instanzWegraeumen(string $dir): void
{
    $temp = rtrim(sys_get_temp_dir(), '/') . '/pm-instanz-';
    if (!str_starts_with($dir, $temp) || str_contains(substr($dir, strlen($temp)), '/') || !is_dir($dir) || is_link($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $eintrag) {
        $eintrag->isDir() && !$eintrag->isLink() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
    }
    @rmdir($dir);
}
