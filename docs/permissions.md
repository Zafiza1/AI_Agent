# Permissions

## Roles

Roles are per organization membership and defined in code
([Role.php](../backend/app/Support/Rbac/Role.php)). Custom roles are a later extension.

| Permission | Owner | Admin | Maintainer | Viewer |
|---|:-:|:-:|:-:|:-:|
| `organization.view` | ✓ | ✓ | ✓ | ✓ |
| `organization.update` | ✓ | ✓ | | |
| `organization.delete` | ✓ | | | |
| `members.view` | ✓ | ✓ | ✓ | ✓ |
| `members.manage` | ✓ | ✓ | | |
| `projects.view` | ✓ | ✓ | ✓ | ✓ |
| `projects.create` | ✓ | ✓ | ✓ | |
| `projects.update` | ✓ | ✓ | ✓ | |
| `projects.delete` | ✓ | ✓ | | |
| `environments.manage` | ✓ | ✓ | ✓ | |
| `environments.manage_protected` | ✓ | ✓ | | |
| `secrets.manage` | ✓ | ✓ | | |
| `infrastructure.manage` | ✓ | ✓ | ✓ | |
| `integrations.manage` | ✓ | ✓ | | |
| `repositories.write` | ✓ | ✓ | ✓ | |
| `audit.view` | ✓ | ✓ | | |

The matrix is also served by `GET /api/meta` and rendered in **Settings**.

## Additional rules

* Only owners can grant the owner role or modify/remove an owner.
* An organization always keeps at least one owner (demotion/removal of the last owner fails with 422).
* Any member may leave an organization.
* Changing a **protected** environment, its variables, or any environment's `is_protected` /
  `requires_approval` flags requires `environments.manage_protected`.
* Writing environment variables requires both `secrets.manage` and the environment's manage permission.
* `integrations.manage` covers adding, verifying and removing git connections (GitHub App
  installations, access tokens). Any member may list connections; credentials are never returned.
* Connecting, syncing or disconnecting a repository requires `projects.update`.
* `repositories.write` (branches, commits, pull requests) is further limited by the
  [branch policy](github.md#branch-policy): never the default or long-lived branches, only
  `fix/`, `feature/`, `refactor/`, `security/`, `maintenance/` work branches.

## Enforcement

1. `ResolveOrganization` middleware loads the caller's membership for the `X-Organization-Id` header.
2. Each permission is registered as a Gate ability backed by that membership
   ([AppServiceProvider](../backend/app/Providers/AppServiceProvider.php)).
3. Controllers call `Gate::authorize(Permission::X->value)` before acting.

## Agent permissions (Phase 3)

Agents will not inherit a user's role. The policy engine evaluates
`(organization, user, agent, project, environment, tool, risk)` and returns `ALLOW`, `DENY` or
`REQUIRE_APPROVAL`; see [security.md](security.md#risk-levels-and-policy).
