<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('revenue:recognize')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('payouts:process')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('payouts:reconcile')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('ledger:verify')->dailyAt('06:00')->onOneServer();
