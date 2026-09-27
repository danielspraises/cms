<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'society_id', 'subscription_id', 'invoice_number', 'amount_minor', 'currency',
        'period_start', 'period_end', 'due_date', 'status', 'provider_ref', 'paid_at',
    ];

    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date', 'paid_at' => 'datetime',
    ];

    public function society(): BelongsTo
    {
        return $this->belongsTo(Society::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SocietySubscription::class, 'subscription_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date->isPast();
    }
}
