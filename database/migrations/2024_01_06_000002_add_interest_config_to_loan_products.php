<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_tenure_months')->nullable();
            // [{ "from_months": 1, "to_months": 3, "rate_bp": 500 }, ...]
            // rate_bp is basis points: 500 = 5.00%
            $table->jsonb('interest_bands')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->dropColumn(['max_tenure_months', 'interest_bands']);
        });
    }
};