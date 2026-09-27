<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('account_type', [
                'savings', 'shares', 'loan_principal', 'loan_interest', 'sales_loan',
            ]);
            // Integer minor units (kobo). Never float.
            $table->bigInteger('balance_minor')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'account_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
