<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deduction_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->constrained('societies');
            $table->foreignId('member_id')->constrained('users');
            $table->enum('channel_type', ['csv_upload', 'bank_debit', 'card_debit']);

            // Admin must explicitly flip this on — nothing auto-deducts by default.
            $table->boolean('is_active')->default(false);

            $table->enum('frequency', ['monthly', 'weekly', 'custom'])->default('monthly');
            $table->unsignedTinyInteger('day_of_period')->nullable(); // e.g. 25th of month
            $table->bigInteger('fixed_amount_minor')->nullable();     // null = amount comes from CSV row

            $table->string('provider')->nullable();           // 'paystack', etc.
            $table->string('provider_mandate_ref')->nullable(); // authorization_code / mandate id
            $table->timestamp('provider_authorized_at')->nullable();

            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users');
            $table->timestamp('deactivated_at')->nullable();

            $table->timestamps();

            $table->unique(['member_id', 'channel_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_channels');
    }
};
