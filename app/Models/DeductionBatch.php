<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeductionBatch extends Model
{
    protected $fillable = [
        'society_id', 'uploaded_by', 'filename', 'file_hash',
        'deduction_date', 'status', 'distributed_at',
    ];

    protected $casts = ['deduction_date' => 'date', 'distributed_at' => 'datetime'];

    public function rows(): HasMany
    {
        return $this->hasMany(DeductionRow::class, 'batch_id');
    }
}
