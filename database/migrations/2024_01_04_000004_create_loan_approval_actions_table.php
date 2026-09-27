<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One row per action taken anywhere in the chain — this IS the
        // visible "chain of custody" rendered on a loan application (§7.7),
        // separate from the general audit_log (which also gets an entry,
        // but doesn't carry the notes/decision structure a reviewer needs).
        Schema::create('loan_approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_application_id')->constrained('loan_applications')->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('approval_stages'); // null for guarantor actions
            $table->foreignId('actor_id')->constrained('users');
            $table->enum('decision', [
                'guarantor_accept', 'guarantor_decline',
                'recommend', 'reject', 'approve', 'disburse',
            ]);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('loan_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_approval_actions');
    }
};
