<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

/** PDO-Singleton für SQLite und MySQL inkl. Migrationsrunner. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = (string) Config::get('database.driver', 'sqlite');
        if ($driver === 'sqlite') {
            $path = (string) Config::get('database.path');
            $dir  = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                Config::get('database.host'),
                (int) Config::get('database.port', 3306),
                Config::get('database.database'),
                Config::get('database.charset', 'utf8mb4')
            );
            $pdo = new PDO($dsn, (string) Config::get('database.username'), (string) Config::get('database.password'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$pdo = $pdo;
    }

    /**
     * Die Verbindung schließen – für Befehle, die lange warten, während ein
     * Update die Datenbankdatei austauschen kann. Eine offene Verbindung zur
     * alten Datei löschte beim Schließen womöglich die -wal der neuen.
     */
    public static function trennen(): void
    {
        self::$pdo = null;
    }

    public static function driver(): string
    {
        return (string) Config::get('database.driver', 'sqlite');
    }

    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function first(string $sql, array $params = []): ?array
    {
        $rows = self::select($sql, $params);
        return $rows[0] ?? null;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        self::execute($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($data)));
        $data['__id'] = $id;
        self::execute("UPDATE {$table} SET {$sets} WHERE id = :__id", $data);
    }

    public static function delete(string $table, int $id): void
    {
        self::execute("DELETE FROM {$table} WHERE id = ?", [$id]);
    }

    /** Tiefe verschachtelter transaktion()-Aufrufe. */
    private static int $tiefe = 0;

    /**
     * $arbeit in einer Transaktion ausführen: alles oder nichts.
     *
     * Wirft $arbeit, wird zurückgerollt und die Ausnahme weitergereicht.
     * Läuft schon eine Transaktion (auch eine von BerichtService oder einer
     * anderen Stelle), gehört $arbeit einfach dazu. SQLite beginnt mit
     * BEGIN IMMEDIATE: Die Schreibsperre kommt sofort, nicht erst beim
     * ersten Schreiben – zwei gleichzeitige Abläufe, die erst lesen und dann
     * schreiben (etwa zwei Rückrufe derselben neuen SSO-Identität), stoßen
     * so nicht erst beim COMMIT zusammen.
     *
     * @template T
     * @param callable():T $arbeit
     * @return T
     */
    public static function transaktion(callable $arbeit): mixed
    {
        $pdo = self::pdo();
        if (self::$tiefe > 0 || $pdo->inTransaction()) {
            return $arbeit();
        }
        $sqlite = self::driver() === 'sqlite';
        $sqlite ? $pdo->exec('BEGIN IMMEDIATE') : $pdo->beginTransaction();
        self::$tiefe++;
        try {
            $ergebnis = $arbeit();
            $sqlite ? $pdo->exec('COMMIT') : $pdo->commit();
            return $ergebnis;
        } catch (\Throwable $e) {
            try {
                $sqlite ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
            } catch (\Throwable) {
                // schon zurückgerollt (etwa nach SQLITE_BUSY beim COMMIT)
            }
            throw $e;
        } finally {
            self::$tiefe--;
        }
    }

    /** Führt alle noch nicht angewandten Migrationen aus database/migrations aus. */
    public static function migrate(string $dir): array
    {
        $pdo = self::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at VARCHAR(32))');
        $done = array_column(self::select('SELECT name FROM migrations'), 'name');

        $applied = [];
        $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $sql = self::translate((string) file_get_contents($file));
            foreach (array_filter(array_map('trim', explode(';--split', $sql))) as $stmt) {
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }
            self::execute('INSERT INTO migrations (name, applied_at) VALUES (?, ?)', [$name, gmdate('c')]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Platzhalter der portablen Migrationen auf den aktiven Treiber abbilden. */
    private static function translate(string $sql): string
    {
        $mysql = self::driver() === 'mysql';
        $map = $mysql
            ? ['{{PK}}' => 'INT AUTO_INCREMENT PRIMARY KEY', '{{TEXT}}' => 'TEXT', '{{JSON}}' => 'LONGTEXT', '{{TS}}' => 'VARCHAR(32)', '{{BOOL}}' => 'TINYINT(1)', '{{BIGINT}}' => 'BIGINT', '{{ENGINE}}' => ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4']
            : ['{{PK}}' => 'INTEGER PRIMARY KEY AUTOINCREMENT', '{{TEXT}}' => 'TEXT', '{{JSON}}' => 'TEXT', '{{TS}}' => 'TEXT', '{{BOOL}}' => 'INTEGER', '{{BIGINT}}' => 'INTEGER', '{{ENGINE}}' => ''];

        return strtr($sql, $map);
    }
}
