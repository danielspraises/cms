<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = [
        'actor_id', 'action', 'entity_type', 'entity_id',
        'before_state', 'after_state', 'reason', 'created_at',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
        'created_at' => 'datetime',
    ];

    public static function record(
        ?int $actorId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): self {
        return self::create([
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_state' => $before,
            'after_state' => $after,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
