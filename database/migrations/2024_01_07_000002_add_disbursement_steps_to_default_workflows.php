<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing societies: add Settlement and Cross-check and posting to the default
 * chain around the existing Disbursement step, both owned by the Society Admin
 * role. Applications waiting at Disbursement move back to Settlement. New
 * societies get these steps from provisioning.
 */
return new class extends Migration {
    public function up(): void
    {
        $now = now();

        foreach (DB::table('approval_workflows')->where('is_default', true)->get() as $workflow) {
            $alreadyDone = DB::table('approval_stages')
                ->where('workflow_id', $workflow->id)->where('action_type', 'prepare')->exists();
            $disburse = DB::table('approval_stages')
                ->where('workflow_id', $workflow->id)->where('action_type', 'disburse')->first();
            $societyAdminRoleId = DB::table('roles')
                ->where('society_id', $workflow->society_id)->where('slug', 'society-admin')->value('id');

            if ($alreadyDone || !$disburse || !$societyAdminRoleId) {
                continue;
            }

            $at = $disburse->sequence;

            // Make room, highest sequence first so the unique (workflow, sequence) rule never collides.
            $later = DB::table('approval_stages')
                ->where('workflow_id', $workflow->id)->where('sequence', '>', $at)
                ->orderByDesc('sequence')->get();
            foreach ($later as $stage) {
                DB::table('approval_stages')->where('id', $stage->id)
                    ->update(['sequence' => $stage->sequence + 2, 'updated_at' => $now]);
            }

            DB::table('approval_stages')->where('id', $disburse->id)
                ->update(['sequence' => $at + 1, 'updated_at' => $now]);

            $settlementId = DB::table('approval_stages')->insertGetId([
                'workflow_id' => $workflow->id,
                'sequence' => $at,
                'name' => 'Settlement',
                'action_type' => 'prepare',
                'assignment_type' => 'role',
                'assignment_role_id' => $societyAdminRoleId,
                'assignment_permission_key' => null,
                'notes_required' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('approval_stages')->insert([
                'workflow_id' => $workflow->id,
                'sequence' => $at + 2,
                'name' => 'Cross-check and posting',
                'action_type' => 'confirm',
                'assignment_type' => 'role',
                'assignment_role_id' => $societyAdminRoleId,
                'assignment_permission_key' => null,
                'notes_required' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('loan_applications')
                ->where('workflow_id', $workflow->id)
                ->where('status', 'approved')
                ->where('current_stage_id', $disburse->id)
                ->update(['current_stage_id' => $settlementId, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // Data backfill: nothing safe to undo.
    }
};