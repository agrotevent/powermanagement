<?php
declare(strict_types=1);

namespace App\Support;

/** Symmetrische Verschlüsselung (libsodium) für Zugangsdaten (Modbus, Cloud, Repository). */
final class Crypto
{
    /** Taugt app.key? null = ja, sonst der Grund. */
    public static function schluesselFehler(): ?string
    {
        $key = trim((string) Config::get('app.key', ''));
        if ($key === '' || $key === 'base64:' || str_contains($key, 'CHANGEME')) {
            return 'In config/config.php fehlt ein eigener app.key (dort steht der Platzhalter). Einen neuen erzeugt: '
                . 'php -r \'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;\'';
        }
        return null;
    }

    private static function key(): string
    {
        // Ohne eigenen Schlüssel wäre er öffentlich bekannt (der Platzhalter
        // aus der Vorlage bzw. sha256('pm:')) – dann lieber gar nicht.
        $fehler = self::schluesselFehler();
        if ($fehler !== null) {
            throw new \RuntimeException($fehler);
        }
        $key = (string) Config::get('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        if (strlen($key) < SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            // Kurze Schlüssel deterministisch auf 32 Byte strecken.
            $key = hash('sha256', 'pm:' . $key, true);
        }
        return substr($key, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Ein eigener Schlüssel je Zweck, abgeleitet aus app.key (HKDF) – etwa
     * für das Siegel der Übergabeberichte. So taugt ein Siegel-Schlüssel
     * nie zum Entschlüsseln der Zugangsdaten und umgekehrt.
     */
    public static function ableiten(string $zweck): string
    {
        return hash_hkdf('sha256', self::key(), 32, 'pm:' . $zweck);
    }

    public static function encrypt(?string $plain): string
    {
        if ($plain === null || $plain === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $cipher): string
    {
        if ($cipher === null || $cipher === '') {
            return '';
        }
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($box, $nonce, self::key());
        return $plain === false ? '' : $plain;
    }

    public static function randomPassword(int $length = 16): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!%&*+-';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
