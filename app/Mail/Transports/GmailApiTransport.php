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
        } catch (GmailApiTransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw GmailApiTransportException::failure(
                'gmail_send',
                GmailApiTransportException::categoryFor($exception, 'gmail_send'),
                $exception,
            );
        }
    }

    public function __toString(): string
    {
        return 'gmail_api';
    }
}
