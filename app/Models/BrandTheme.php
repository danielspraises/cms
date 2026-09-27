<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandTheme extends Model
{
    protected $fillable = ['scope', 'society_id', 'colors', 'updated_by'];

    protected $casts = ['colors' => 'array'];

    public const DEFAULT_COLORS = [
        'primary' => '#1e3a8a',
        'secondary' => '#2563eb',
        'accent' => '#10b981',
        'background' => '#f9fafb',
        'text' => '#111827',
    ];

    public function society(): BelongsTo
    {
        return $this->belongsTo(Society::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
