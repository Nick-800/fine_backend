<?php

use App\Exceptions\OptimisticLockConflictException;
use App\Http\Middleware\EnsurePasswordIsUpdated;
use App\Http\Middleware\ScopeOperatingUnit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'scope.unit' => ScopeOperatingUnit::class,
            'ensure.password.updated' => EnsurePasswordIsUpdated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (OptimisticLockConflictException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        });
    })->create();
