<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('notifications:recover')->everyMinute()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
