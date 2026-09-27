<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Society-scoped member listing — gap identified while building the
 * frontend Members screen. MemberAccountController handles closure,
 * LedgerController::forMember handles one member's passbook, but nothing
 * previously returned the list itself.
 */
class MemberController extends Controller
{
    public function index(Request $request)
    {
        $societyId = $request->attributes->get('society_id');

        $query = User::where('society_id', $societyId)
            ->where('role', 'member')
            ->with('accounts');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'ilike', "%{$search}%")
                  ->orWhere('member_uid', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $members = $query->orderBy('full_name')->paginate(25);

        // Flatten each member's accounts into a quick balance summary —
        // the frontend list doesn't need the full account rows, just the
        // headline numbers per fund type.
        $members->getCollection()->transform(function (User $member) {
            $balances = $member->accounts->pluck('balance_minor', 'account_type');
            return [
                'id' => $member->id,
                'member_uid' => $member->member_uid,
                'full_name' => $member->full_name,
                'email' => $member->email,
                'status' => $member->status,
                'savings_balance_minor' => $balances->get('savings', 0),
                'shares_balance_minor' => $balances->get('shares', 0),
                'loan_balance_minor' => $balances->get('loan_principal', 0) + $balances->get('loan_interest', 0),
            ];
        });

        return $members;
    }
}
