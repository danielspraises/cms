<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanApplication extends Model
{
    protected $fillable = [
        'society_id', 'member_id', 'loan_product_id', 'workflow_id',
        'requested_principal_minor', 'requested_tenure_months', 'purpose',
        'status', 'current_stage_id', 'loan_id', 'review_cycle',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(ApprovalStage::class, 'current_stage_id');
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(LoanGuarantor::class, 'loan_application_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(LoanApprovalAction::class)->orderBy('created_at');
    }

    public function allGuarantorsAccepted(): bool
    {
        if (!$this->product->requires_guarantors) {
            return true; // gate doesn't apply
        }

        $guarantors = $this->guarantors;
        return $guarantors->count() >= $this->product->guarantors_required
            && $guarantors->every(fn (LoanGuarantor $g) => $g->status === 'accepted');
    }

    /** Every user who has already acted on THIS application, in the CURRENT review cycle. */
    public function actorsWhoHaveActed(): array
    {
        return $this->actions()
            ->where('review_cycle', $this->review_cycle)
            ->pluck('actor_id')
            ->unique()
            ->all();
    }
}
