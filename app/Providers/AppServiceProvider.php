<?php

namespace App\Providers;

use App\Notifications\Channels\QueuedReservationMail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QueuedReservationMail::class);
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Event::listen(JobQueueing::class, [QueuedReservationMail::class, 'capturePayload']);

        // Use the configured application origin, never an untrusted request Host header.
        ResetPassword::createUrlUsing(fn ($user, $token) => rtrim(config('app.url'), '/').route('password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ], false));
    }
}
