<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // Owner and Admin always bypass role checks
        if ($user->hasRole('owner') || $user->hasRole('admin')) {
            return $next($request);
        }

        // Expand requested roles to match both hyphenated and underscore variants
        $variants = [];
        foreach ($roles as $role) {
            $variants[] = $role;
            $variants[] = str_replace('-', '_', $role);
            $variants[] = str_replace('_', '-', $role);
        }
        $variants = array_unique($variants);

        foreach ($variants as $roleVariant) {
            if ($user->hasRole($roleVariant)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You do not have the required role to perform this action.',
            'code' => 'ACCESS_DENIED',
            'required_roles' => array_values($roles),
        ], 403);
    }
}
