<?php

namespace App\Services\Audit;

use App\Enums\ActorType;
use App\Enums\AuditResult;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Single entry point for writing audit records. Metadata is redacted before it is
 * persisted so that secrets and credentials never reach the audit trail.
 */
class AuditLogger
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|secret|token|api[_-]?key|private[_-]?key|credential|authorization|cookie|^value$)/i';

    public function __construct(private readonly CurrentOrganization $tenant) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $target = null,
        array $metadata = [],
        AuditResult $result = AuditResult::Success,
        ?RiskLevel $risk = null,
        ?User $user = null,
        ?string $organizationId = null,
        ?string $projectId = null,
        ActorType $actorType = ActorType::User,
        ?string $agentId = null,
        ?string $tool = null,
        ?string $approvalId = null,
    ): AuditLog {
        $user ??= Auth::user();
        $request = request();

        return AuditLog::create([
            'organization_id' => $organizationId ?? $this->tenant->id() ?? $this->attributeOf($target, 'organization_id'),
            'project_id' => $projectId ?? $this->projectIdFor($target),
            'user_id' => $user?->getKey(),
            'actor_type' => $user || $actorType !== ActorType::User ? $actorType : ActorType::System,
            'agent_id' => $agentId,
            'action' => $action,
            'tool' => $tool,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey() !== null ? (string) $target->getKey() : null,
            'risk_level' => $risk,
            'approval_id' => $approvalId,
            'result' => $result,
            'metadata' => $metadata === [] ? null : $this->redact($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function attributeOf(?Model $target, string $attribute): mixed
    {
        return $target?->getAttributes()[$attribute] ?? null;
    }

    private function projectIdFor(?Model $target): ?string
    {
        return match (true) {
            $target === null => null,
            $target instanceof Project => $target->getKey(),
            default => $this->attributeOf($target, 'project_id'),
        };
    }
}
