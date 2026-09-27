<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalWorkflow extends Model
{
    use HasFactory;

    protected $fillable = ['society_id', 'name', 'is_default'];

    public function society(): BelongsTo
    {
        return $this->belongsTo(Society::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalStage::class, 'workflow_id')->orderBy('sequence');
    }
}
