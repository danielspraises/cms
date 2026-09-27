<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('brand_themes', function (Blueprint $table) {
            $table->id();
            $table->enum('scope', ['platform', 'society']);
            // null when scope = platform (there is exactly one platform theme row)
            $table->foreignId('society_id')->nullable()->unique()->constrained('societies');
            // { "primary": "#1e3a8a", "secondary": "#2563eb", "accent": "#10b981", ... }
            $table->jsonb('colors');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_themes');
    }
};
