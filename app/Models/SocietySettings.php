<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocietySettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'society_id', 'deduction_priority_order', 'due_calculation_model',
        'default_grace_months', 'default_interest_method',
        'late_fee_enabled', 'late_fee_type', 'late_fee_amount_minor', 'late_fee_percent_bp',
        'features', 'terminology', 'currency', 'updated_by',
        'granted_features', 'features_updated_by', 'features_updated_at',
    ];

    protected $casts = [
        'deduction_priority_order' => 'array',
        'features' => 'array',
        'terminology' => 'array',
        'late_fee_enabled' => 'boolean',
        'granted_features' => 'array',
        'features_updated_at' => 'datetime',
    ];

    public const DEFAULT_PRIORITY_ORDER = ['sales', 'loan_interest', 'loan_principal', 'shares', 'savings'];

    /**
     * Features that require explicit platform-admin grant before a society
     * admin can enable them (blueprint §9.3). Any feature key NOT in this
     * list is a core feature — always available, controlled purely by the
     * society admin's own toggle with no platform gate.
     */
    public const GATED_FEATURE_KEYS = [
        'bank_auto_debit', 'custom_approval_workflows', 'ai_risk_scoring', 'branding_customization',
    ];

    public function society(): BelongsTo
    {
        return $this->belongsTo(Society::class);
    }

    public function isFeatureEnabled(string $key): bool
    {
        return (bool) ($this->features[$key] ?? false);
    }

    public function isFeatureGranted(string $key): bool
    {
        if (!in_array($key, self::GATED_FEATURE_KEYS, true)) {
            return true; // core feature, no platform gate applies
        }

        return in_array($key, $this->granted_features ?? [], true);
    }

    public function terminologyFor(string $key, string $fallback): string
    {
        return $this->terminology[$key] ?? $fallback;
    }
}
