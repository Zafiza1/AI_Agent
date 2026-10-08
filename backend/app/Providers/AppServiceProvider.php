<?php

namespace App\Providers;

use App\Contracts\SecretStore;
use App\Models\User;
use App\Services\Secrets\EncryptedDatabaseSecretStore;
use App\Support\Rbac\Permission;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: reset between requests and queued jobs so a tenant can never leak.
        $this->app->scoped(CurrentOrganization::class);

        $this->app->bind(SecretStore::class, EncryptedDatabaseSecretStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Every permission is a gate evaluated against the membership resolved by
        // ResolveOrganization. Outside a tenant context every permission is denied.
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user) => app(CurrentOrganization::class)->can($permission),
            );
        }

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(240)->by($request->user()?->getKey() ?: $request->ip());
        });
    }
}
