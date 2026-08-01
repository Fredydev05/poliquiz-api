<?php

use Illuminate\Support\Facades\Schedule;

// Cierra salas abandonadas cada 5 minutos (requiere el scheduler corriendo:
// en prod, un cron a `php artisan schedule:run`; ver F7).
Schedule::command('poliquiz:expire-abandoned')
    ->everyFiveMinutes()
    ->withoutOverlapping();
