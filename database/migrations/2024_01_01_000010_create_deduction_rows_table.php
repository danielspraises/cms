<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deduction_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('deduction_batches');
            $table->foreignId('member_id')->nullable()->constrained('users');
            $table->string('raw_member_uid'); // preserved even if it fails to resolve
            $table->bigInteger('amount_minor');

            $table->bigInteger('sales_minor')->default(0);
            $table->bigInteger('loan_interest_minor')->default(0);
            $table->bigInteger('loan_principal_minor')->default(0);
            $table->bigInteger('shares_minor')->default(0);
            $table->bigInteger('savings_minor')->default(0);

            $table->enum('status', ['pending', 'distributed', 'failed', 'unresolved_member'])
                ->default('pending');
            $table->string('error_message')->nullable();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_rows');
    }
};
