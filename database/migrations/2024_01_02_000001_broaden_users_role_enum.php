<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'staff' to the users.role enum. 'staff' covers any non-member,
 * non-admin internal user (auditors, loan officers, etc.) — their actual
 * capabilities come from the roles/permissions tables below, not from this
 * broad column. This column stays as a coarse category used for basic
 * routing/UI decisions ("is this a member-facing or admin-facing account").
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check
            CHECK (role IN ('platform_admin','society_admin','staff','member'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check
            CHECK (role IN ('platform_admin','society_admin','member'))");
    }
};
