<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedSimGateSms;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityCode extends Notification
{
    public function __construct(#[\SensitiveParameter] private readonly string $code, private readonly string $purpose) {}

    public function via(object $notifiable): array
    {
        return ['database', QueuedSimGateSms::class, 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['event' => 'security_code_delivery', 'purpose' => $this->purpose];
    }

    public function toSms(object $notifiable): string
    {
        $label = $this->purpose === 'verify' ? 'EMAIL VERIFICATION CODE' : 'SECURITY CODE';

        return "eReserve - Washington\n{$label}\n\nHello, {$notifiable->name}!\nYour code is {$this->code}.\nIt expires in 5 minutes.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->purpose === 'verify' ? 'Your eReserve email verification code' : 'Your eReserve security code')
            ->view('emails.security-code', ['code' => $this->code, 'purpose' => $this->purpose]);
    }
}
