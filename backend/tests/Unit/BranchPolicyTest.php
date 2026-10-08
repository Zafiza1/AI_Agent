<?php

namespace Tests\Unit;

use App\Models\Repository;
use App\Support\Git\BranchPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BranchPolicyTest extends TestCase
{
    private function repository(string $defaultBranch = 'main'): Repository
    {
        return (new Repository)->forceFill(['default_branch' => $defaultBranch]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writableBranches(): array
    {
        return [
            'fix' => ['fix/products-api-500'],
            'feature' => ['feature/export-csv'],
            'refactor nested' => ['refactor/billing/invoices'],
            'security' => ['security/bump-guzzle'],
            'maintenance' => ['maintenance/weekly-deps.2026-10-08'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedBranches(): array
    {
        return [
            'default branch' => ['main'],
            'master' => ['master'],
            'production' => ['production'],
            'release' => ['release/1.2'],
            'hotfix' => ['hotfix/urgent'],
            'no prefix' => ['products-api'],
            'unknown prefix' => ['chore/cleanup'],
            'uppercase' => ['fix/Products'],
            'empty slug' => ['fix/'],
            'dot dot' => ['fix/a..b'],
            'lock suffix' => ['fix/thing.lock'],
            'trailing dot' => ['fix/thing.'],
            'space' => ['fix/with space'],
        ];
    }

    #[DataProvider('writableBranches')]
    public function test_work_branches_are_writable(string $branch): void
    {
        $this->assertNull(BranchPolicy::writeViolation($this->repository(), $branch));
    }

    #[DataProvider('refusedBranches')]
    public function test_protected_and_unconventional_branches_are_refused(string $branch): void
    {
        $this->assertNotNull(BranchPolicy::writeViolation($this->repository(), $branch));
    }

    public function test_a_custom_default_branch_is_protected(): void
    {
        $repository = $this->repository('fix/legacy-default');

        $this->assertTrue(BranchPolicy::isProtected($repository, 'fix/legacy-default'));
        $this->assertNotNull(BranchPolicy::writeViolation($repository, 'fix/legacy-default'));
        $this->assertTrue(BranchPolicy::isProtected($repository, 'MAIN'));
    }
}
