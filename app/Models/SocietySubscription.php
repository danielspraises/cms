<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocietySubscription extends Model
{
    protected $fillable = [
        'society_id', 'billing_plan_id', 'cycle_months_override', 'amount_minor_override',
        'status', 'current_period_start', 'current_period_end', 'next_billing_date',
    ];

    protected $casts = [
        'current_period_start' => 'date',
        'current_period_end' => 'date',
        'next_billing_date' => 'date',
    ];

    public function society(): BelongsTo
    {
        return $this->belongsTo(Society::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscription_id');
    }

    /** Cycle length actually in effect: super-admin override wins over the plan default. */
    public function effectiveCycleMonths(): int
    {
        return $this->cycle_months_override ?? $this->plan?->cycleMonths() ?? 1;
    }

    public function effectiveAmountMinor(): int
    {
        return $this->amount_minor_override ?? $this->plan?->amount_minor ?? 0;
    }
}
