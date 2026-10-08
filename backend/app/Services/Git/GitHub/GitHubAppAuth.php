<?php

namespace App\Services\Git\GitHub;

use App\Services\Git\GitProviderException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Authentication as the platform's GitHub App:
 *  - app JWT (RS256, 9 minutes) for app-level endpoints,
 *  - installation access tokens (cached encrypted until shortly before expiry),
 *  - the OAuth code exchange used to prove the installing user can access an installation.
 */
class GitHubAppAuth
{
    private const TOKEN_CACHE_PREFIX = 'github:installation-token:';

    public function __construct(
        private readonly Cache $cache,
        private readonly Encrypter $encrypter,
    ) {}

    /**
     * The App flow needs every credential, including OAuth (used to verify the
     * installing user) and the webhook secret (used to verify deliveries).
     */
    public function configured(): bool
    {
        $app = config('services.github.app');

        return filled($app['id']) && filled($app['slug']) && filled($app['client_id'])
            && filled($app['client_secret']) && filled($app['webhook_secret'])
            && (filled($app['private_key']) || filled($app['private_key_path']));
    }

    public function slug(): ?string
    {
        return config('services.github.app.slug');
    }

    public function installUrl(string $state): string
    {
        return config('services.github.web_url').'/apps/'.rawurlencode((string) $this->slug())
            .'/installations/new?'.http_build_query(['state' => $state]);
    }

    public function webhookSecret(): ?string
    {
        return config('services.github.app.webhook_secret');
    }

    /**
     * @throws GitProviderException
     */
    public function jwt(): string
    {
        $key = openssl_pkey_get_private($this->privateKey());

        if ($key === false) {
            throw new GitProviderException('The GitHub App private key is invalid.');
        }

        $now = time();
        $segments = [
            self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url(json_encode([
                'iat' => $now - 60, // tolerate clock drift
                'exp' => $now + 540,
                'iss' => (string) config('services.github.app.id'),
            ])),
        ];

        openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256);
        $segments[] = self::base64Url($signature);

        return implode('.', $segments);
    }

    /**
     * A client authenticated as the App itself (not an installation).
     */
    public function appClient(): GitHubClient
    {
        return new GitHubClient($this->jwt());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws GitProviderException
     */
    public function installation(int $installationId): array
    {
        return $this->appClient()->get("app/installations/{$installationId}");
    }

    /**
     * @throws GitProviderException
     */
    public function installationToken(int $installationId): string
    {
        $cached = $this->cache->get(self::TOKEN_CACHE_PREFIX.$installationId);

        if (is_string($cached)) {
            return $this->encrypter->decryptString($cached);
        }

        $response = $this->appClient()->post("app/installations/{$installationId}/access_tokens");
        $token = (string) ($response['token'] ?? '');

        if ($token === '') {
            throw new GitProviderException('GitHub did not return an installation token.', 502);
        }

        // Tokens live one hour; refresh five minutes early.
        $expiresAt = isset($response['expires_at']) ? Carbon::parse($response['expires_at'])->subMinutes(5) : now()->addMinutes(50);
        $this->cache->put(self::TOKEN_CACHE_PREFIX.$installationId, $this->encrypter->encryptString($token), $expiresAt);

        return $token;
    }

    public function forgetInstallationToken(int $installationId): void
    {
        $this->cache->forget(self::TOKEN_CACHE_PREFIX.$installationId);
    }

    /**
     * Exchange the OAuth code GitHub appends to the setup redirect for a user token.
     * The token is used once to check installation access and is never stored.
     *
     * @throws GitProviderException
     */
    public function userAccessToken(string $code): string
    {
        try {
            $response = Http::asForm()->accept('application/json')
                ->timeout(config('services.github.timeout', 15))
                ->post(config('services.github.web_url').'/login/oauth/access_token', [
                    'client_id' => config('services.github.app.client_id'),
                    'client_secret' => config('services.github.app.client_secret'),
                    'code' => $code,
                ]);
        } catch (ConnectionException $e) {
            throw new GitProviderException('GitHub could not be reached. Try again later.', 0, $e);
        }

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            throw new GitProviderException('GitHub did not accept the authorization code. Start the installation again.', 422);
        }

        return $token;
    }

    /**
     * Whether the user behind $userToken can access $installationId. This stops a
     * member from linking someone else's installation by guessing its id.
     *
     * @throws GitProviderException
     */
    public function userCanAccessInstallation(string $userToken, int $installationId): bool
    {
        $installations = (new GitHubClient($userToken))->paginate('user/installations', limit: 500, itemsKey: 'installations');

        foreach ($installations as $installation) {
            if ((int) ($installation['id'] ?? 0) === $installationId) {
                return true;
            }
        }

        return false;
    }

    private function privateKey(): string
    {
        $key = (string) config('services.github.app.private_key');

        if ($key === '' && filled($path = config('services.github.app.private_key_path')) && is_readable($path)) {
            $key = (string) file_get_contents($path);
        }

        if ($key === '') {
            throw new GitProviderException('The GitHub App private key is not configured.');
        }

        return str_replace('\n', "\n", $key);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
