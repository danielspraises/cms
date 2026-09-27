<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPlan extends Model
{
    protected $fillable = [
        'name', 'cadence', 'custom_cycle_months', 'amount_minor', 'currency', 'is_active',
    ];

    public function cycleMonths(): int
    {
        return match ($this->cadence) {
            'monthly' => 1,
            'quarterly' => 3,
            'biannual' => 6,
            'annual' => 12,
            'custom' => $this->custom_cycle_months ?? 1,
        };
    }
}
