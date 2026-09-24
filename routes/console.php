<?php

use App\Models\TwoFactorChallenge;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => TwoFactorChallenge::where('expires_at', '<=', now())->delete())
    ->hourly()->name('purge-expired-security-codes')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
