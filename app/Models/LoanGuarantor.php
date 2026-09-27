<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanGuarantor extends Model
{
    protected $fillable = ['loan_id', 'loan_application_id', 'guarantor_id', 'status', 'responded_at'];

    protected $casts = ['responded_at' => 'datetime'];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function guarantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guarantor_id');
    }
}
