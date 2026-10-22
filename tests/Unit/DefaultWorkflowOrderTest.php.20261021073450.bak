<?php

namespace Tests\Unit;

use App\Exceptions\ApprovalActionException;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\Society;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\LoanApprovalService;
use App\Services\SocietyProvisioningService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uses the chain a brand-new society actually gets, so this catches anyone being
 * able to take a step that belongs to someone else.
 */
class DefaultWorkflowOrderTest extends TestCase
{
    use RefreshDatabase;

    private LoanApprovalService $service;
    private Society $society;
    private User $admin;
    private User $treasurer;
    private User $approver;
    private User $platformAdmin;
    private LoanApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->service = new LoanApprovalService(new LedgerService());
        $this->society = Society::factory()->create();

        $provisioning = new SocietyProvisioningService();
        $provisioning->provision($this->society);

        $this->admin = User::factory()->create(['society_id' => $this->society->id, 'role' => 'society_admin']);
        $provisioning->grantSocietyAdminRole($this->society, $this->admin);

        $this->treasurer = $this->staffWithRole('treasurer');
        $this->approver = $this->staffWithRole('approver');
        $this->platformAdmin = User::factory()->create(['society_id' => null, 'role' => 'platform_admin']);

        $product = LoanProduct::factory()->create([
            'society_id' => $this->society->id,
            'requires_guarantors' => false,
        ]);
        $member = User::factory()->create(['society_id' => $this->society->id, 'role' => 'member']);

        $this->application = $this->service->submit($member, $product->id, 500_00, 6);
    }

    private function staffWithRole(string $slug): User
    {
        $user = User::factory()->create(['society_id' => $this->society->id, 'role' => 'staff']);
        $user->roles()->attach(
            Role::where('society_id', $this->society->id)->where('slug', $slug)->firstOrFail()
        );

        return $user;
    }

    private function assertCannotAct(User $user): void
    {
        try {
            $this->service->act($this->application, $user, 'recommend', 'Trying to act out of turn.');
        } catch (ApprovalActionException $e) {
            $this->assertStringContainsString('role/permission', $e->getMessage());
            return;
        }

        $this->fail('Expected this person to be refused.');
    }

    private function takeItToSettlement(): void
    {
        $this->service->act($this->application, $this->admin, 'recommend', 'Checked.');
        $this->service->act($this->application, $this->treasurer, 'recommend', 'Confirmed.');
        $approved = $this->service->act($this->application, $this->approver, 'approve');

        $this->assertSame('approved', $approved->status);
        $this->assertSame('Settlement', $approved->currentStage->name);
    }

    public function test_only_the_society_admin_can_take_the_first_step(): void
    {
        $this->assertCannotAct($this->treasurer);
        $this->assertCannotAct($this->approver);
        $this->assertCannotAct($this->platformAdmin);

        $moved = $this->service->act($this->application, $this->admin, 'recommend', 'Checked.');

        $this->assertSame(2, $moved->currentStage->sequence);
    }

    public function test_every_step_belongs_only_to_its_own_role_and_runs_in_order(): void
    {
        // 1 Society Admin Review
        $this->service->act($this->application, $this->admin, 'recommend', 'Checked.');

        // 2 Treasurer Review
        $this->assertCannotAct($this->admin);
        $this->assertCannotAct($this->approver);
        $this->assertCannotAct($this->platformAdmin);
        $this->service->act($this->application, $this->treasurer, 'recommend', 'Confirmed.');

        // 3 Final Approval
        $this->assertCannotAct($this->admin);
        $this->assertCannotAct($this->treasurer);
        $this->assertCannotAct($this->platformAdmin);
        $approved = $this->service->act($this->application, $this->approver, 'approve');
        $this->assertSame('approved', $approved->status);

        // 4 Settlement: society admin only, even though they recommended earlier
        $this->assertCannotAct($this->treasurer);
        $this->assertCannotAct($this->approver);
        $this->assertCannotAct($this->platformAdmin);
        $afterSettlement = $this->service->act($this->application, $this->admin, 'prepare');
        $this->assertSame('Disbursement', $afterSettlement->currentStage->name);

        // 5 Disbursement: treasurer only, even though they recommended earlier
        $this->assertCannotAct($this->admin);
        $this->assertCannotAct($this->approver);
        $this->assertCannotAct($this->platformAdmin);
        $afterPayout = $this->service->act($this->application, $this->treasurer, 'disburse');
        $this->assertSame('Cross-check and posting', $afterPayout->currentStage->name);

        // 6 Cross-check and posting: society admin only
        $this->assertCannotAct($this->treasurer);
        $this->assertCannotAct($this->approver);
        $this->assertCannotAct($this->platformAdmin);
        $done = $this->service->act($this->application, $this->admin, 'confirm');
        $this->assertSame('disbursed', $done->status);
        $this->assertNull($done->current_stage_id);
    }

    public function test_the_treasurer_can_send_a_payout_back_to_settlement(): void
    {
        $this->takeItToSettlement();
        $this->service->act($this->application, $this->admin, 'prepare');

        $returned = $this->service->act($this->application, $this->treasurer, 'return', 'The settlement sheet looks wrong.');

        $this->assertSame('approved', $returned->status);
        $this->assertSame('Settlement', $returned->currentStage->name);

        $again = $this->service->act($this->application, $this->admin, 'prepare');
        $this->assertSame('Disbursement', $again->currentStage->name);
    }
}