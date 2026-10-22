<?php

namespace App\Services;

use App\Exceptions\ApprovalActionException;
use App\Models\ApprovalStage;
use App\Models\LoanApplication;
use App\Models\LoanApprovalAction;
use App\Models\LoanGuarantor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Engine for the multi-stage chain. The review steps (recommend / approve) offer
 * three outcomes: move forward, reject (final), or return (one step back).
 * After approval come three operational steps, each owned by one role:
 *   prepare   the settlement sheet     (society admin)
 *   disburse  the payout               (treasurer)
 *   confirm   cross-check and posting  (society admin)
 * This engine only records and advances. The sheet and the ledger posting are
 * done by DisbursementService; nothing here writes to the ledger.
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

            // Segregation of duty covers the REVIEW steps only: nobody may act
            // at a second, different review step in the same review cycle.
            // Acting again at your own step is fine (that is what a return to
            // you looks like). The operational steps after approval are exempt.
            $isReviewStage = in_array($stage->action_type, ['recommend', 'approve'], true);
            if ($isReviewStage && $application->hasActedAtAnotherStage($actor->id, $stage->id)) {
                throw new ApprovalActionException('You have already acted on this application at another stage and cannot act again.');
            }

            $validDecisions = match ($stage->action_type) {
                'recommend' => ['recommend', 'reject', 'return'],
                'approve' => ['approve', 'reject', 'return'],
                'prepare' => ['prepare'],
                'disburse' => ['disburse', 'return'],
                'confirm' => ['confirm', 'return'],
            };
            if (!in_array($decision, $validDecisions, true)) {
                throw new ApprovalActionException("Invalid decision '{$decision}' for a {$stage->action_type} stage.");
            }

            $notes = trim((string) $notes);
            if (in_array($decision, ['reject', 'return'], true)) {
                if ($notes === '') {
                    throw new ApprovalActionException('A written reason is required to ' . $decision . ' an application.');
                }
            } elseif ($stage->notes_required && $notes === '') {
                throw new ApprovalActionException('Notes are required for this stage.');
            }

            LoanApprovalAction::create([
                'loan_application_id' => $application->id,
                'stage_id' => $stage->id,
                'actor_id' => $actor->id,
                'decision' => $decision,
                'notes' => $notes === '' ? null : $notes,
                'review_cycle' => $application->review_cycle,
            ]);

            if ($decision === 'reject') {
                return $this->handleRejection($application);
            }

            if ($decision === 'return') {
                return $this->handleReturn($application, $stage);
            }

            if ($decision === 'confirm') {
                // Final step. DisbursementService has posted the ledger entries
                // and recorded the loan before it calls this.
                $application->update(['status' => 'disbursed', 'current_stage_id' => null]);

                return $application->fresh();
            }

            $nextStage = $application->workflow->stages()
                ->where('sequence', '>', $stage->sequence)
                ->first();

            if ($decision === 'approve') {
                // Approval always produces 'approved' immediately and never
                // moves money. The next step (settlement) becomes current.
                $application->update(['status' => 'approved', 'current_stage_id' => $nextStage?->id]);

                return $application->fresh();
            }

            // recommend, prepare, disburse: advance to the next step.
            if (!$nextStage) {
                throw new ApprovalActionException('Workflow is misconfigured: no step follows this one.');
            }

            $application->update(['current_stage_id' => $nextStage->id]);

            return $application->fresh();
        });
    }

    /** Rejection is final. Sending something back is what "return" is for. */
    private function handleRejection(LoanApplication $application): LoanApplication
    {
        $application->update(['status' => 'rejected', 'current_stage_id' => null]);

        return $application->fresh();
    }

    /**
     * Sends the application back one step. From any step after the first it goes
     * to the previous step (still in the same review cycle). From the first step
     * it goes back to the applicant; resubmitting restarts the chain.
     */
    private function handleReturn(LoanApplication $application, ApprovalStage $stage): LoanApplication
    {
        $previous = $application->workflow->stages()
            ->where('sequence', '<', $stage->sequence)
            ->reorder('sequence', 'desc')
            ->first();

        if ($previous) {
            $afterApproval = in_array($previous->action_type, ['prepare', 'disburse', 'confirm'], true);
            $application->update([
                'status' => $afterApproval ? 'approved' : 'in_review',
                'current_stage_id' => $previous->id,
            ]);
        } else {
            $application->update(['status' => 'changes_requested', 'current_stage_id' => null]);
        }

        return $application->fresh();
    }

    /**
     * Applicant edits and resubmits after being returned. Restarts the chain and
     * starts a new review cycle, which resets segregation-of-duty eligibility
     * (prior actions stay in the log for history).
     */
    public function resubmit(LoanApplication $application, int $newRequestedPrincipalMinor, int $newTenureMonths, ?string $newPurpose = null): LoanApplication
    {
        if ($application->status !== 'changes_requested') {
            throw new ApprovalActionException('Only applications that were returned to the applicant can be resubmitted.');
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

    private function defaultWorkflowFor(int $societyId): \App\Models\ApprovalWorkflow
    {
        return \App\Models\ApprovalWorkflow::where('society_id', $societyId)
            ->where('is_default', true)
            ->firstOrFail();
    }
}