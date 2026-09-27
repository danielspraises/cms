<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deduction_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->constrained('societies');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('filename');
            $table->string('file_hash', 64);
            $table->date('deduction_date');
            $table->enum('status', ['pending_review', 'distributed', 'deleted'])->default('pending_review');
            $table->timestamp('distributed_at')->nullable();
            $table->timestamps();

            // Idempotency guard at the DB layer, not just app-level checks.
            $table->unique(['society_id', 'deduction_date', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_batches');
    }
};
