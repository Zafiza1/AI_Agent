<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('hostname')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('provider')->nullable();
            $table->string('os')->nullable();
            $table->string('connection_type', 32)->default('none');
            $table->string('status', 32)->default('unknown');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'project_id']);
        });

        Schema::create('databases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('engine', 32);
            $table->string('version')->nullable();
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('database_name')->nullable();
            $table->string('status', 32)->default('unknown');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'project_id']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('runtime', 32)->default('docker');
            $table->string('container_name')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('health_check_url')->nullable();
            $table->string('status', 32)->default('unknown');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
        Schema::dropIfExists('databases');
        Schema::dropIfExists('servers');
    }
};
