<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalStage extends Model
{
    protected $fillable = [
        'workflow_id', 'sequence', 'name', 'action_type',
        'assignment_type', 'assignment_role_id', 'assignment_permission_key', 'notes_required',
    ];

    protected $casts = ['notes_required' => 'boolean'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function assignmentRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'assignment_role_id');
    }

    /** Can this specific user act on this stage, purely on eligibility (not yet checking segregation of duty)? */
    public function canBeActedOnBy(User $user): bool
    {
        if (in_array($user->role, ['platform_admin', 'society_admin'], true)) {
            // Even the blanket-access roles must satisfy the stage's intended
            // assignment conceptually — but segregation of duty (checked
            // separately in LoanApprovalService) is what actually constrains
            // them, not stage eligibility. An admin is eligible for every
            // stage by design; whether they're ALLOWED to act on THIS
            // application is a separate, per-application check.
            return true;
        }

        if ($this->assignment_type === 'role') {
            return $user->roles()->where('roles.id', $this->assignment_role_id)->exists();
        }

        return $user->hasPermission($this->assignment_permission_key);
    }
}
