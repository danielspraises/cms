<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->constrained('societies');
            $table->foreignId('member_id')->constrained('users');
            $table->foreignId('loan_product_id')->nullable()->constrained('loan_products');

            $table->bigInteger('principal_disbursed_minor')->default(0);
            $table->bigInteger('principal_repaid_minor')->default(0);
            $table->bigInteger('interest_disbursed_minor')->default(0);
            $table->bigInteger('interest_repaid_minor')->default(0);
            $table->bigInteger('sales_disbursed_minor')->default(0);
            $table->bigInteger('sales_repaid_minor')->default(0);

            $table->unsignedInteger('tenure_months');
            $table->unsignedInteger('grace_months')->default(0);
            $table->date('disbursement_date')->nullable();
            $table->unsignedTinyInteger('guarantors_required')->default(0);

            $table->enum('status', [
                'pending_approval', 'pending_guarantors', 'active', 'terminated', 'rejected',
            ])->default('pending_approval');

            // Carries legacy TXN ids across during migration from the WP system.
            $table->string('external_transaction_id')->nullable()->index();

            $table->timestamps();

            $table->index(['society_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
