<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('framework')->nullable();
            $table->string('language')->nullable();
            $table->string('database_type')->nullable();
            $table->string('deployment_type')->nullable();
            $table->string('status', 32)->default('onboarding');
            $table->jsonb('ai_context')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('repositories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('url');
            $table->string('full_name')->nullable();
            $table->string('default_branch')->default('main');
            $table->boolean('is_primary')->default(false);
            $table->string('connection_status', 32)->default('not_connected');
            $table->string('external_id')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'url']);
            $table->index(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('projects');
    }
};
