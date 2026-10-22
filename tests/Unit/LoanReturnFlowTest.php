<?php

namespace Tests\Unit;

use App\Exceptions\ApprovalActionException;
use App\Models\ApprovalWorkflow;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\Society;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\LoanApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three outcomes at every review stage: recommend/approve, reject (final),
 * and return (one step back).
 */
class LoanReturnFlowTest extends TestCase
{
    use RefreshDatabase;

    private LoanApprovalService $service;
    private Society $society;
    private LoanProduct $product;
    private User $admin;
    private User $treasurer;
    private User $approver;
    private LoanApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LoanApprovalService(new LedgerService());
        $this->society = Society::factory()->create();

        $treasurerRole = Role::factory()->create(['society_id' => $this->society->id, 'slug' => 'treasurer']);
        $approverRole = Role::factory()->create(['society_id' => $this->society->id, 'slug' => 'approver']);

        $workflow = ApprovalWorkflow::factory()->create(['society_id' => $this->society->id, 'is_default' => true]);
        $workflow->stages()->create(['sequence' => 1, 'name' => 'Society Admin Review', 'action_type' => 'recommend', 'assignment_type' => 'permission', 'assignment_permission_key' => 'loans.recommend', 'notes_required' => true]);
        $workflow->stages()->create(['sequence' => 2, 'name' => 'Treasurer Review', 'action_type' => 'recommend', 'assignment_type' => 'role', 'assignment_role_id' => $treasurerRole->id, 'notes_required' => true]);
        $workflow->stages()->create(['sequence' => 3, 'name' => 'Final Approval', 'action_type' => 'approve', 'assignment_type' => 'role', 'assignment_role_id' => $approverRole->id]);
        $workflow->stages()->create(['sequence' => 4, 'name' => 'Disbursement', 'action_type' => 'disburse', 'assignment_type' => 'role', 'assignment_role_id' => $treasurerRole->id]);

        $this->product = LoanProduct::factory()->create([
            'society_id' => $this->society->id,
            'requires_guarantors' => false,
            'approval_workflow_id' => $workflow->id,
        ]);

        $member = User::factory()->create(['society_id' => $this->society->id, 'role' => 'member']);
        $this->admin = User::factory()->create(['society_id' => $this->society->id, 'role' => 'society_admin']);
        $this->treasurer = User::factory()->create(['society_id' => $this->society->id, 'role' => 'staff']);
        $this->treasurer->roles()->attach($treasurerRole);
        $this->approver = User::factory()->create(['society_id' => $this->society->id, 'role' => 'staff']);
        $this->approver->roles()->attach($approverRole);

        $this->application = $this->service->submit($member, $this->product->id, 500_00, 6);
    }

    public function test_treasurer_can_return_to_society_admin_who_can_then_act_again(): void
    {
        $this->service->act($this->application, $this->admin, 'recommend', 'Looks fine.');
        $returned = $this->service->act($this->application, $this->treasurer, 'return', 'Please attach the guarantor form.');

        $this->assertSame('in_review', $returned->status);
        $this->assertSame(1, $returned->currentStage->sequence);

        // Same stage as before the return, so segregation of duty allows it.
        $again = $this->service->act($this->application, $this->admin, 'recommend', 'Form attached.');
        $this->assertSame(2, $again->currentStage->sequence);
    }

    public function test_president_can_return_to_treasurer_who_can_then_act_again(): void
    {
        $this->service->act($this->application, $this->admin, 'recommend', 'ok');
        $this->service->act($this->application, $this->treasurer, 'recommend', 'ok');
        $returned = $this->service->act($this->application, $this->approver, 'return', 'Savings history is unclear.');

        $this->assertSame('in_review', $returned->status);
        $this->assertSame(2, $returned->currentStage->sequence);

        $this->service->act($this->application, $this->treasurer, 'recommend', 'Clarified with the member.');
        $approved = $this->service->act($this->application, $this->approver, 'approve');

        $this->assertSame('approved', $approved->status);
    }

    public function test_society_admin_can_return_to_applicant_who_can_resubmit(): void
    {
        $returned = $this->service->act($this->application, $this->admin, 'return', 'Please clarify the purpose.');

        $this->assertSame('changes_requested', $returned->status);
        $this->assertNull($returned->current_stage_id);

        $resubmitted = $this->service->resubmit($returned, 500_00, 6, 'Clarified.');
        $this->assertSame('in_review', $resubmitted->status);
        $this->assertSame(2, $resubmitted->review_cycle);
    }

    public function test_return_and_reject_both_require_a_written_reason(): void
    {
        foreach (['return', 'reject'] as $decision) {
            try {
                $this->service->act($this->application, $this->admin, $decision, '   ');
                $this->fail("Expected '{$decision}' without a reason to be refused.");
            } catch (ApprovalActionException $e) {
                $this->assertStringContainsString('reason', $e->getMessage());
            }
        }
    }

    public function test_returning_does_not_let_one_person_act_at_a_second_stage(): void
    {
        $dual = User::factory()->create(['society_id' => $this->society->id, 'role' => 'staff']);
        $dual->roles()->attach(Role::where('society_id', $this->society->id)->pluck('id'));

        $this->service->act($this->application, $this->admin, 'recommend', 'ok');
        $this->service->act($this->application, $this->treasurer, 'recommend', 'ok');
        $this->service->act($this->application, $dual, 'return', 'Needs another look.');

        // Back at Treasurer Review. $dual holds the treasurer role but already
        // acted at a different stage, so they cannot take this one too.
        $this->expectException(ApprovalActionException::class);
        $this->expectExceptionMessage('already acted');
        $this->service->act($this->application, $dual, 'recommend', 'I will take it.');
    }

    public function test_reject_is_always_final(): void
    {
        $this->product->update(['rejection_mode' => 'return_for_changes']);

        $rejected = $this->service->act($this->application, $this->admin, 'reject', 'Not eligible.');

        $this->assertSame('rejected', $rejected->status);
    }
}