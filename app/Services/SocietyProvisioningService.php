<?php

namespace App\Services;

use App\Models\ApprovalWorkflow;
use App\Models\Role;
use App\Models\Society;
use App\Models\User;

/**
 * Everything a brand-new society needs seeded at creation time.
 */
class SocietyProvisioningService
{
    public function provision(Society $society): void
    {
        $clonedRoles = $this->cloneSystemRoleTemplates($society);
        $this->createDefaultWorkflow($society, $clonedRoles);
    }

    /**
     * Gives a society admin the Society Admin role, which owns the first review
     * step, the settlement step and the cross-check. Call for every society admin created.
     */
    public function grantSocietyAdminRole(Society $society, User $user): void
    {
        $role = Role::where('society_id', $society->id)->where('slug', 'society-admin')->first();

        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now()]]);
        }
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
     * The default chain. Every step belongs to ONE role, so nobody can take a
     * step that is someone else's:
     *   1 Society Admin Review      recommend  Society Admin
     *   2 Treasurer Review          recommend  Treasurer
     *   3 Final Approval            approve    Approver
     *   4 Settlement                prepare    Society Admin
     *   5 Disbursement              disburse   Treasurer
     *   6 Cross-check and posting   confirm    Society Admin
     */
    private function createDefaultWorkflow(Society $society, array $clonedRoles): void
    {
        foreach (['society-admin', 'treasurer', 'approver'] as $slug) {
            if (!isset($clonedRoles[$slug])) {
                throw new \RuntimeException("The '{$slug}' role template is missing. Run: php artisan db:seed --class=RoleSeeder");
            }
        }

        $workflow = ApprovalWorkflow::create([
            'society_id' => $society->id,
            'name' => 'Standard Loan Approval',
            'is_default' => true,
        ]);

        $steps = [
            ['Society Admin Review', 'recommend', 'society-admin', true],
            ['Treasurer Review', 'recommend', 'treasurer', true],
            ['Final Approval', 'approve', 'approver', false],
            ['Settlement', 'prepare', 'society-admin', false],
            ['Disbursement', 'disburse', 'treasurer', false],
            ['Cross-check and posting', 'confirm', 'society-admin', false],
        ];

        foreach ($steps as $index => [$name, $type, $roleSlug, $notesRequired]) {
            $workflow->stages()->create([
                'sequence' => $index + 1,
                'name' => $name,
                'action_type' => $type,
                'assignment_type' => 'role',
                'assignment_role_id' => $clonedRoles[$roleSlug]->id,
                'notes_required' => $notesRequired,
            ]);
        }
    }
}