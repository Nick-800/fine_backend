<?php

declare(strict_types=1);

namespace App\Extensions\Scramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\RouteInfo;

final class AddOperatingUnitHeaderExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $operation->addParameters([
            Parameter::make('X-Operating-Unit-ID', 'header')
                ->description('Operating Unit UUID scope required for unit-scoped API operations')
                ->example('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'),
        ]);
    }
}
