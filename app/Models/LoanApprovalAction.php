<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanApprovalAction extends Model
{
    protected $fillable = ['loan_application_id', 'stage_id', 'actor_id', 'decision', 'notes'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ApprovalStage::class, 'stage_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
