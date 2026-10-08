<?php

namespace App\Support\Git;

use App\Models\Repository;

/**
 * Branch rules for every write the platform makes to a repository:
 *
 *  - the default branch and long-lived branches (main, master, develop, production,
 *    staging, release/*, hotfix/*) are never written to directly;
 *  - branches created or committed to by the platform must use a work prefix
 *    (fix/, feature/, refactor/, security/, maintenance/) followed by a slug;
 *  - provider-protected branches are refused as well (checked by the caller).
 *
 * Changes reach protected branches only through a reviewed pull request.
 */
final class BranchPolicy
{
    public const WORK_PREFIXES = ['fix', 'feature', 'refactor', 'security', 'maintenance'];

    private const PROTECTED_NAMES = ['main', 'master', 'develop', 'development', 'production', 'staging', 'trunk'];

    private const PROTECTED_PREFIXES = ['release/', 'hotfix/'];

    /** Validation regex for a work branch: prefix + slug segments of [a-z0-9._-]. */
    public const WORK_BRANCH_PATTERN = '/^(fix|feature|refactor|security|maintenance)\/[a-z0-9][a-z0-9._-]*(\/[a-z0-9][a-z0-9._-]*)*$/';

    /**
     * Why $branch may not be written to in $repository, or null when it may.
     */
    public static function writeViolation(Repository $repository, string $branch): ?string
    {
        if (self::isProtected($repository, $branch)) {
            return "Branch {$branch} is protected. Changes must go through a pull request from a work branch.";
        }

        if (! self::isWorkBranch($branch)) {
            return 'Branch names must start with one of '.implode(', ', array_map(fn ($p) => "{$p}/", self::WORK_PREFIXES))
                .' followed by a lowercase slug, e.g. fix/products-api-500.';
        }

        if (str_contains($branch, '..') || str_ends_with($branch, '.lock') || str_ends_with($branch, '.') || strlen($branch) > 100) {
            return 'This is not a valid branch name.';
        }

        return null;
    }

    public static function isProtected(Repository $repository, string $branch): bool
    {
        $name = strtolower($branch);

        if ($name === strtolower($repository->default_branch) || in_array($name, self::PROTECTED_NAMES, true)) {
            return true;
        }

        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function isWorkBranch(string $branch): bool
    {
        return (bool) preg_match(self::WORK_BRANCH_PATTERN, $branch);
    }
}
