<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerLine extends Model
{
    protected $fillable = [
        'entry_id', 'account_id', 'direction', 'amount_minor', 'running_balance_minor',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Signed amount: positive for credit, negative for debit, in minor units. */
    public function signedAmount(): int
    {
        return $this->direction === 'credit' ? $this->amount_minor : -$this->amount_minor;
    }
}
