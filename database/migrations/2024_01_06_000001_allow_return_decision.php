<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'return' as a recordable decision, so a reviewer can send an
 * application back one step instead of only approving or rejecting it.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->replaceDecisionConstraint(
            "'guarantor_accept','guarantor_decline','recommend','reject','approve','disburse','return'"
        );
    }

    public function down(): void
    {
        $this->replaceDecisionConstraint(
            "'guarantor_accept','guarantor_decline','recommend','reject','approve','disburse'"
        );
    }

    private function replaceDecisionConstraint(string $allowed): void
    {
        $existing = DB::selectOne(
            "select conname from pg_constraint
             where conrelid = to_regclass('loan_approval_actions')
               and contype = 'c'
               and pg_get_constraintdef(oid) like '%decision%'"
        );

        if ($existing) {
            DB::statement('ALTER TABLE loan_approval_actions DROP CONSTRAINT "' . $existing->conname . '"');
        }

        DB::statement(
            "ALTER TABLE loan_approval_actions ADD CONSTRAINT loan_approval_actions_decision_check CHECK (decision IN ($allowed))"
        );
    }
};