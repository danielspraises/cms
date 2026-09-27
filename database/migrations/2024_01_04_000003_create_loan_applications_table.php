<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loan_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->constrained('societies');
            $table->foreignId('member_id')->constrained('users');
            $table->foreignId('loan_product_id')->constrained('loan_products');
            $table->foreignId('workflow_id')->constrained('approval_workflows');

            $table->bigInteger('requested_principal_minor');
            $table->unsignedInteger('requested_tenure_months');
            $table->text('purpose')->nullable();

            $table->enum('status', [
                'pending_guarantors',   // waiting on guarantor acceptance, hasn't entered the chain yet
                'in_review',            // actively moving through approval stages
                'changes_requested',    // rejected under return_for_changes mode, waiting on applicant
                'rejected',             // hard-rejected or resubmission not allowed
                'approved',             // cleared final approval stage, awaiting disbursement
                'disbursed',            // fully complete — a `loans` row now exists
            ])->default('pending_guarantors');

            $table->foreignId('current_stage_id')->nullable()->constrained('approval_stages');
            $table->foreignId('loan_id')->nullable()->constrained('loans'); // set once disbursed
            $table->timestamps();

            $table->index(['society_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_applications');
    }
};
