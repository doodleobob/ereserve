<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifyEmailRequest extends EmailVerificationRequest
{
    // Keep Laravel's authorize() and fulfill() checks unchanged; only explain failures.
    protected function failedAuthorization()
    {
        $reason = ! User::query()->whereKey($this->route('id'))->exists()
            ? 'missing-account'
            : ((string) $this->user()->getKey() !== (string) $this->route('id')
                ? 'wrong-account'
                : 'email-changed');

        throw new HttpResponseException(
            response()->view('auth.verification-link-error', ['reason' => $reason], 403)
                ->header('Cache-Control', 'no-store, private')
        );
    }
}
