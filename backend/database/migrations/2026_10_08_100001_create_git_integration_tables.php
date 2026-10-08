<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An organization's authorization to talk to a git provider.
        Schema::create('git_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('auth_type', 32);
            $table->string('name');
            $table->string('account_login')->nullable();
            $table->string('account_type', 32)->nullable();
            // GitHub App installation id. One installation can belong to one organization only.
            $table->unsignedBigInteger('installation_id')->nullable()->unique();
            // Ciphertext (encrypted cast) of the access token for token connections. Never serialized.
            $table->text('credentials')->nullable();
            $table->jsonb('scopes')->nullable();
            $table->string('status', 32)->default('active');
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'provider']);
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->foreignUuid('git_connection_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->boolean('is_private')->nullable()->after('default_branch');
            $table->string('connection_error', 500)->nullable()->after('connection_status');
            $table->string('webhook_status', 32)->default('not_configured')->after('external_id');
            $table->string('webhook_id')->nullable()->after('webhook_status');
            // Ciphertext (encrypted cast) of the per-repository webhook secret. Never serialized.
            $table->text('webhook_secret')->nullable()->after('webhook_id');
            $table->string('webhook_error', 500)->nullable()->after('webhook_secret');
            $table->string('last_commit_sha', 64)->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->index(['provider', 'external_id']);
        });

        Schema::create('pull_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('repository_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_id')->nullable();
            $table->unsignedInteger('number');
            $table->string('title', 500);
            $table->string('state', 16);
            $table->boolean('is_draft')->default(false);
            $table->string('head_branch');
            $table->string('head_sha', 64)->nullable();
            $table->string('base_branch');
            $table->string('url', 500)->nullable();
            $table->string('author_login')->nullable();
            $table->string('checks_status', 16)->nullable();
            // Opened through the platform API (by a user now, by agents from Phase 3).
            $table->boolean('opened_via_platform')->default(false);
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'number']);
            $table->index(['organization_id', 'state']);
            $table->index(['repository_id', 'head_branch']);
        });

        // Raw inbound webhooks: idempotency key, processing state and debugging trail.
        // organization_id is resolved after signature verification, so it is nullable.
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->foreignUuid('git_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('repository_id')->nullable()->constrained()->nullOnDelete();
            // The provider's repository id from the payload, to list App deliveries per repository.
            $table->string('external_repository_id')->nullable();
            $table->string('provider', 32);
            $table->string('delivery_id');
            $table->string('event', 64);
            $table->string('action', 64)->nullable();
            $table->string('status', 16);
            $table->jsonb('payload')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->unique(['provider', 'delivery_id']);
            $table->index(['organization_id', 'received_at']);
            $table->index(['repository_id', 'received_at']);
            $table->index(['git_connection_id', 'external_repository_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('pull_requests');

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropIndex(['provider', 'external_id']);
            $table->dropConstrainedForeignId('git_connection_id');
            $table->dropColumn([
                'is_private', 'connection_error', 'webhook_status', 'webhook_id', 'webhook_secret',
                'webhook_error', 'last_commit_sha', 'last_pushed_at', 'last_synced_at',
            ]);
        });

        Schema::dropIfExists('git_connections');
    }
};
