<?php
declare(strict_types=1);

namespace App\Auth\Sso;

/**
 * Ein fehlgeschlagener Schritt der Anmeldung über SSO.
 *
 * $pruefung ist der kurze Code fürs Audit und die Fehlersuche (`iss`,
 * `discovery`, `token` …), getMessage() der Text für den Menschen – beides
 * ohne Geheimnis, Code oder Token. $anfrage: nach dem Rollback eine
 * Anmeldeanfrage merken. $nameDort: der Name beim Anmeldedienst für den
 * Akteur im Audit, nur aus geprüften Claims.
 */
final class SsoFehler extends \RuntimeException
{
    public function __construct(
        public readonly string $pruefung,
        string $text,
        public readonly bool $anfrage = false,
        public readonly string $nameDort = '',
    ) {
        parent::__construct($text);
    }
}
