<?php

namespace Tests\Unit;

use App\Models\Loan;
use App\Services\LoanDueCalculator;
use Carbon\Carbon;
use Tests\TestCase;

class LoanDueCalculatorTest extends TestCase
{
    private LoanDueCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new LoanDueCalculator();
    }

    public function test_no_amount_due_before_disbursement(): void
    {
        $loan = Loan::factory()->make([
            'disbursement_date' => null,
        ]);

        $this->assertSame(0, $this->calculator->amountDue($loan, 'principal'));
    }

    public function test_no_amount_due_during_grace_period(): void
    {
        $loan = Loan::factory()->make([
            'disbursement_date' => Carbon::parse('2026-01-01'),
            'grace_months' => 3,
            'tenure_months' => 12,
            'principal_disbursed_minor' => 1_200_00, // 1,200.00 in kobo
            'principal_repaid_minor' => 0,
        ]);

        // Still inside the 3-month grace window
        $asOf = Carbon::parse('2026-02-15');

        $this->assertSame(0, $this->calculator->amountDue($loan, 'principal', $asOf));
    }

    public function test_one_month_due_after_grace_period_ends(): void
    {
        $loan = Loan::factory()->make([
            'disbursement_date' => Carbon::parse('2026-01-01'),
            'grace_months' => 0,
            'tenure_months' => 12,
            'principal_disbursed_minor' => 1_200_00,
            'principal_repaid_minor' => 0,
        ]);

        $asOf = Carbon::parse('2026-02-01'); // exactly one month elapsed

        $this->assertSame(100_00, $this->calculator->amountDue($loan, 'principal', $asOf));
    }

    public function test_total_due_accumulates_across_missed_months(): void
    {
        // This is the behavior difference from the legacy system flagged in
        // the redesign doc §5.1/§9.1 — three missed months should demand
        // three installments, not cap at one.
        $loan = Loan::factory()->make([
            'disbursement_date' => Carbon::parse('2026-01-01'),
            'grace_months' => 0,
            'tenure_months' => 12,
            'principal_disbursed_minor' => 1_200_00,
            'principal_repaid_minor' => 0,
        ]);

        $asOf = Carbon::parse('2026-04-01'); // three months elapsed, nothing paid

        $this->assertSame(300_00, $this->calculator->amountDue($loan, 'principal', $asOf));
    }

    public function test_due_is_capped_at_remaining_balance_near_payoff(): void
    {
        $loan = Loan::factory()->make([
            'disbursement_date' => Carbon::parse('2026-01-01'),
            'grace_months' => 0,
            'tenure_months' => 12,
            'principal_disbursed_minor' => 1_200_00,
            'principal_repaid_minor' => 1_150_00, // only 50.00 left
        ]);

        $asOf = Carbon::parse('2027-06-01'); // well past full tenure

        $this->assertSame(50_00, $this->calculator->amountDue($loan, 'principal', $asOf));
    }

    public function test_termination_eligibility_requires_full_repayment_and_elapsed_tenure(): void
    {
        $loan = Loan::factory()->make([
            'disbursement_date' => Carbon::parse('2026-01-01'),
            'grace_months' => 0,
            'tenure_months' => 6,
            'principal_disbursed_minor' => 600_00,
            'principal_repaid_minor' => 600_00,
            'interest_disbursed_minor' => 0,
            'interest_repaid_minor' => 0,
            'sales_disbursed_minor' => 0,
            'sales_repaid_minor' => 0,
        ]);

        $this->assertTrue(
            $this->calculator->isEligibleForTermination($loan, Carbon::parse('2026-07-01'))
        );

        $this->assertFalse(
            $this->calculator->isEligibleForTermination($loan, Carbon::parse('2026-05-01')),
            'Tenure has not elapsed yet, should not be eligible even though fully repaid.'
        );
    }
}
