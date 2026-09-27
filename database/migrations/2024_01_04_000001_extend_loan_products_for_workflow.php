<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->boolean('requires_guarantors')->default(true);
            $table->unsignedTinyInteger('guarantors_required')->default(2);
            $table->enum('rejection_mode', ['hard_reject', 'return_for_changes'])->default('hard_reject');
            $table->foreignId('approval_workflow_id')->nullable()->constrained('approval_workflows');
            // ^ nullable FK to a table created in the next migration — Laravel
            // resolves this fine since migrations run in filename order and
            // the constraint is only enforced at the DB level once both exist.
        });
    }

    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_workflow_id');
            $table->dropColumn(['requires_guarantors', 'guarantors_required', 'rejection_mode']);
        });
    }
};
