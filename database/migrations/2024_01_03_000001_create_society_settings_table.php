<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('society_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->unique()->constrained('societies');

            $table->jsonb('deduction_priority_order')->default(
                json_encode(['sales', 'loan_interest', 'loan_principal', 'shares', 'savings'])
            );
            $table->enum('due_calculation_model', ['total_due', 'monthly_due'])->default('total_due');

            $table->unsignedInteger('default_grace_months')->default(0);
            $table->string('default_interest_method')->default('flat');

            $table->boolean('late_fee_enabled')->default(false);
            $table->enum('late_fee_type', ['flat', 'percent_of_due'])->nullable();
            $table->bigInteger('late_fee_amount_minor')->default(0);
            $table->unsignedInteger('late_fee_percent_bp')->default(0);

            $table->jsonb('features')->default(json_encode([
                'sales_loans' => true,
                'share_deductions' => true,
                'bank_auto_debit' => false,
                'csv_deductions' => true,
                'loan_guarantors_required' => true,
                'member_self_service_payment' => true,
            ]));

            $table->jsonb('terminology')->default('{}');
            $table->char('currency', 3)->default('NGN');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('society_settings');
    }
};
