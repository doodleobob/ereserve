<?php

namespace App\Providers;

use App\Notifications\Channels\QueuedReservationMail;
use App\Models\EquipmentCharge;
use App\Models\Payment;
use App\Models\User;
use App\Mail\Transports\GmailApiTransport;
use App\Mail\Transports\GoogleGmailApiSender;
use App\Policies\EquipmentChargePolicy;
use App\Policies\AdminAccountPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ResidentPolicy;
use App\Policies\FacilityPolicy;
use App\Policies\OfficialUsePolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
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
        Gate::policy(EquipmentCharge::class, EquipmentChargePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(User::class, ResidentPolicy::class);
        Gate::policy(\App\Models\Facility::class, FacilityPolicy::class);
        Gate::policy(\App\Models\OfficialUse::class, OfficialUsePolicy::class);
        Gate::define('manage-admin-accounts', [AdminAccountPolicy::class, 'manageAny']);
        Gate::define('manage-admin-account', [AdminAccountPolicy::class, 'manage']);
        Event::listen(JobQueueing::class, [QueuedReservationMail::class, 'capturePayload']);
        Mail::extend('gmail_api', function (array $config = []): GmailApiTransport {
            $gmail = config('services.gmail');
            $user = $gmail['user'] ?? null;
            if (! is_string($user) || trim($user) === '') {
                throw \App\Mail\Transports\GmailApiTransportException::notConfigured();
            }

            return new GmailApiTransport(
                new GoogleGmailApiSender($gmail['client_id'] ?? null, $gmail['client_secret'] ?? null, $gmail['refresh_token'] ?? null),
                $user,
            );
        });

        // Use the configured application origin, never an untrusted request Host header.
        ResetPassword::createUrlUsing(fn ($user, $token) => rtrim(config('app.url'), '/').route('password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ], false));
    }
}
