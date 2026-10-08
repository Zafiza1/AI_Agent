<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_are_immutable(): void
    {
        $log = app(AuditLogger::class)->record('test.action', organizationId: null);

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_audit_logs_cannot_be_deleted(): void
    {
        $log = app(AuditLogger::class)->record('test.action');

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_sensitive_metadata_is_redacted(): void
    {
        $log = app(AuditLogger::class)->record('test.action', metadata: [
            'password' => 'hunter2',
            'nested' => ['api_key' => 'sk-123', 'GITHUB_TOKEN' => 'ghp_x', 'safe' => 'ok'],
            'value' => 'plaintext',
            'key' => 'DB_PASSWORD',
        ]);

        $this->assertSame('[REDACTED]', $log->metadata['password']);
        $this->assertSame('[REDACTED]', $log->metadata['nested']['api_key']);
        $this->assertSame('[REDACTED]', $log->metadata['nested']['GITHUB_TOKEN']);
        $this->assertSame('ok', $log->metadata['nested']['safe']);
        $this->assertSame('[REDACTED]', $log->metadata['value']);
        $this->assertSame('DB_PASSWORD', $log->metadata['key']);
    }

    public function test_viewers_cannot_read_audit_logs_but_admins_can_filter_them(): void
    {
        [$viewer, $organization] = $this->memberOf(Role::Viewer);
        [$admin] = $this->memberOf(Role::Admin, $organization);
        $this->memberOf(Role::Owner, $organization);
        $project = $this->createProject($organization);

        $this->actingInOrganization($viewer, $organization)->getJson('/api/audit-logs')->assertForbidden();

        $this->actingInOrganization($admin, $organization)->patchJson("/api/projects/{$project->id}", ['description' => 'Updated']);

        $this->actingInOrganization($admin, $organization)
            ->getJson('/api/audit-logs?action=project.&project_id='.$project->id)
            ->assertOk()
            ->assertJsonPath('data.0.action', 'project.updated')
            ->assertJsonPath('data.0.user.id', $admin->id)
            ->assertJsonPath('data.0.actor_type', 'user');
    }

    public function test_action_filter_is_a_literal_prefix_match(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $logger = app(AuditLogger::class);
        $logger->record('environment.variables_updated', organizationId: $organization->id);
        $logger->record('environment.variablesXupdated', organizationId: $organization->id);
        $logger->record('environment.updated', organizationId: $organization->id);

        $this->actingInOrganization($owner, $organization)
            ->getJson('/api/audit-logs?action=environment.variables_')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'environment.variables_updated');
    }

    public function test_audit_entries_record_request_context(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);

        $this->actingInOrganization($owner, $organization)
            ->withHeader('User-Agent', 'phpunit')
            ->postJson('/api/projects', ['name' => 'Traced'])
            ->assertCreated();

        $log = AuditLog::where('action', 'project.created')->sole();
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame('phpunit', $log->user_agent);
        $this->assertNotNull($log->ip_address);
    }
}
