<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:run')
    ->dailyAt('00:05')
    ->timezone((string) config('billing.timezone', 'UTC'))
    ->withoutOverlapping();
