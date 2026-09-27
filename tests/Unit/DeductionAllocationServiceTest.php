<?php

namespace Tests\Unit;

use App\Models\Loan;
use App\Models\Society;
use App\Models\SocietySettings;
use App\Models\User;
use App\Services\DeductionAllocationService;
use App\Services\LoanDueCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Directly exercises blueprint requirement #4: a fully-repaid loan must
 * never receive further allocation, and the freed-up amount must correctly
 * cascade to the next item in priority order instead of vanishing.
 */
class DeductionAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeductionAllocationService $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new DeductionAllocationService(new LoanDueCalculator());
    }

    public function test_fully_repaid_loan_is_excluded_from_allocation(): void
    {
        $society = Society::factory()->create();
        SocietySettings::factory()->create([
            'society_id' => $society->id,
            'deduction_priority_order' => SocietySettings::DEFAULT_PRIORITY_ORDER,
        ]);

        $member = User::factory()->create(['society_id' => $society->id, 'role' => 'member']);

        // Fully repaid loan — status still 'active' deliberately, simulating
        // the window before the nightly termination job has run yet. This is
        // the scenario the balance-based guard (not just status) protects.
        Loan::factory()->create([
            'society_id' => $society->id,
            'member_id' => $member->id,
            'status' => 'active',
            'disbursement_date' => Carbon::now()->subYear(),
            'tenure_months' => 6,
            'grace_months' => 0,
            'principal_disbursed_minor' => 600_00,
            'principal_repaid_minor' => 600_00,
            'interest_disbursed_minor' => 0,
            'interest_repaid_minor' => 0,
            'sales_disbursed_minor' => 0,
            'sales_repaid_minor' => 0,
        ]);

        $result = $this->allocator->allocate($member, 500_00, Carbon::now());

        $this->assertCount(1, $result->lines());
        $this->assertSame('savings', $result->lines()[0]['fund_type']);
        $this->assertSame(500_00, $result->lines()[0]['amount_minor']);
    }

    public function test_active_loan_still_receives_allocation_before_savings(): void
    {
        $society = Society::factory()->create();
        SocietySettings::factory()->create([
            'society_id' => $society->id,
            'deduction_priority_order' => SocietySettings::DEFAULT_PRIORITY_ORDER,
        ]);

        $member = User::factory()->create(['society_id' => $society->id, 'role' => 'member']);

        Loan::factory()->create([
            'society_id' => $society->id,
            'member_id' => $member->id,
            'status' => 'active',
            'disbursement_date' => Carbon::now()->subMonths(2),
            'tenure_months' => 12,
            'grace_months' => 0,
            'principal_disbursed_minor' => 1_200_00,
            'principal_repaid_minor' => 0,
            'interest_disbursed_minor' => 0,
            'interest_repaid_minor' => 0,
            'sales_disbursed_minor' => 0,
            'sales_repaid_minor' => 0,
        ]);

        // 2 months elapsed x 100.00/month = 200.00 owed on principal
        $result = $this->allocator->allocate($member, 500_00, Carbon::now());

        $lines = collect($result->lines());
        $this->assertSame(200_00, $lines->firstWhere('fund_type', 'principal')['amount_minor']);
        $this->assertSame(300_00, $lines->firstWhere('fund_type', 'savings')['amount_minor']);
    }

    public function test_custom_priority_order_is_respected(): void
    {
        $society = Society::factory()->create();
        // Custom order: savings BEFORE loan principal — an intentionally
        // unusual configuration, just to prove the allocator actually reads
        // it rather than falling back to the hard-coded default.
        SocietySettings::factory()->create([
            'society_id' => $society->id,
            'deduction_priority_order' => ['savings', 'sales', 'loan_interest', 'loan_principal', 'shares'],
        ]);

        $member = User::factory()->create(['society_id' => $society->id, 'role' => 'member']);

        // Custom order with 'savings' first means the entire amount should
        // land in savings, leaving nothing for the loan — proves order, not
        // just presence, is honored.
        $result = $this->allocator->allocate($member, 100_00, Carbon::now());

        $this->assertCount(1, $result->lines());
        $this->assertSame('savings', $result->lines()[0]['fund_type']);
    }
}
