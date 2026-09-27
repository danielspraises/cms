<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();   // e.g. 'ledger.view', 'ledger.edit'
            $table->string('description');
            $table->string('group');           // e.g. 'ledger', 'loans', 'members', 'billing'
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
