<?php

namespace Tests\Unit;

use App\Exceptions\ApprovalActionException;
use App\Models\LedgerEntry;
use App\Models\LedgerLine;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanDisbursement;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\Society;
use App\Models\User;
use App\Services\DisbursementService;
use App\Services\LoanApprovalService;
use App\Services\SocietyProvisioningService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The example from the design: a member with 50,000.00 still owing takes a new
 * 5,000,000.00 loan over 6 months at 12% a year. Interest is 300,000.00, the
 * earlier balance is cleared from the proceeds, and 4,950,000.00 is paid out.
 */
class DisbursementPipelineTest extends TestCase
{
    use RefreshDatabase;

    private LoanApprovalService $approval;
    private DisbursementService $disbursements;
    private Society $society;
    private User $admin;
    private User $treasurer;
    private User $approver;
    private User $member;
    private Loan $oldLoan;
    private LoanApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->approval = app(LoanApprovalService::class);
        $this->disbursements = app(DisbursementService::class);
        $this->society = Society::factory()->create();

        $provisioning = new SocietyProvisioningService();
        $provisioning->provision($this->society);

        $this->admin = User::factory()->create(['society_id' => $this->society->id, 'role' => 'society_admin']);
        $provisioning->grantSocietyAdminRole($this->society, $this->admin);
        $this->treasurer = $this->staffWithRole('treasurer');
        $this->approver = $this->staffWithRole('approver');
        $this->member = User::factory()->create(['society_id' => $this->society->id, 'role' => 'member']);

        // 12% a year for any tenure up to 12 months.
        $product = LoanProduct::factory()->create([
            'society_id' => $this->society->id,
            'requires_guarantors' => false,
            'max_tenure_months' => 12,
            'interest_bands' => [['from_months' => 1, 'to_months' => 12, 'rate_bp' => 1200]],
        ]);

        // An earlier loan with 50,000.00 still owing.
        $this->oldLoan = Loan::factory()->create([
            'society_id' => $this->society->id,
            'member_id' => $this->member->id,
            'status' => 'active',
            'tenure_months' => 12,
            'principal_disbursed_minor' => 100_000_00,
            'principal_repaid_minor' => 50_000_00,
        ]);

