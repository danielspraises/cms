<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * GET /members/{uid}/ledger
     * Replaces render_member_passbook_ledger()'s data-fetching half. The
     * batching/grouping-by-batch_id display logic moves to the frontend;
     * this returns flat, paginated entries with their lines.
     */
    public function forMember(Request $request, string $uid)
    {
        $member = User::where('member_uid', $uid)
            ->where('society_id', $request->attributes->get('society_id'))
            ->firstOrFail();

        $entries = LedgerEntry::query()
            ->whereHas('lines.account', fn ($q) => $q->where('user_id', $member->id))
            ->with(['lines.account'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(50);

        return $entries;
    }

    /**
     * PATCH /ledger/entries/{id}
     * Replaces the un-audited, nonce-less single-row POST handler that used
     * to live inline in render_member_passbook_ledger(). Edits now go through
     * a reversal + new entry (never mutate historical lines in place) and
     * every edit requires a reason, written to audit_log.
     */
    public function amend(Request $request, LedgerEntry $entry)
    {
        abort_unless($request->user()->canActOnSociety($entry->society_id), 404);

        $validated = $request->validate([
            'reason' => 'required|string|min:5',
            'lines' => 'required|array|min:1',
            'lines.*.user_id' => 'required|integer|exists:users,id',
            'lines.*.account_type' => 'required|string',
            'lines.*.direction' => 'required|in:debit,credit',
            'lines.*.amount_minor' => 'required|integer|min:1',
        ]);

        $before = $entry->load('lines')->toArray();

        $reversal = $this->ledger->reverseEntry($entry, $request->user()->id, $validated['reason']);

        $replacement = $this->ledger->postEntry(
            societyId: $entry->society_id,
            entryDate: $entry->entry_date,
            description: $entry->description . ' (amended)',
            source: 'manual',
            lines: $validated['lines'],
            createdBy: $request->user()->id,
            batchId: $entry->batch_id,
            loanId: $entry->loan_id,
        );

        AuditLog::record(
            actorId: $request->user()->id,
            action: 'ledger_entry.amend',
            entityType: 'ledger_entry',
            entityId: $entry->id,
            before: $before,
            after: $replacement->load('lines')->toArray(),
            reason: $validated['reason'],
        );

        return response()->json([
            'reversal' => $reversal,
            'replacement' => $replacement,
        ]);
    }
}
