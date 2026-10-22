<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The settlement sheet and payout record for one approved loan. Nothing here is
 * in the ledger until the final cross-check step posts it.
 */
class LoanDisbursement extends Model
{
    protected $fillable = [
        'society_id', 'loan_application_id', 'member_id',
        'principal_minor', 'tenure_months', 'interest_rate_bp', 'interest_minor',
        'settlement_total_minor', 'net_payout_minor', 'status',
        'prepared_by', 'prepared_at',
        'paid_by', 'paid_at', 'paid_on', 'payment_method', 'payment_reference',
        'confirmed_by', 'confirmed_at', 'loan_id', 'ledger_entry_id',
    ];

    protected $casts = [
        'prepared_at' => 'datetime',
        'paid_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'paid_on' => 'date:Y-m-d',
    ];

    public function settlements(): HasMany
    {
        return $this->hasMany(LoanDisbursementSettlement::class, 'disbursement_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }
}