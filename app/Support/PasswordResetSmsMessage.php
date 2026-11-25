<?php

namespace App\Support;

use Illuminate\Auth\Notifications\ResetPassword;

class PasswordResetSmsMessage
{
    public static function make(object $user, string $token): string
    {
        $url = ResetPassword::$createUrlCallback
            ? call_user_func(ResetPassword::$createUrlCallback, $user, $token)
            : rtrim(config('app.url'), '/').route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], false);

        return "eReserve - Washington\nPASSWORD RESET\n\nHello, {$user->name}!\nUse this link to reset your password: {$url}\nIf you did not request this, ignore this message.";
    }
}
