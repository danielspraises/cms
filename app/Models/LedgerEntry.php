<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerEntry extends Model
{
    protected $fillable = [
        'society_id', 'batch_id', 'loan_id', 'entry_date', 'description',
        'source', 'created_by', 'reversed_of_id',
    ];

    protected $casts = ['entry_date' => 'date'];

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class, 'entry_id');
    }

    public function society(): BelongsTo
    {
        return $this->belongsTo(Society::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
