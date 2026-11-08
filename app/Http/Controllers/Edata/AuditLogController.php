<?php

namespace App\Http\Controllers\Edata;

use App\Models\EdataAuditLog;
use App\Support\Edata\Access;
use Illuminate\Http\Request;

/**
 * Read only by design: this controller has no method that writes.
 */
class AuditLogController extends BaseController
{
    public function index(Request $request)
    {
        $me = $this->me();
        Access::need($me, 'view audit log');

        $query = EdataAuditLog::where('company_id', $this->companyId());

        if ($request->filled('user')) {
            $query->where('user_id', (int) $request->query('user'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }
        if ($request->filled('entity')) {
            $query->where('entity_type', $request->query('entity'));
        }
        if ($request->filled('q')) {
            $query->where('entity_label', 'like', '%' . $request->query('q') . '%');
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from') . ' 00:00:00');
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to') . ' 23:59:59');
        }

        return view('edata.audit.index', [
            'logs' => $query->orderByDesc('id')->paginate(30)->withQueryString(),
            'users' => Access::staff($me)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
