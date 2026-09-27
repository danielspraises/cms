<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §9.3 — the entitlement ceiling. Holds only the GATED feature
 * keys currently granted to this society by the platform admin; core
 * (ungated) features are always available and controlled purely by the
 * society admin's own society_settings.features toggles (§1), unaffected
 * by this column.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('society_settings', function (Blueprint $table) {
            $table->jsonb('granted_features')->default('[]');
            $table->foreignId('features_updated_by')->nullable()->constrained('users');
            $table->timestamp('features_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('society_settings', function (Blueprint $table) {
            $table->dropColumn(['granted_features', 'features_updated_by', 'features_updated_at']);
        });
    }
};
