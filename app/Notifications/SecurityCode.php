<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityCode extends Notification
{
    public function __construct(#[\SensitiveParameter] private readonly string $code, private readonly string $purpose) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->purpose === 'verify' ? 'Your eReserve email verification code' : 'Your eReserve security code')
            ->view('emails.security-code', ['code' => $this->code, 'purpose' => $this->purpose]);
    }
}
