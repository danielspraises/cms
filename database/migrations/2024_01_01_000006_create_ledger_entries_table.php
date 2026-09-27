<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->constrained('societies');
            $table->string('batch_id')->nullable()->index();
            $table->foreignId('loan_id')->nullable()->constrained('loans');
            $table->date('entry_date');
            $table->string('description');
            $table->enum('source', ['manual', 'auto_deduction', 'payment', 'csv_upload', 'system']);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('reversed_of_id')->nullable()->constrained('ledger_entries');
            $table->timestamps();

            $table->index(['society_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
