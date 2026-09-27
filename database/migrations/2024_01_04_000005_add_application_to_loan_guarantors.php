<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loan_guarantors', function (Blueprint $table) {
            $table->foreignId('loan_application_id')->nullable()->after('id')->constrained('loan_applications');
            $table->unsignedBigInteger('loan_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('loan_guarantors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_application_id');
        });
    }
};
