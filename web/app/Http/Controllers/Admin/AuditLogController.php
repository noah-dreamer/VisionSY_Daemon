<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $query = AuditLog::query()->latest('id');

        if ($action = trim((string) $request->query('action', ''))) {
            $query->where('action', 'like', "%{$action}%");
        }

        if ($actor = trim((string) $request->query('actor', ''))) {
            $query->where('actor_email', 'like', "%{$actor}%");
        }

        return view('admin.audit-logs.index', [
            'logs' => $query->paginate(30)->withQueryString(),
            'filters' => $request->only(['action', 'actor']),
        ]);
    }
}
