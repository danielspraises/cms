<?php

namespace Tests\Unit;

use App\Models\Loan;
use App\Models\Society;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function member(): array
    {
        $society = Society::factory()->create();
        $member = User::factory()->create(['society_id' => $society->id, 'role' => 'member']);

        return [$society, $member];
    }

    public function test_lines_tied_to_a_loan_keep_that_loans_figures_in_step(): void
    {
        [$society, $member] = $this->member();
        $loan = Loan::factory()->create(['society_id' => $society->id, 'member_id' => $member->id, 'status' => 'active']);
        $other = Loan::factory()->create(['society_id' => $society->id, 'member_id' => $member->id, 'status' => 'active']);

        (new LedgerService())->postEntry(
            societyId: $society->id,
            entryDate: now(),
            description: 'Test posting',
            source: 'system',
            lines: [
                ['user_id' => $member->id, 'account_type' => 'loan_principal', 'direction' => 'debit', 'amount_minor' => 1_000_00, 'loan_id' => $loan->id],
                ['user_id' => $member->id, 'account_type' => 'loan_interest', 'direction' => 'debit', 'amount_minor' => 100_00, 'loan_id' => $loan->id],
                ['user_id' => $member->id, 'account_type' => 'loan_principal', 'direction' => 'credit', 'amount_minor' => 400_00, 'loan_id' => $loan->id],
            ],
        );

        $loan->refresh();
        $this->assertSame(1_000_00, $loan->principal_disbursed_minor);
        $this->assertSame(400_00, $loan->principal_repaid_minor);
        $this->assertSame(100_00, $loan->interest_disbursed_minor);
        $this->assertSame(600_00, $loan->remainingMinor('principal'));

        // A different loan of the same member is untouched.
        $this->assertSame(0, $other->refresh()->principal_disbursed_minor);
    }

    public function test_lines_without_a_loan_leave_loan_figures_alone(): void
    {
        [$society, $member] = $this->member();
        $loan = Loan::factory()->create(['society_id' => $society->id, 'member_id' => $member->id, 'status' => 'active']);

        (new LedgerService())->postEntry(
            societyId: $society->id,
            entryDate: now(),
            description: 'Savings deposit',
            source: 'manual',
            lines: [
                ['user_id' => $member->id, 'account_type' => 'savings', 'direction' => 'credit', 'amount_minor' => 5_000_00],
            ],
        );

        $this->assertSame(0, $loan->refresh()->principal_disbursed_minor);
        $this->assertSame(5_000_00, $member->account('savings')->balance_minor);
    }
}