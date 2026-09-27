<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeductionRow extends Model
{
    protected $fillable = [
        'batch_id', 'member_id', 'raw_member_uid', 'amount_minor',
        'sales_minor', 'loan_interest_minor', 'loan_principal_minor',
        'shares_minor', 'savings_minor', 'status', 'error_message', 'ledger_entry_id',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DeductionBatch::class, 'batch_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }
}
