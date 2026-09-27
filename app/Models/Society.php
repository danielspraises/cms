<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Society extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'currency'];

    public function members(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'member');
    }

    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'society_admin');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'staff');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(SocietySettings::class);
    }

    /** Always returns a settings object, even before one has been explicitly created. */
    public function effectiveSettings(): SocietySettings
    {
        return $this->settings ?? new SocietySettings([
            'deduction_priority_order' => SocietySettings::DEFAULT_PRIORITY_ORDER,
            'due_calculation_model' => 'total_due',
            'features' => [
                'sales_loans' => true, 'share_deductions' => true, 'bank_auto_debit' => false,
                'csv_deductions' => true, 'loan_guarantors_required' => true, 'member_self_service_payment' => true,
            ],
        ]);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(SocietySubscription::class);
    }

    public function brandTheme(): HasOne
    {
        return $this->hasOne(BrandTheme::class)->where('scope', 'society');
    }

    /**
     * Effective colors for this society — falls back to the platform default
     * theme if the society hasn't customized one, so member apps always have
     * something to render even before a society admin sets their own colors.
     */
    public function effectiveColors(): array
    {
        return $this->brandTheme?->colors
            ?? BrandTheme::where('scope', 'platform')->first()?->colors
            ?? BrandTheme::DEFAULT_COLORS;
    }
}
