<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'society_id', 'member_id', 'amount_minor', 'provider', 'provider_ref',
        'webhook_event_id', 'status', 'intended_breakdown', 'allocated_entry_id',
        'paid_at', 'allocated_at',
    ];

    protected $casts = [
        'intended_breakdown' => 'array',
        'paid_at' => 'datetime',
        'allocated_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }
}