        $this->application = $this->approval->submit($this->member, $product->id, 5_000_000_00, 6);
    }

    private function staffWithRole(string $slug): User
    {
        $user = User::factory()->create(['society_id' => $this->society->id, 'role' => 'staff']);
        $user->roles()->attach(
            Role::where('society_id', $this->society->id)->where('slug', $slug)->firstOrFail()
        );

        return $user;
    }

    private function toSettlement(): void
    {
        $this->approval->act($this->application, $this->admin, 'recommend', 'Checked.');
        $this->approval->act($this->application, $this->treasurer, 'recommend', 'Confirmed.');
        $this->approval->act($this->application, $this->approver, 'approve');
    }

    private function sheet(int $amountMinor = 50_000_00): array
    {
        return [['loan_id' => $this->oldLoan->id, 'fund_type' => 'principal', 'amount_minor' => $amountMinor]];
    }

    private function payout(): array
    {
        return ['paid_on' => now()->toDateString(), 'payment_method' => 'bank_transfer', 'payment_reference' => 'TRF-001'];
    }

    private function assertRefused(callable $attempt, string $messagePart): void
    {
        try {
            $attempt();
        } catch (ApprovalActionException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());
            return;
        }

        $this->fail('Expected this to be refused.');
    }

    public function test_the_sheet_works_out_interest_and_net_payout_without_touching_the_ledger(): void
    {
        $this->toSettlement();

        $sheet = $this->disbursements->prepare($this->application, $this->admin, $this->sheet());

        $this->assertSame('prepared', $sheet->status);
        $this->assertSame(1200, $sheet->interest_rate_bp);
        $this->assertSame(300_000_00, $sheet->interest_minor);
        $this->assertSame(50_000_00, $sheet->settlement_total_minor);
        $this->assertSame(4_950_000_00, $sheet->net_payout_minor);
        $this->assertCount(1, $sheet->settlements);
        $this->assertSame(50_000_00, $sheet->settlements->first()->balance_at_preparation_minor);

        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame('Disbursement', $this->application->fresh()->currentStage->name);
    }

    public function test_the_pipeline_posts_the_new_loan_at_full_amount_and_clears_the_old_one(): void
    {
        $this->toSettlement();
        $this->disbursements->prepare($this->application, $this->admin, $this->sheet());

        $paid = $this->disbursements->recordPayout($this->application, $this->treasurer, $this->payout());
        $this->assertSame('paid_out', $paid->status);
        $this->assertSame(0, LedgerEntry::count(), 'Paying out must not write to the ledger.');

        $done = $this->disbursements->confirmAndPost($this->application, $this->admin);

        $this->assertSame('disbursed', $done->status);

        $newLoan = Loan::findOrFail($done->loan_id);
        $this->assertSame(5_000_000_00, $newLoan->principal_disbursed_minor);
        $this->assertSame(300_000_00, $newLoan->interest_disbursed_minor);
        $this->assertSame(6, $newLoan->tenure_months);
        $this->assertSame('active', $newLoan->status);

        $old = $this->oldLoan->refresh();
        $this->assertSame(100_000_00, $old->principal_repaid_minor);
        $this->assertSame('terminated', $old->status);
        $this->assertSame($newLoan->id, $old->settled_by_loan_id);

        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(3, LedgerLine::count());
        $this->assertSame(-4_950_000_00, $this->member->account('loan_principal')->balance_minor);
        $this->assertSame(-300_000_00, $this->member->account('loan_interest')->balance_minor);
        $this->assertSame('posted', LoanDisbursement::first()->status);
    }

    public function test_only_the_right_role_can_do_each_part(): void
    {
        $this->toSettlement();

        $this->assertRefused(
            fn () => $this->disbursements->prepare($this->application, $this->treasurer, $this->sheet()),
            'role/permission'
        );
        $this->disbursements->prepare($this->application, $this->admin, $this->sheet());

        $this->assertRefused(
            fn () => $this->disbursements->recordPayout($this->application, $this->admin, $this->payout()),
            'role/permission'
        );
        $this->disbursements->recordPayout($this->application, $this->treasurer, $this->payout());

        $this->assertRefused(
            fn () => $this->disbursements->confirmAndPost($this->application, $this->treasurer),
            'role/permission'
        );
        $done = $this->disbursements->confirmAndPost($this->application, $this->admin);

        $this->assertSame('disbursed', $done->status);
    }

    public function test_settlements_are_checked_against_what_is_actually_owed(): void
    {
        $this->toSettlement();

        // More than the earlier loan still owes
        $this->assertRefused(
            fn () => $this->disbursements->prepare($this->application, $this->admin, $this->sheet(60_000_00)),
            'outstanding'
        );

        // The same item twice
        $twice = array_merge($this->sheet(10_000_00), $this->sheet(10_000_00));
        $this->assertRefused(
            fn () => $this->disbursements->prepare($this->application, $this->admin, $twice),
            'twice'
        );

        // Somebody else's loan
        $other = User::factory()->create(['society_id' => $this->society->id, 'role' => 'member']);
        $theirs = Loan::factory()->create([
            'society_id' => $this->society->id, 'member_id' => $other->id,
            'status' => 'active', 'principal_disbursed_minor' => 10_000_00,
        ]);
        $this->assertRefused(
            fn () => $this->disbursements->prepare($this->application, $this->admin, [
                ['loan_id' => $theirs->id, 'fund_type' => 'principal', 'amount_minor' => 1_000_00],
            ]),
            "not one of this member's"
        );

        // Clearing more than the new loan is worth
        $big = Loan::factory()->create([
            'society_id' => $this->society->id, 'member_id' => $this->member->id,
            'status' => 'active', 'principal_disbursed_minor' => 6_000_000_00,
        ]);
        $this->assertRefused(
            fn () => $this->disbursements->prepare($this->application, $this->admin, [
                ['loan_id' => $big->id, 'fund_type' => 'principal', 'amount_minor' => 6_000_000_00],
            ]),
            'nothing to pay out'
        );

        // Every refusal rolled back: still waiting at Settlement, nothing saved.
        $this->assertSame('Settlement', $this->application->fresh()->currentStage->name);
        $this->assertSame(0, LoanDisbursement::count());
    }

    public function test_a_changed_balance_stops_the_posting(): void
    {
        $this->toSettlement();
        $this->disbursements->prepare($this->application, $this->admin, $this->sheet());
        $this->disbursements->recordPayout($this->application, $this->treasurer, $this->payout());

        // A repayment lands on the earlier loan after the sheet was prepared.
        $this->oldLoan->update(['principal_repaid_minor' => 100_000_00]);

        $this->assertRefused(
            fn () => $this->disbursements->confirmAndPost($this->application, $this->admin),
            'changed'
        );

        $this->assertSame('Cross-check and posting', $this->application->fresh()->currentStage->name);
        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame(1, Loan::count());
    }
}