<?php

namespace App\Services\Secrets;

use App\Contracts\SecretStore;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;

class EncryptedDatabaseSecretStore implements SecretStore
{
    public function __construct(private readonly Encrypter $encrypter) {}

    public function put(Environment $environment, string $key, string $value, bool $isSecret, ?User $actor = null): EnvironmentVariable
    {
        $variable = $environment->variables()->firstOrNew(['key' => $key]);
        $variable->organization_id ??= $environment->organization_id;
        $variable->is_secret = $isSecret;
        $variable->value = $this->encrypter->encryptString($value);
        $variable->updated_by = $actor?->id;
        $variable->save();

        return $variable;
    }

    public function reveal(EnvironmentVariable $variable): string
    {
        return $this->encrypter->decryptString($variable->value);
    }

    public function forget(EnvironmentVariable $variable): void
    {
        $variable->delete();
    }
}
