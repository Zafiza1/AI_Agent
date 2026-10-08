<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EnvironmentController;
use App\Http\Controllers\Api\EnvironmentVariableController;
use App\Http\Controllers\Api\Infrastructure\DatabaseController;
use App\Http\Controllers\Api\Infrastructure\ServerController;
use App\Http\Controllers\Api\Infrastructure\ServiceController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RepositoryController;
use App\Http\Controllers\Api\SystemHealthController;
use Illuminate\Support\Facades\Route;

// Malformed ids fail routing with 404 instead of reaching PostgreSQL's uuid parser.
foreach (['project', 'repository', 'environment', 'variable', 'member'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

/*
| Public
*/
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

/*
| Authenticated, no organization context
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::get('meta', MetaController::class);
    Route::get('system/health', SystemHealthController::class);

    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::post('organizations', [OrganizationController::class, 'store']);
});

/*
| Authenticated and scoped to the organization in the X-Organization-Id header
*/
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('organization', [OrganizationController::class, 'current']);
    Route::patch('organization', [OrganizationController::class, 'update']);
    Route::delete('organization', [OrganizationController::class, 'destroy']);

    Route::get('organization/members', [MemberController::class, 'index']);
    Route::post('organization/members', [MemberController::class, 'store']);
    Route::patch('organization/members/{member}', [MemberController::class, 'update']);
    Route::delete('organization/members/{member}', [MemberController::class, 'destroy']);

    Route::get('dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('audit-logs', [AuditLogController::class, 'index']);

    Route::apiResource('projects', ProjectController::class);

    Route::get('projects/{project}/repositories', [RepositoryController::class, 'index']);
    Route::post('projects/{project}/repositories', [RepositoryController::class, 'store']);
    Route::patch('repositories/{repository}', [RepositoryController::class, 'update']);
    Route::delete('repositories/{repository}', [RepositoryController::class, 'destroy']);

    Route::get('projects/{project}/environments', [EnvironmentController::class, 'index']);
    Route::post('projects/{project}/environments', [EnvironmentController::class, 'store']);
    Route::get('environments/{environment}', [EnvironmentController::class, 'show']);
    Route::patch('environments/{environment}', [EnvironmentController::class, 'update']);
    Route::delete('environments/{environment}', [EnvironmentController::class, 'destroy']);

    Route::get('environments/{environment}/variables', [EnvironmentVariableController::class, 'index']);
    Route::put('environments/{environment}/variables', [EnvironmentVariableController::class, 'upsert']);
    Route::delete('environments/{environment}/variables/{variable}', [EnvironmentVariableController::class, 'destroy']);

    foreach (['servers' => ServerController::class, 'databases' => DatabaseController::class, 'services' => ServiceController::class] as $uri => $controller) {
        Route::get("projects/{project}/{$uri}", [$controller, 'index']);
        Route::post("projects/{project}/{$uri}", [$controller, 'store']);
        Route::get("{$uri}/{id}", [$controller, 'show'])->whereUuid('id');
        Route::patch("{$uri}/{id}", [$controller, 'update'])->whereUuid('id');
        Route::delete("{$uri}/{id}", [$controller, 'destroy'])->whereUuid('id');
    }
});
