<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('approval_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('approval_workflows')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence'); // 1, 2, 3... order of review
            $table->string('name');                  // "Society Admin Review", "Treasurer Review"...
            $table->enum('action_type', ['recommend', 'approve', 'disburse']);

            $table->enum('assignment_type', ['role', 'permission']);
            $table->foreignId('assignment_role_id')->nullable()->constrained('roles');
            $table->string('assignment_permission_key')->nullable();

            $table->boolean('notes_required')->default(false);
            $table->timestamps();

            $table->unique(['workflow_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_stages');
    }
};
