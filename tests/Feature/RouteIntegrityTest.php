<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Guard against the bug class that shipped three separate times: a route
 * registered against a controller method that does not exist (dead scaffolding
 * from apiResource or renamed actions). Such a route 500s only when hit, so
 * nothing else catches it.
 */
test('every registered route points at a real controller method', function () {
    $broken = [];

    foreach (Route::getRoutes() as $route) {
        $action = $route->getActionName();

        if ($action === 'Closure' || ! str_contains($action, '@')) {
            continue;
        }

        [$class, $method] = explode('@', $action);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            $broken[] = "/{$route->uri()} -> {$action}";
        }
    }

    expect($broken)->toBe([]);
});
