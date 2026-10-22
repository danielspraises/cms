<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One earlier-loan balance that is cleared out of a new loan's proceeds. */
class LoanDisbursementSettlement extends Model
{
    protected $fillable = [
        'disbursement_id', 'loan_id', 'fund_type', 'amount_minor', 'balance_at_preparation_minor',
    ];

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(LoanDisbursement::class, 'disbursement_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}