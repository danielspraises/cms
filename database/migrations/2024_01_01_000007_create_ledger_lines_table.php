<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('ledger_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->enum('direction', ['debit', 'credit']);
            $table->bigInteger('amount_minor');
            $table->bigInteger('running_balance_minor')->nullable();
            $table->timestamps();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_lines');
    }
};
