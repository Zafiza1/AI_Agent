<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. No foreign keys on purpose: audit records must outlive
        // the users, organizations and resources they describe.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('actor_type', 16);
            $table->string('agent_id')->nullable();
            $table->string('action', 100);
            $table->string('tool')->nullable();
            $table->string('target_type', 100)->nullable();
            $table->string('target_id')->nullable();
            $table->string('risk_level', 16)->nullable();
            $table->uuid('approval_id')->nullable();
            $table->string('result', 16);
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'project_id', 'created_at']);
            $table->index(['organization_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
