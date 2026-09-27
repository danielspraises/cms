<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // "Standard Monthly", "Annual Discount"
            $table->enum('cadence', ['monthly', 'quarterly', 'biannual', 'annual', 'custom']);
            // Only meaningful when cadence = 'custom'; otherwise derived from cadence.
            $table->unsignedInteger('custom_cycle_months')->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('NGN');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_plans');
    }
};
