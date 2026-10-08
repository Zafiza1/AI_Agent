<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditResult;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $user = User::create($data);
        $this->audit->record('auth.registered', $user, user: $user);

        return response()->json($this->tokenPayload($user), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->audit->record('auth.login_failed', metadata: ['email' => $data['email']], result: AuditResult::Failure);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $this->audit->record('auth.logged_in', $user, user: $user);

        return response()->json($this->tokenPayload($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();
        $this->audit->record('auth.logged_out', $user);

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user),
            'organizations' => OrganizationResource::collection($user->organizations()->orderBy('name')->get()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenPayload(User $user): array
    {
        return [
            'token' => $user->createToken('dashboard')->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
            'organizations' => OrganizationResource::collection($user->organizations()->orderBy('name')->get()),
        ];
    }
}
