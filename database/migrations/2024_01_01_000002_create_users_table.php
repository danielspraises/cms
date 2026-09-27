<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_id')->nullable()->constrained('societies');
            $table->enum('role', ['society_admin', 'member', 'platform_admin']);
            $table->string('member_uid')->nullable()->unique();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->rememberToken();
            $table->timestamps();

            $table->index('society_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
