<?php
declare(strict_types=1);

namespace App\Support;

use App\Auth\Auth;

/** Revisionssicheres Protokoll aller administrativen Aktionen. */
final class Audit
{
    /** Wer handelt, wenn niemand angemeldet ist (Vorgabe „system“). */
    private static ?string $akteur = null;

    /**
     * Für die Kommandozeile: unter welchem Namen Einträge ohne angemeldeten
     * Benutzer stehen – etwa „cli“ beim Katalog-Import. null = „system“.
     */
    public static function akteur(?string $name): void
    {
        self::$akteur = $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 64) : null;
    }

    public static function log(string $action, string $targetType = '', string $targetId = '', array $details = []): void
    {
        $user = Auth::user();
        Database::insert('audit_log', [
            'user_id'     => $user['id'] ?? null,
            'username'    => $user['username'] ?? (self::$akteur ?? 'system'),
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
