<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing societies: create the Society Admin role, give it to their society
 * admins, and assign the default chain's first step to that role and its
 * disbursement step to the Treasurer role, so nobody can take a step that
 * isn't theirs. New societies get all of this from provisioning.
 */
return new class extends Migration {
    private const ROLE_PERMISSIONS = ['loans.view', 'loans.recommend', 'members.view', 'ledger.view', 'comments.create'];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', self::ROLE_PERMISSIONS)->pluck('id');
        $now = now();

        foreach (DB::table('societies')->pluck('id') as $societyId) {
            $roleId = DB::table('roles')
                ->where('society_id', $societyId)->where('slug', 'society-admin')->value('id');

            if (!$roleId) {
                $roleId = DB::table('roles')->insertGetId([
                    'society_id' => $societyId,
                    'name' => 'Society Admin',
                    'slug' => 'society-admin',
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }

            $adminIds = DB::table('users')
                ->where('society_id', $societyId)->where('role', 'society_admin')->pluck('id');
            foreach ($adminIds as $userId) {
                DB::table('user_roles')->insertOrIgnore(['user_id' => $userId, 'role_id' => $roleId, 'assigned_at' => $now]);
            }

            $treasurerRoleId = DB::table('roles')
                ->where('society_id', $societyId)->where('slug', 'treasurer')->value('id');

            $workflowIds = DB::table('approval_workflows')
                ->where('society_id', $societyId)->where('is_default', true)->pluck('id');

            foreach ($workflowIds as $workflowId) {
                DB::table('approval_stages')
                    ->where('workflow_id', $workflowId)
                    ->where('sequence', 1)
                    ->where('action_type', 'recommend')
                    ->where('assignment_type', 'permission')
                    ->where('assignment_permission_key', 'loans.recommend')
                    ->update([
                        'assignment_type' => 'role',
                        'assignment_role_id' => $roleId,
                        'assignment_permission_key' => null,
                        'updated_at' => $now,
                    ]);

                if ($treasurerRoleId) {
                    DB::table('approval_stages')
                        ->where('workflow_id', $workflowId)
                        ->where('action_type', 'disburse')
                        ->where('assignment_type', 'permission')
                        ->where('assignment_permission_key', 'loans.disburse')
                        ->update([
                            'assignment_type' => 'role',
                            'assignment_role_id' => $treasurerRoleId,
                            'assignment_permission_key' => null,
                            'updated_at' => $now,
                        ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Data backfill: nothing safe to undo.
    }
};