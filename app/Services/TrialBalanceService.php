<?php

namespace App\Services;

use App\Models\Society;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Produces a trial balance for a society: total debits, total credits, and
 * net balance per account type, as of a given date. This is the report a
 * cooperative/microfinance auditor expects to see, and it's the fastest way
 * to catch a data-integrity problem — if debits and credits across the whole
 * society don't move together the way they should, something upstream
 * (a bad migration row, a bypassed LedgerService call) is wrong.
 *
 * Caveat carried over from the redesign doc §4: this system uses a
 * single-sided-per-member ledger, not full double-entry with control
 * accounts. That means this trial balance will NOT sum to zero across all
 * account types the way a textbook trial balance does — it's a per-fund-type
 * summary, not a full accounting equation check. If you want the full
 * self-balancing guarantee, we implement the control-account pair
 * (cash_in/cash_out) noted in the redesign doc; flag it if you want that
 * built next.
 */
class TrialBalanceService
{
    public function generate(Society $society, ?Carbon $asOfDate = null): array
    {
        $asOfDate ??= now();

        $rows = DB::table('ledger_lines')
            ->join('ledger_entries', 'ledger_lines.entry_id', '=', 'ledger_entries.id')
            ->join('accounts', 'ledger_lines.account_id', '=', 'accounts.id')
            ->where('ledger_entries.society_id', $society->id)
            ->where('ledger_entries.entry_date', '<=', $asOfDate)
            ->select('accounts.account_type')
            ->selectRaw("SUM(CASE WHEN ledger_lines.direction = 'debit' THEN amount_minor ELSE 0 END) as total_debit_minor")
            ->selectRaw("SUM(CASE WHEN ledger_lines.direction = 'credit' THEN amount_minor ELSE 0 END) as total_credit_minor")
            ->groupBy('accounts.account_type')
            ->get();

        $lines = $rows->map(fn ($row) => [
            'account_type' => $row->account_type,
            'total_debit_minor' => (int) $row->total_debit_minor,
            'total_credit_minor' => (int) $row->total_credit_minor,
            'net_balance_minor' => (int) $row->total_credit_minor - (int) $row->total_debit_minor,
        ])->values()->all();

        return [
            'society_id' => $society->id,
            'as_of' => $asOfDate->toDateString(),
            'lines' => $lines,
            'grand_total_debit_minor' => array_sum(array_column($lines, 'total_debit_minor')),
            'grand_total_credit_minor' => array_sum(array_column($lines, 'total_credit_minor')),
        ];
    }
}
