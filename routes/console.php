<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ACC-07: monthly depreciation. Idempotent, so a missed or repeated run is
// harmless — assets already posted for the period are skipped.
Schedule::command('accounting:run-depreciation')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping();
