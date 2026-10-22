<?php

namespace Database\Seeders;

use App\Models\Loan;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\Seeder;

/**
 * Test data only: gives amaka@test.com an earlier loan with 50,000.00 still
 * owing, posted through the ledger the same way real postings are.
 */
class DemoEarlierLoanSeeder extends Seeder
{
    public function run(): void
    {
        $member = User::where('email', 'amaka@test.com')->firstOrFail();

        if (Loan::where('member_id', $member->id)->where('status', 'active')->exists()) {
            $this->command?->info('Amaka already has a running loan, nothing added.');
            return;
        }

        $loan = Loan::create([
            'society_id' => $member->society_id,
            'member_id' => $member->id,
            'tenure_months' => 12,
            'grace_months' => 0,
            'disbursement_date' => now()->subMonths(4)->toDateString(),
            'status' => 'active',
        ]);

        $ledger = new LedgerService();

        $ledger->postEntry($member->society_id, now()->subMonths(4), 'Earlier loan (demo)', 'manual', [
            ['user_id' => $member->id, 'account_type' => 'loan_principal', 'direction' => 'debit', 'amount_minor' => 100_000_00, 'loan_id' => $loan->id],
        ], loanId: $loan->id);

        $ledger->postEntry($member->society_id, now()->subMonth(), 'Repayment on earlier loan (demo)', 'manual', [
            ['user_id' => $member->id, 'account_type' => 'loan_principal', 'direction' => 'credit', 'amount_minor' => 50_000_00, 'loan_id' => $loan->id],
        ], loanId: $loan->id);

        $this->command?->info('Added an earlier loan for Amaka with 50,000.00 outstanding.');
    }
}