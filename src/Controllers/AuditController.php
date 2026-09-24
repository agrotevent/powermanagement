<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Audit;
use App\Support\Request;

final class AuditController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('audit/index', [
            'eintraege' => Audit::recent(300, ['username' => $request->str('benutzer'), 'action' => $request->str('aktion')]),
            'filter'    => ['benutzer' => $request->str('benutzer'), 'aktion' => $request->str('aktion')],
        ]);
    }
}
