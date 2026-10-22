<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'society_id', 'name', 'type', 'interest_method',
        'requires_guarantors', 'guarantors_required', 'rejection_mode', 'approval_workflow_id',
        'max_tenure_months', 'interest_bands',
    ];

    protected $casts = [
        'requires_guarantors' => 'boolean',
        'interest_bands' => 'array',
    ];

    public function society(): BelongsTo
    {
        return $this->belongsTo(Society::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }
}