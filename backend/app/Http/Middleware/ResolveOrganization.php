<?php

namespace App\Http\Middleware;

use App\Models\OrganizationMember;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the organization the request acts in from the X-Organization-Id header.
 *
 * The header is untrusted input: it is only accepted when the authenticated user
 * holds a membership in that organization. Non-members get the same 403 whether or
 * not the organization exists, so the endpoint cannot be used to probe for tenants.
 */
class ResolveOrganization
{
    public const HEADER = 'X-Organization-Id';

    public function __construct(private readonly CurrentOrganization $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = (string) $request->header(self::HEADER, '');

        if (! Str::isUuid($organizationId)) {
            return response()->json([
                'message' => 'The '.self::HEADER.' header is required and must be a valid organization id.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $membership = OrganizationMember::query()
            ->with('organization')
            ->where('organization_id', $organizationId)
            ->where('user_id', $request->user()->getKey())
            ->first();

        if (! $membership) {
            return response()->json([
                'message' => 'You do not have access to this organization.',
            ], Response::HTTP_FORBIDDEN);
        }

        $this->tenant->set($membership->organization, $membership);

        try {
            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }
}
