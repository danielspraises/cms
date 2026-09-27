<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes a gap in the initial approval-actions design: segregation of duty
 * (blueprint §7.3) must reset when an application is resubmitted after
 * 'changes_requested' (§7.4), since that's a fresh review of changed
 * material. Without a cycle marker, actorsWhoHaveActed() would keep
 * blocking the same reviewers forever, since it's still the same
 * application row and the same action rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->unsignedInteger('review_cycle')->default(1);
        });

        Schema::table('loan_approval_actions', function (Blueprint $table) {
            $table->unsignedInteger('review_cycle')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', fn (Blueprint $t) => $t->dropColumn('review_cycle'));
        Schema::table('loan_approval_actions', fn (Blueprint $t) => $t->dropColumn('review_cycle'));
    }
};
