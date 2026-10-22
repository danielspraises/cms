<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema for the disbursement pipeline: ledger lines that name their loan, the
 * settlement sheet tables, and the new step and decision types.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ledger_lines', function (Blueprint $table) {
            $table->foreignId('loan_id')->nullable()->constrained('loans');
            $table->index('loan_id');
        });

        Schema::table('loans', function (Blueprint $table) {
            // Set on an old loan that was cleared from a newer loan's proceeds.
            $table->foreignId('settled_by_loan_id')->nullable()->constrained('loans');
        });

        Schema::create('loan_disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->constrained('societies');
            $table->foreignId('loan_application_id')->unique()->constrained('loan_applications');
            $table->foreignId('member_id')->constrained('users');

            $table->bigInteger('principal_minor');
            $table->unsignedInteger('tenure_months');
            $table->unsignedInteger('interest_rate_bp')->nullable();
            $table->bigInteger('interest_minor')->default(0);
            $table->bigInteger('settlement_total_minor')->default(0);
            $table->bigInteger('net_payout_minor');

            $table->enum('status', ['prepared', 'paid_out', 'posted'])->default('prepared');

            $table->foreignId('prepared_by')->nullable()->constrained('users');
            $table->timestamp('prepared_at')->nullable();

            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->timestamp('paid_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('loan_id')->nullable()->constrained('loans');
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');

            $table->timestamps();
            $table->index(['society_id', 'status']);
        });

        Schema::create('loan_disbursement_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disbursement_id')->constrained('loan_disbursements')->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained('loans');
            $table->enum('fund_type', ['principal', 'interest', 'sales']);
            $table->bigInteger('amount_minor');
            $table->bigInteger('balance_at_preparation_minor');
            $table->timestamps();

            $table->unique(['disbursement_id', 'loan_id', 'fund_type']);
        });

        $this->replaceCheck('approval_stages', 'action_type', "'recommend','approve','disburse','prepare','confirm'");
        $this->replaceCheck(
            'loan_approval_actions',
            'decision',
            "'guarantor_accept','guarantor_decline','recommend','reject','approve','disburse','return','prepare','confirm'"
        );
    }

    public function down(): void
    {
        $this->replaceCheck(
            'loan_approval_actions',
            'decision',
            "'guarantor_accept','guarantor_decline','recommend','reject','approve','disburse','return'"
        );
        $this->replaceCheck('approval_stages', 'action_type', "'recommend','approve','disburse'");

        Schema::dropIfExists('loan_disbursement_settlements');
        Schema::dropIfExists('loan_disbursements');

        Schema::table('loans', fn (Blueprint $table) => $table->dropConstrainedForeignId('settled_by_loan_id'));
        Schema::table('ledger_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('loan_id'));
    }

    private function replaceCheck(string $table, string $column, string $allowed): void
    {
        $existing = DB::selectOne(
            "select conname from pg_constraint
             where conrelid = to_regclass('{$table}')
               and contype = 'c'
               and pg_get_constraintdef(oid) like '%{$column}%'"
        );

        if ($existing) {
            DB::statement('ALTER TABLE ' . $table . ' DROP CONSTRAINT "' . $existing->conname . '"');
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$allowed}))");
    }
};