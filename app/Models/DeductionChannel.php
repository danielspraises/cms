<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeductionChannel extends Model
{
    protected $fillable = [
        'society_id', 'member_id', 'channel_type', 'is_active', 'frequency', 'day_of_period',
        'fixed_amount_minor', 'provider', 'provider_mandate_ref', 'provider_authorized_at',
        'activated_by', 'activated_at', 'deactivated_by', 'deactivated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'provider_authorized_at' => 'datetime',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function society(): BelongsTo
    {
        return $this->belongsTo(Society::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(DeductionRun::class, 'channel_id');
    }

    public function isDueOn(\DateTimeInterface $date): bool
    {
        if (!$this->is_active) {
            return false;
        }

        return match ($this->frequency) {
            'monthly' => (int) $date->format('j') === $this->day_of_period,
            'weekly' => (int) $date->format('N') === $this->day_of_period, // 1=Mon..7=Sun
            'custom' => false, // custom schedules handled by a dedicated rule, not built yet
        };
    }
}
