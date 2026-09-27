<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\TrialBalanceService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private TrialBalanceService $trialBalance) {}

    /** GET /reports/trial-balance — gated by 'reports.view' permission. */
    public function trialBalance(Request $request)
    {
        $society = $request->user()->society;
        $asOf = $request->query('as_of') ? \Carbon\Carbon::parse($request->query('as_of')) : null;

        return $this->trialBalance->generate($society, $asOf);
    }

    /**
     * GET /reports/audit-log — what the "tracking and logging for whoever
     * edits" requirement backs onto. Auditors get this via 'reports.view';
     * it's filterable so an auditor reviewing a specific member or a
     * specific date range doesn't have to page through everything.
     */
    public function auditLog(Request $request)
    {
        $query = AuditLog::query()->with('actor:id,full_name,email')->orderByDesc('created_at');

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->query('entity_type'));
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->query('entity_id'));
        }
        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->query('actor_id'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to'));
        }

        return $query->paginate(50);
    }
}
