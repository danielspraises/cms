<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // null society_id = system template (e.g. the base "Auditor" role
            // definition); society admins can also create their own custom
            // roles scoped to just their society.
            $table->foreignId('society_id')->nullable()->constrained('societies');
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_system')->default(false); // system roles can't be deleted
            $table->timestamps();

            $table->unique(['society_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
