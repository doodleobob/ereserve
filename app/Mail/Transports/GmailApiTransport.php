<?php

namespace App\Mail\Transports;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Throwable;

class GmailApiTransport extends AbstractTransport
{
    public function __construct(
        private readonly GmailApiSender $sender,
        private readonly string $user,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        try {
            $mime = $message->toString();
            $base64UrlMessage = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
            $this->sender->send($this->user, $base64UrlMessage);
        } catch (Throwable) {
            // Never propagate a provider exception, message content, or credentials.
            throw GmailApiTransportException::deliveryFailed();
        }
    }

    public function __toString(): string
    {
        return 'gmail_api';
    }
}
