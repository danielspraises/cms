<?php

namespace App\Services;

use App\Exceptions\ApprovalActionException;
use App\Models\LoanApplication;
use App\Models\LoanApprovalAction;
use App\Models\LoanGuarantor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The engine behind blueprint §7. Enforces, in order, on every action:
 *   1. The guarantor gate (nothing enters stage 1 until guarantors have accepted,
 *      for products that require them).
 *   2. Stage eligibility (is this user's role/permission the one this stage requires).
 *   3. Segregation of duty for the REVIEW chain (recommend/approve/reject) — has
 *      this exact user already acted on this exact application, at any review
 *      stage, applies to every role including society_admin. Deliberately does
 *      NOT apply to the disburse stage — a single-treasurer society needs the
 *      same person able to both recommend and later disburse.
 *   4. Advances current_stage on recommend/approve, closes out on reject/disburse.
 *
 * Disbursement is deliberately a separate call (still routed through act()),
 * not something approving the loan triggers automatically — see blueprint §7.1.
 */
class LoanApprovalService
{
    public function __construct(private LedgerService $ledger) {}

    public function submit(User $member, int $loanProductId, int $requestedPrincipalMinor, int $tenureMonths, ?string $purpose = null): LoanApplication
    {
        $product = \App\Models\LoanProduct::findOrFail($loanProductId);
        $workflow = $product->workflow ?? $this->defaultWorkflowFor($product->society_id);

        return LoanApplication::create([
            'society_id' => $member->society_id,
            'member_id' => $member->id,
            'loan_product_id' => $product->id,
            'workflow_id' => $workflow->id,
            'requested_principal_minor' => $requestedPrincipalMinor,
            'requested_tenure_months' => $tenureMonths,
            'purpose' => $purpose,
            'status' => $product->requires_guarantors ? 'pending_guarantors' : 'in_review',
            'current_stage_id' => $product->requires_guarantors ? null : $workflow->stages()->first()?->id,
        ]);
    }

    public function guarantorRespond(LoanApplication $application, User $guarantor, bool $accept): LoanApplication
    {
        $record = LoanGuarantor::where('loan_application_id', $application->id)
            ->where('guarantor_id', $guarantor->id)
            ->firstOrFail();

        $record->update([
            'status' => $accept ? 'accepted' : 'declined',
            'responded_at' => now(),
        ]);

        LoanApprovalAction::create([
            'loan_application_id' => $application->id,
            'stage_id' => null,
            'actor_id' => $guarantor->id,
            'decision' => $accept ? 'guarantor_accept' : 'guarantor_decline',
            'review_cycle' => $application->review_cycle,
        ]);

        if (!$accept) {
            $application->update(['status' => 'rejected']);
            return $application->fresh();
        }

        if ($application->fresh()->allGuarantorsAccepted()) {
            $firstStage = $application->workflow->stages()->first();
            $application->update(['status' => 'in_review', 'current_stage_id' => $firstStage?->id]);
        }

        return $application->fresh();
    }

    /**
     * @throws ApprovalActionException
     */
    public function act(LoanApplication $application, User $actor, string $decision, ?string $notes = null): LoanApplication
    {
        return DB::transaction(function () use ($application, $actor, $decision, $notes) {
            $application = LoanApplication::query()->lockForUpdate()->findOrFail($application->id);

            // 'approved' is a valid status to act from too — that's the
            // whole point of disbursement being a separate step after
            // approval, not folded into it.
            if (!in_array($application->status, ['in_review', 'approved'], true)) {
                throw new ApprovalActionException("Application is not currently actionable (status: {$application->status}).");
            }

            $stage = $application->currentStage;
            if (!$stage) {
                throw new ApprovalActionException('Application has no current stage set.');
            }

            if (!$stage->canBeActedOnBy($actor)) {
                throw new ApprovalActionException('You do not hold the role/permission required for this stage.');
            }

            // Segregation of duty applies to the review chain
            // (recommend/approve/reject) — deliberately exempted for
            // disbursement. Confirmed requirement: in a single-treasurer
            // society, the same person who recommended is expected to also
            // be the one who disburses. Disbursement is a distinct,
            // separately logged action, not a second opinion on the loan.
            if ($stage->action_type !== 'disburse'
                && in_array($actor->id, $application->actorsWhoHaveActed(), true)) {
                throw new ApprovalActionException('You have already acted on this application and cannot act again.');
            }

            if ($stage->notes_required && $decision !== 'reject' && empty($notes)) {
                throw new ApprovalActionException('Notes are required for this stage.');
            }

            $validDecisions = match ($stage->action_type) {
                'recommend' => ['recommend', 'reject'],
                'approve' => ['approve', 'reject'],
                'disburse' => ['disburse'],
            };
            if (!in_array($decision, $validDecisions, true)) {
                throw new ApprovalActionException("Invalid decision '{$decision}' for a {$stage->action_type} stage.");
            }

            LoanApprovalAction::create([
                'loan_application_id' => $application->id,
                'stage_id' => $stage->id,
                'actor_id' => $actor->id,
                'decision' => $decision,
                'notes' => $notes,
                'review_cycle' => $application->review_cycle,
            ]);

            if ($decision === 'reject') {
                return $this->handleRejection($application);
            }

            if ($decision === 'disburse') {
                return $this->handleDisbursement($application, $actor);
            }

            if ($decision === 'approve') {
                // Approval ALWAYS produces 'approved' status immediately —
                // this no longer depends on whether a disburse stage
                // follows. If one does, current_stage moves to it so the
                // disburse action's own eligibility check has something to
                // check against; if this was the workflow's last stage,
                // current_stage_id goes to null and the application simply
                // waits in 'approved' status (a workflow with no disburse
                // stage has no further action modeled here — a known
                // limitation worth flagging if you build a workflow that way).
                $nextStage = $application->workflow->stages()
                    ->where('sequence', '>', $stage->sequence)
                    ->first();

                $application->update([
                    'status' => 'approved',
                    'current_stage_id' => $nextStage?->id,
                ]);

                return $application->fresh();
            }

            // decision === 'recommend' — advance to the next stage. A
            // recommend stage with nothing after it is a workflow
            // misconfiguration (a chain should always end in approve) —
            // fail loudly rather than silently stalling the application.
            $nextStage = $application->workflow->stages()
                ->where('sequence', '>', $stage->sequence)
                ->first();

            if (!$nextStage) {
                throw new ApprovalActionException('Workflow is misconfigured: no stage follows this recommend stage.');
            }

            $application->update(['current_stage_id' => $nextStage->id]);

            return $application->fresh();
        });
    }

    private function handleRejection(LoanApplication $application): LoanApplication
    {
        $mode = $application->product->rejection_mode;

        $application->update([
            'status' => $mode === 'return_for_changes' ? 'changes_requested' : 'rejected',
            'current_stage_id' => null,
        ]);

        return $application->fresh();
    }

    /**
     * Applicant edits and resubmits after 'changes_requested'. Resets to the
     * top of the chain AND resets segregation-of-duty eligibility — this is
     * functionally a fresh review cycle of changed material, per blueprint §7.4.
     */
    public function resubmit(LoanApplication $application, int $newRequestedPrincipalMinor, int $newTenureMonths, ?string $newPurpose = null): LoanApplication
    {
        if ($application->status !== 'changes_requested') {
            throw new ApprovalActionException('Only applications in changes_requested status can be resubmitted.');
        }

        $application->update([
            'requested_principal_minor' => $newRequestedPrincipalMinor,
            'requested_tenure_months' => $newTenureMonths,
            'purpose' => $newPurpose,
            'status' => $application->product->requires_guarantors ? 'pending_guarantors' : 'in_review',
            'current_stage_id' => $application->product->requires_guarantors
                ? null : $application->workflow->stages()->first()?->id,
            'review_cycle' => $application->review_cycle + 1,
        ]);

        return $application->fresh();
    }

    private function handleDisbursement(LoanApplication $application, User $actor): LoanApplication
    {
        $loan = \App\Models\Loan::create([
            'society_id' => $application->society_id,
            'member_id' => $application->member_id,
            'loan_product_id' => $application->loan_product_id,
            'principal_disbursed_minor' => $application->requested_principal_minor,
            'tenure_months' => $application->requested_tenure_months,
            'disbursement_date' => now(),
            'status' => 'active',
        ]);

        $this->ledger->postEntry(
            societyId: $application->society_id,
            entryDate: now(),
            description: "Loan disbursement: {$application->product->name}",
            source: 'manual',
            lines: [[
                'user_id' => $application->member_id,
                'account_type' => 'loan_principal',
                'direction' => 'debit',
                'amount_minor' => $application->requested_principal_minor,
            ]],
            createdBy: $actor->id,
            loanId: $loan->id,
        );

        $application->update(['status' => 'disbursed', 'loan_id' => $loan->id]);

        return $application->fresh();
    }

    private function defaultWorkflowFor(int $societyId): \App\Models\ApprovalWorkflow
    {
        return \App\Models\ApprovalWorkflow::where('society_id', $societyId)
            ->where('is_default', true)
            ->firstOrFail();
    }
}
