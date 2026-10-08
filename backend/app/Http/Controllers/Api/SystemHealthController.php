<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Health of the platform's own dependencies (not of customer projects).
 * Error details are logged, never returned, to avoid leaking hostnames or DSNs.
 */
class SystemHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', fn () => DB::select('select 1')),
            'redis' => $this->check('redis', fn () => Redis::connection()->ping()),
            'agent' => $this->check('agent', function () {
                Http::timeout(config('services.agent.timeout'))
                    ->acceptJson()
                    ->get(rtrim(config('services.agent.url'), '/').'/health')
                    ->throw();
            }),
        ];

        $healthy = ! in_array('down', array_column($checks, 'status'), true);

        return response()->json([
            'data' => [
                'status' => $healthy ? 'healthy' : 'degraded',
                'checks' => $checks,
            ],
        ]);
    }

    /**
     * @return array{status: string, latency_ms: int}
     */
    private function check(string $name, Closure $probe): array
    {
        $started = hrtime(true);

        try {
            $probe();
            $status = 'up';
        } catch (Throwable $e) {
            Log::warning("System health check failed: {$name}", ['exception' => $e::class, 'message' => $e->getMessage()]);
            $status = 'down';
        }

        return ['status' => $status, 'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000)];
    }
}
