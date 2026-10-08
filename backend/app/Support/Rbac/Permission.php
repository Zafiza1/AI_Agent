<?php

namespace App\Support\Rbac;

/**
 * Every permission the control plane checks. Abilities are registered as gates
 * (see AppServiceProvider) so controllers call Gate::authorize(Permission::X->value).
 */
enum Permission: string
{
    case OrganizationView = 'organization.view';
    case OrganizationUpdate = 'organization.update';
    case OrganizationDelete = 'organization.delete';

    case MembersView = 'members.view';
    case MembersManage = 'members.manage';

    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdate = 'projects.update';
    case ProjectsDelete = 'projects.delete';

    case EnvironmentsManage = 'environments.manage';
    case EnvironmentsManageProtected = 'environments.manage_protected';

    case SecretsManage = 'secrets.manage';
    case InfrastructureManage = 'infrastructure.manage';

    case AuditView = 'audit.view';
}
