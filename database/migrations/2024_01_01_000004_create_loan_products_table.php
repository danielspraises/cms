<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->constrained('societies');
            $table->string('name');
            $table->enum('type', ['cash', 'sales']);
            $table->enum('interest_method', ['flat', 'reducing_balance'])->default('flat');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_products');
    }
};
