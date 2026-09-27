<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->constrained('societies');
            $table->foreignId('subscription_id')->constrained('society_subscriptions');
            $table->string('invoice_number')->unique();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('NGN');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->enum('status', ['pending', 'paid', 'overdue', 'void'])->default('pending');
            $table->string('provider_ref')->nullable(); // Paystack reference once paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['society_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
