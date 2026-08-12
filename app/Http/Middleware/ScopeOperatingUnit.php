<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\InvalidOperatingUnitException;
use App\Models\OperatingUnit;
use App\Models\User;
use App\Support\CurrentUnitContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ScopeOperatingUnit
{
    public function __construct(
        private readonly CurrentUnitContext $context
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // 1. Extract potential unit ID from header
        $rawUnitId = $request->header('X-Operating-Unit-ID');
        $unitId = is_string($rawUnitId) ? trim($rawUnitId) : null;

        if ($unitId !== null && in_array(strtolower($unitId), ['null', 'undefined', 'none', ''], true)) {
            $unitId = null;
        }

        // 2. If a non-null unit header is provided, validate format, existence, and authorization
        if ($unitId !== null) {
            if (! Str::isUuid($unitId)) {
                throw new BadRequestHttpException('Invalid Operating Unit ID format.');
            }

            $unit = OperatingUnit::find($unitId);
            if ($unit) {
                if (! $this->userHasAccessToUnit($user, $unit->id)) {
                    throw new AccessDeniedHttpException('You do not have access to this Operating Unit.');
                }

                $this->context->setUnit($unit);

                return $next($request);
            }

            // Stale UUID header (unit not found in DB)
            // For operating-units.index, allow fetching available units so frontend can recover
            if ($request->routeIs('operating-units.index')) {
                return $next($request);
            }

            // Carries a code so the client can drop the stale id and recover.
            // Without one it would keep resending the same bad header on retry.
            throw new InvalidOperatingUnitException(
                'The selected operating unit no longer exists. It may belong to a different database.'
            );
        }

        // 3. Fallback to route parameter if header unit ID was not provided
        $routeParam = $request->route('operating_unit') ?? $request->route('id');
        if ($routeParam) {
            $possibleId = is_object($routeParam) ? $routeParam->getKey() : (string) $routeParam;
            if (Str::isUuid($possibleId)) {
                $routeUnit = OperatingUnit::find($possibleId);
                if ($routeUnit) {
                    if (! $this->userHasAccessToUnit($user, $routeUnit->id)) {
                        throw new AccessDeniedHttpException('You do not have access to this Operating Unit.');
                    }

                    $this->context->setUnit($routeUnit);

                    return $next($request);
                }
            }
        }

        // 4. Handle requests without a unit header
        if ($request->routeIs('operating-units.index')) {
            return $next($request);
        }

        if ($request->routeIs('operating-units.store')) {
            $hasCompanyWideRole = $user->roles()
                ->whereNull('user_roles.operating_unit_id')
                ->exists();

            if ($hasCompanyWideRole) {
                return $next($request);
            }
        }

        $hasCompanyWideRole = $user->roles()
            ->whereNull('user_roles.operating_unit_id')
            ->exists();

        if (! $hasCompanyWideRole) {
            throw new BadRequestHttpException('Missing X-Operating-Unit-ID header.');
        }

        return $next($request);
    }

    private function userHasAccessToUnit(User $user, string $unitId): bool
    {
        return $user->roles()
            ->where(function ($query) use ($unitId) {
                $query->where('user_roles.operating_unit_id', $unitId)
                    ->orWhereNull('user_roles.operating_unit_id');
            })
            ->exists();
    }
}
