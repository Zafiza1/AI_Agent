<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('url')->nullable();
            $table->string('branch')->nullable();
            $table->string('health_check_url')->nullable();
            $table->boolean('is_protected')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->jsonb('deployment_config')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::create('environment_variables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('environment_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            // Ciphertext written by the SecretStore; never returned for secret variables.
            $table->text('value');
            $table->boolean('is_secret')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['environment_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_variables');
        Schema::dropIfExists('environments');
    }
};
