<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LoanGuarantor;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Account closure per blueprint §8.3 — admin-only, no platform-enforced
 * settlement logic. The summary endpoint is purely informational and never
 * blocks the actual close action.
 */
class MemberAccountController extends Controller
{
    /** GET /members/{uid}/closure-summary — informational only, does not close anything. */
    public function closureSummary(Request $request, User $member)
    {
        abort_unless($request->user()->canActOnSociety($member->society_id), 404);

        $outstandingLoanMinor = $member->loans()
            ->where('status', 'active')
            ->get()
            ->sum(fn ($loan) => $loan->remainingMinor('principal') + $loan->remainingMinor('interest') + $loan->remainingMinor('sales'));

        $activeGuarantorFor = LoanGuarantor::where('guarantor_id', $member->id)
            ->where('status', 'accepted')
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->with('loan.member:id,full_name,member_uid')
            ->get();

        return [
            'member_uid' => $member->member_uid,
            'savings_balance_minor' => $member->account('savings')->balance_minor,
            'shares_balance_minor' => $member->account('shares')->balance_minor,
            'outstanding_loan_minor' => $outstandingLoanMinor,
            'active_guarantor_for' => $activeGuarantorFor->map(fn ($g) => [
                'loan_id' => $g->loan_id,
                'member' => $g->loan->member->full_name,
                'member_uid' => $g->loan->member->member_uid,
            ]),
            'note' => 'This summary is informational only. The platform does not block or auto-settle account closure — that determination is entirely the admin\'s.',
        ];
    }

    /** POST /members/{uid}/close — no checks beyond permission; admin's call entirely. */
    public function close(Request $request, User $member)
    {
        abort_unless($request->user()->canActOnSociety($member->society_id), 404);

        $before = ['status' => $member->status];
        $member->update(['status' => 'closed']);

        // Revoke any active API tokens so closure takes effect immediately,
        // not just on their next login attempt.
        $member->tokens()->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            action: 'member.close_account',
            entityType: 'user',
            entityId: $member->id,
            before: $before,
            after: ['status' => 'closed'],
        );

        return response()->json(['message' => 'Account closed.']);
    }
}
