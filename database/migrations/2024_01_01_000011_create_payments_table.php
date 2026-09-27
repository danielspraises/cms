<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->constrained('societies');
            $table->foreignId('member_id')->constrained('users');
            $table->bigInteger('amount_minor');
            $table->string('provider')->default('paystack');
            $table->string('provider_ref')->unique();
            $table->string('webhook_event_id')->nullable()->unique();
            $table->enum('status', ['pending', 'paid', 'failed', 'allocated'])->default('pending');
            $table->json('intended_breakdown')->nullable();
            $table->foreignId('allocated_entry_id')->nullable()->constrained('ledger_entries');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('allocated_at')->nullable();
            $table->timestamps();

            $table->index(['society_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
