<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('societies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->char('currency', 3)->default('NGN');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('societies');
    }
};
