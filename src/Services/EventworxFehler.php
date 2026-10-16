<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Was bei Eventworx schiefging – als deutsche Meldung, die so in die
 * Oberfläche darf.
 *
 * Die Meldung trägt nie den Rumpf der Antwort, kein `Location`, kein Token
 * und keinen Suchtext; dazu `status` (HTTP, 0 = keine Antwort) und
 * `fehlercode` (nur ein Bezeichner wie ACCESS_DENIED oder ein eigener wie
 * NO_JSON). Der Name ist nicht `code`: den hat \Exception schon als Zahl.
 */
final class EventworxFehler extends \RuntimeException
{
    public function __construct(string $meldung, public readonly int $status = 0, public readonly string $fehlercode = '')
    {
        parent::__construct($meldung);
    }
}
