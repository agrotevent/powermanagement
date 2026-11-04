<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Geraetepruefung\GeraeteImport;
use App\Geraetepruefung\Prueflinge;
use App\Geraetepruefung\Pruefungen;
use App\Support\Database;
use App\Support\Request;

/**
 * Übersicht der Geräteprüfung (DGUV V3): fällige und überfällige Prüflinge,
 * offene Prüfungen (noch ohne gültigen Prüfbericht), die letzten Importe
 * und die Wege zu Import, Export und Prüfungen – je nach Recht.
 *
 * Ansehen mit „geraetepruefung.view“ (prüft die Route). Die Seite liest
 * nur; Importe zeigt sie nur mit ihren Zahlen, nie mit Dateinamen oder
 * Inhalten.
 */
final class GeraetepruefungController extends Controller
{
    /** Wie viele fällige Prüflinge und offene Prüfungen die Übersicht zeigt (der Rest über die Listen). */
    private const ZEILEN = 15;

    public function index(Request $request): void
    {
        $this->view('geraetepruefung/index', [
            'zahlen'        => Prueflinge::zahlen(),
            'faellig'       => Prueflinge::liste(['faellig' => 'faellig', 'limit' => self::ZEILEN]),
            'offen'         => Pruefungen::liste(['offen' => true, 'limit' => self::ZEILEN]),
            'offenAnzahl'   => self::offeneAnzahl(),
            'importe'       => GeraeteImport::letzte(5),
            'pflegen'       => Auth::can('geraetepruefung.manage'),
            'unterschreiben' => Auth::can('geraetepruefung.unterschreiben'),
            'leer'          => Prueflinge::anzahl(['betrieb' => 'alle']) === 0,
        ]);
    }

    /** Wie viele Prüfungen noch keinen gültigen Prüfbericht haben („offen“). */
    private static function offeneAnzahl(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM gp_pruefungen p
            WHERE NOT EXISTS (SELECT 1 FROM gp_berichte b WHERE b.pruefung_id = p.id AND b.status = 'gueltig')");
    }
}
