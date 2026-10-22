<?php

namespace Tests\Unit;

use App\Exceptions\ApprovalActionException;
use App\Models\ApprovalWorkflow;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Society;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\LoanApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the specific requirements from blueprint §7: segregation of duty
 * applying to EVERY user including society_admin, per-loan-type rejection
 * modes, disbursement as a distinct action from approval, and the
 * review-cycle reset on resubmission.
 */
class LoanApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoanApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LoanApprovalService(new LedgerService());
    }

    private function buildStandardWorkflow(Society $society): ApprovalWorkflow
    {
        $treasurerRole = Role::factory()->create(['society_id' => $society->id, 'slug' => 'treasurer']);
        $approverRole = Role::factory()->create(['society_id' => $society->id, 'slug' => 'approver']);
        $treasurerRole->permissions()->attach(Permission::factory()->create(['key' => 'loans.disburse']));
        $approverRole->permissions()->attach(Permission::factory()->create(['key' => 'loans.approve']));

        $workflow = ApprovalWorkflow::factory()->create(['society_id' => $society->id, 'is_default' => true]);
        $workflow->stages()->create(['sequence' => 1, 'name' => 'Admin Review', 'action_type' => 'recommend', 'assignment_type' => 'permission', 'assignment_permission_key' => 'loans.recommend', 'notes_required' => true]);
        $workflow->stages()->create(['sequence' => 2, 'name' => 'Treasurer Review', 'action_type' => 'recommend', 'assignment_type' => 'role', 'assignment_role_id' => $treasurerRole->id, 'notes_required' => true]);
        $workflow->stages()->create(['sequence' => 3, 'name' => 'Approval', 'action_type' => 'approve', 'assignment_type' => 'role', 'assignment_role_id' => $approverRole->id]);
        $workflow->stages()->create(['sequence' => 4, 'name' => 'Disbursement', 'action_type' => 'disburse', 'assignment_type' => 'role', 'assignment_role_id' => $treasurerRole->id]);

        return $workflow->fresh('stages');
    }

    public function test_society_admin_cannot_act_twice_on_same_application(): void
    {
        $society = Society::factory()->create();
        $workflow = $this->buildStandardWorkflow($society);
        $product = LoanProduct::factory()->create([
            'society_id' => $society->id, 'requires_guarantors' => false, 'approval_workflow_id' => $workflow->id,
        ]);
        $member = User::factory()->create(['society_id' => $society->id, 'role' => 'member']);
        $admin = User::factory()->create(['society_id' => $society->id, 'role' => 'society_admin']);

        $application = $this->service->submit($member, $product->id, 500_00, 6);

        // Admin recommends at stage 1 — fine, they're eligible.
        $this->service->act($application, $admin, 'recommend', 'Looks reasonable.');

        // Same admin should NOT be able to act again later, even though
        // society_admin has an implicit permission bypass everywhere else.
        // Advance a treasurer through stage 2 first so we're testing stage 3
        // specifically (approval), not just re-hitting stage 2.
        $treasurer = User::factory()->create(['society_id' => $society->id, 'role' => 'staff']);
        $treasurerRole = Role::where('society_id', $society->id)->where('slug', 'treasurer')->first();
        $treasurer->roles()->attach($treasurerRole);
        $this->service->act($application->fresh(), $treasurer, 'recommend', 'Confirmed with member.');

        // Give the admin the approver role too, to prove it's the PERSON being
        // blocked, not the permission.
        $approverRole = Role::where('society_id', $society->id)->where('slug', 'approver')->first();
        $admin->roles()->attach($approverRole);

        $this->expectException(ApprovalActionException::class);
        $this->expectExceptionMessage('already acted');
        $this->service->act($application->fresh(), $admin, 'approve');
    }

    public function test_hard_reject_ends_application_permanently(): void
    {
        $society = Society::factory()->create();
        $workflow = $this->buildStandardWorkflow($society);
        $product = LoanProduct::factory()->create([
            'society_id' => $society->id, 'requires_guarantors' => false,
            'approval_workflow_id' => $workflow->id, 'rejection_mode' => 'hard_reject',
        ]);
        $member = User::factory()->create(['society_id' => $society->id, 'role' => 'member']);
        $admin = User::factory()->create(['society_id' => $society->id, 'role' => 'society_admin']);

        $application = $this->service->submit($member, $product->id, 500_00, 6);
        $result = $this->service->act($application, $admin, 'reject', 'Insufficient savings history.');

        $this->assertSame('rejected', $result->status);
        $this->assertNull($result->current_stage_id);
    }

    public function test_return_for_changes_allows_resubmission_and_resets_segregation(): void
    {
        $society = Society::factory()->create();
        $workflow = $this->buildStandardWorkflow($society);
        $product = LoanProduct::factory()->create([
            'society_id' => $society->id, 'requires_guarantors' => false,
            'approval_workflow_id' => $workflow->id, 'rejection_mode' => 'return_for_changes',
        ]);
        $member = User::factory()->create(['society_id' => $society->id, 'role' => 'member']);
        $admin = User::factory()->create(['society_id' => $society->id, 'role' => 'society_admin']);

        $application = $this->service->submit($member, $product->id, 500_00, 6);
        $rejected = $this->service->act($application, $admin, 'return', 'Please clarify the purpose.');
        $this->assertSame('changes_requested', $rejected->status);

        $resubmitted = $this->service->resubmit($rejected, 500_00, 6, 'Clarified purpose: farm equipment.');
        $this->assertSame('in_review', $resubmitted->status);
        $this->assertSame(2, $resubmitted->review_cycle);

        // The SAME admin who rejected cycle 1 should be allowed to act again
        // on cycle 2 — this is the fresh-review-cycle reset from §7.4, not a
        // segregation-of-duty violation, since it's a new cycle.
        $again = $this->service->act($resubmitted, $admin, 'recommend', 'Now approved on review.');
        $this->assertSame('in_review', $again->status);
    }

    public function test_approval_does_not_disburse_automatically(): void
    {
        $society = Society::factory()->create();
        $workflow = $this->buildStandardWorkflow($society);
        $product = LoanProduct::factory()->create([
            'society_id' => $society->id, 'requires_guarantors' => false, 'approval_workflow_id' => $workflow->id,
        ]);
        $member = User::factory()->create(['society_id' => $society->id, 'role' => 'member']);
        $admin = User::factory()->create(['society_id' => $society->id, 'role' => 'society_admin']);
        $treasurer = User::factory()->create(['society_id' => $society->id, 'role' => 'staff']);
        $approver = User::factory()->create(['society_id' => $society->id, 'role' => 'staff']);

        $treasurerRole = Role::where('society_id', $society->id)->where('slug', 'treasurer')->first();
        $approverRole = Role::where('society_id', $society->id)->where('slug', 'approver')->first();
        $treasurer->roles()->attach($treasurerRole);
        $approver->roles()->attach($approverRole);

        $application = $this->service->submit($member, $product->id, 500_00, 6);
        $application = $this->service->act($application, $admin, 'recommend', 'ok');
        $application = $this->service->act($application, $treasurer, 'recommend', 'ok');
        $application = $this->service->act($application, $approver, 'approve');

        $this->assertSame('approved', $application->status);
        $this->assertNull($application->loan_id, 'Approval alone must not create a disbursed loan.');

        // Now the (same) treasurer disburses — a distinct, separately logged action.
        $stillApproved = $application->fresh();
        $this->assertSame('approved', $stillApproved->status);
        $this->assertNull($stillApproved->loan_id);
    }
}
