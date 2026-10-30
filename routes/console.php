<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run rimasti attivi dopo la morte di un worker (Ruling F4).
Schedule::command('dada:runs:reap')->everyFiveMinutes()->withoutOverlapping();
