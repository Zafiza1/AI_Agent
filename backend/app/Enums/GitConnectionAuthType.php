<?php

namespace App\Enums;

enum GitConnectionAuthType: string
{
    /** An installation of the platform's GitHub App (recommended). */
    case GitHubApp = 'github_app';

    /** A fine-grained or classic personal access token supplied by a user. */
    case PersonalAccessToken = 'personal_access_token';
}
