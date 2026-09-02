<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ModemPay payment/payout lifecycle — see each command's own docblock.
Schedule::command('orders:expire-awaiting-payment')->everyFiveMinutes();
Schedule::command('orders:reconcile-modempay')->everyFiveMinutes();
Schedule::command('orders:auto-release-payouts')->daily();
