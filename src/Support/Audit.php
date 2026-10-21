<?php
declare(strict_types=1);

namespace App\Support;

use App\Auth\Auth;

/** Revisionssicheres Protokoll aller administrativen Aktionen. */
final class Audit
{
    /** Wer handelt, wenn niemand angemeldet ist – etwa „api:<Token>“ oder „cli“. */
    private static ?string $akteur = null;

    /**
     * Den Handelnden ohne Anmeldung setzen (null: wieder „system“).
     *
     * Die Schnittstelle meldet niemanden an; ihre Einträge sollen trotzdem
     * zeigen, welches Token es war – nie das Token selbst, nur seinen Namen.
     * Die Kommandozeile setzt „cli“ (etwa beim Katalog-Import).
     */
    public static function akteur(?string $name): void
    {
        $name = $name === null ? '' : trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        self::$akteur = $name === '' ? null : mb_substr($name, 0, 64);
    }

    public static function log(string $action, string $targetType = '', string $targetId = '', array $details = []): void
    {
        $user = Auth::user();
        Database::insert('audit_log', [
            'user_id'     => $user['id'] ?? null,
            'username'    => mb_substr((string) ($user['username'] ?? self::$akteur ?? 'system'), 0, 64),
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => (string) $targetId,
            'details'     => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip'          => php_sapi_name() === 'cli' ? 'cli' : (new Request())->ip(),
            'created_at'  => gmdate('c'),
        ]);
    }

    public static function recent(int $limit = 50, array $filters = []): array
    {
        $sql    = 'SELECT * FROM audit_log WHERE 1=1';
        $params = [];
        if (!empty($filters['username'])) {
            $sql .= ' AND username = ?';
            $params[] = $filters['username'];
        }
        if (!empty($filters['action'])) {
            $sql .= ' AND action LIKE ?';
            $params[] = '%' . $filters['action'] . '%';
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min($limit, 500));
        return Database::select($sql, $params);
    }
}
