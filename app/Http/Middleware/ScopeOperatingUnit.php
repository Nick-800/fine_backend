<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\OperatingUnit;
use App\Support\CurrentUnitContext;
use Closure;
use Illuminate\Http\Request;
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
        $unitId = $request->header('X-Operating-Unit-ID');
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($unitId) {
            $unit = OperatingUnit::find($unitId);
            if (! $unit) {
                throw new BadRequestHttpException('Invalid Operating Unit ID.');
            }

            // Verify if user has a role assigned to this unit or a company-wide role
            $hasRole = $user->roles()
                ->where(function ($query) use ($unitId) {
                    $query->where('user_roles.operating_unit_id', $unitId)
                        ->orWhereNull('user_roles.operating_unit_id');
                })
                ->exists();

            if (! $hasRole) {
                throw new AccessDeniedHttpException('You do not have access to this Operating Unit.');
            }

            $this->context->setUnit($unit);
        } else {
            // Check if user has a company-wide role (where operating_unit_id is null)
            $hasCompanyWideRole = $user->roles()
                ->whereNull('user_roles.operating_unit_id')
                ->exists();

            if (! $hasCompanyWideRole) {
                throw new BadRequestHttpException('Missing X-Operating-Unit-ID header.');
            }
        }

        return $next($request);
    }
}
