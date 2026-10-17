<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module-level permission check for a route group: reads (GET/HEAD) need the
 * view permission, everything else the write permission. Usage:
 * `require.module:view-foam,edit-foam`. Runs after require.role, which still
 * bounds who can reach the group at all.
 */
final class RequireModulePermission
{
    public function handle(Request $request, Closure $next, string $viewPermission, ?string $writePermission = null): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'UNAUTHENTICATED'], 401);
        }

        // Same bypass as RequireRole: owner and admin reach every module.
        if ($user->hasRole('owner') || $user->hasRole('admin')) {
            return $next($request);
        }

        $permission = in_array($request->method(), ['GET', 'HEAD'], true)
            ? $viewPermission
            : ($writePermission ?? $viewPermission);

        if ($user->hasPermission($permission)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'You lack the necessary permission for this resource.',
            'code' => 'ACCESS_DENIED',
            'required_permissions' => [$permission],
        ], 403);
    }
}
