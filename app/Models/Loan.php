<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'society_id', 'member_id', 'loan_product_id',
        'principal_disbursed_minor', 'principal_repaid_minor',
        'interest_disbursed_minor', 'interest_repaid_minor',
        'sales_disbursed_minor', 'sales_repaid_minor',
        'tenure_months', 'grace_months', 'disbursement_date',
        'guarantors_required', 'status', 'external_transaction_id',
    ];

    protected $casts = ['disbursement_date' => 'date'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function society(): BelongsTo
    {
        return $this->belongsTo(Society::class);
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(LoanGuarantor::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Read amounts for a given fund type without a switch statement
     * scattered through the codebase — this is the single place that
     * maps 'principal' | 'interest' | 'sales' to the right columns.
     */
    public function disbursedMinor(string $fundType): int
    {
        return match ($fundType) {
            'principal' => $this->principal_disbursed_minor,
            'interest' => $this->interest_disbursed_minor,
            'sales' => $this->sales_disbursed_minor,
            default => throw new \InvalidArgumentException("Unknown fund type: {$fundType}"),
        };
    }

    public function repaidMinor(string $fundType): int
    {
        return match ($fundType) {
            'principal' => $this->principal_repaid_minor,
            'interest' => $this->interest_repaid_minor,
            'sales' => $this->sales_repaid_minor,
            default => throw new \InvalidArgumentException("Unknown fund type: {$fundType}"),
        };
    }

    public function remainingMinor(string $fundType): int
    {
        return max(0, $this->disbursedMinor($fundType) - $this->repaidMinor($fundType));
    }

    public function isFullyRepaid(): bool
    {
        foreach (['principal', 'interest', 'sales'] as $fundType) {
            if ($this->remainingMinor($fundType) > 0) {
                return false;
            }
        }
        return true;
    }
}
