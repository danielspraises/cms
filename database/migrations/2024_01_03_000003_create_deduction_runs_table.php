<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One row per attempted charge on a bank/card channel — separate from
        // deduction_rows (which is CSV-specific) because a direct-debit
        // attempt can fail for reasons a CSV row never does (card declined,
        // mandate revoked, insufficient funds) and that needs its own status
        // lifecycle and retry tracking.
        Schema::create('deduction_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('deduction_channels');
            $table->date('scheduled_for');
            $table->bigInteger('amount_minor');
            $table->enum('status', ['pending', 'success', 'failed', 'retrying'])->default('pending');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->string('provider_ref')->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $table->timestamps();

            $table->unique(['channel_id', 'scheduled_for']); // one attempt-series per schedule date
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_runs');
    }
};
