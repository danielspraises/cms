<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeductionRun extends Model
{
    protected $fillable = [
        'channel_id', 'scheduled_for', 'amount_minor', 'status', 'attempt_count',
        'provider_ref', 'failure_reason', 'ledger_entry_id',
    ];

    protected $casts = ['scheduled_for' => 'date'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(DeductionChannel::class, 'channel_id');
    }
}
