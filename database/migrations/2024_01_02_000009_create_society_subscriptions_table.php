<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('society_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->unique()->constrained('societies');
            $table->foreignId('billing_plan_id')->nullable()->constrained('billing_plans');

            // Super admin can override the plan's cadence/amount per-society
            // ("the member half a year or depending on what the super admin
            // said") without needing a whole new plan for one-off deals.
            $table->unsignedInteger('cycle_months_override')->nullable();
            $table->bigInteger('amount_minor_override')->nullable();

            $table->enum('status', ['trial', 'active', 'past_due', 'cancelled'])->default('trial');
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('society_subscriptions');
    }
};
