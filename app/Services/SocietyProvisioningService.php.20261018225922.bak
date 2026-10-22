<?php

namespace App\Services;

use App\Models\ApprovalWorkflow;
use App\Models\Role;
use App\Models\Society;

/**
 * Everything a brand-new society needs seeded at creation time, in one
 * place, so PlatformSocietyController::store() isn't doing this inline.
 * Called once, right after the society + first admin are created.
 */
class SocietyProvisioningService
{
    public function provision(Society $society): void
    {
        $clonedRoles = $this->cloneSystemRoleTemplates($society);
        $this->createDefaultWorkflow($society, $clonedRoles);
    }

    private function cloneSystemRoleTemplates(Society $society): array
    {
        $clones = [];

        foreach (Role::whereNull('society_id')->with('permissions')->get() as $template) {
            $copy = Role::create([
                'society_id' => $society->id,
                'name' => $template->name,
                'slug' => $template->slug,
                'is_system' => true,
            ]);
            $copy->permissions()->sync($template->permissions->pluck('id'));
            $clones[$template->slug] = $copy;
        }

        return $clones;
    }

    /**
     * Builds the 4-stage chain from blueprint §7.1: Society Admin (recommend,
     * permission-based since admin isn't a "role" in the roles table) ->
     * Treasurer (recommend, role-based) -> Approver (approve, role-based) ->
     * Treasurer (disburse, permission-based so any holder of loans.disburse
     * can do it, not necessarily the same treasurer who recommended).
     */
    private function createDefaultWorkflow(Society $society, array $clonedRoles): void
    {
        $workflow = ApprovalWorkflow::create([
            'society_id' => $society->id,
            'name' => 'Standard Loan Approval',
            'is_default' => true,
        ]);

        $workflow->stages()->create([
            'sequence' => 1,
            'name' => 'Society Admin Review',
            'action_type' => 'recommend',
            'assignment_type' => 'permission',
            'assignment_permission_key' => 'loans.recommend',
            'notes_required' => true,
        ]);

        $workflow->stages()->create([
            'sequence' => 2,
            'name' => 'Treasurer Review',
            'action_type' => 'recommend',
            'assignment_type' => 'role',
            'assignment_role_id' => $clonedRoles['treasurer']->id,
            'notes_required' => true,
        ]);

        $workflow->stages()->create([
            'sequence' => 3,
            'name' => 'Final Approval',
            'action_type' => 'approve',
            'assignment_type' => 'role',
            'assignment_role_id' => $clonedRoles['approver']->id,
            'notes_required' => false,
        ]);

        $workflow->stages()->create([
            'sequence' => 4,
            'name' => 'Disbursement',
            'action_type' => 'disburse',
            'assignment_type' => 'permission',
            'assignment_permission_key' => 'loans.disburse',
            'notes_required' => false,
        ]);
    }
}
