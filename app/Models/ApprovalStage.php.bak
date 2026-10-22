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

    /**
     * Can this user take part in this stage? Eligibility only; segregation of
     * duty is checked separately in LoanApprovalService.
     *
     * Platform admins oversee the platform and never take part in a society's
     * approval chain. Everyone else, society admins included, must match the
     * stage's own assignment: the specific role, or the permission.
     */
    public function canBeActedOnBy(User $user): bool
    {
        if ($user->role === 'platform_admin') {
            return false;
        }

        if ($this->assignment_type === 'role') {
            return $user->roles()->where('roles.id', $this->assignment_role_id)->exists();
        }

        return $user->hasPermission($this->assignment_permission_key);
    }
}