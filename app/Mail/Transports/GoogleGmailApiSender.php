<?php

namespace App\Mail\Transports;

use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Throwable;

class GoogleGmailApiSender implements GmailApiSender
{
    private ?Client $client = null;

    public function __construct(
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly ?string $refreshToken,
        ?Client $client = null,
    ) {
        $this->client = $client;

        foreach ([$clientId, $clientSecret, $refreshToken] as $credential) {
            if (! is_string($credential) || trim($credential) === '') {
                throw GmailApiTransportException::notConfigured();
            }
        }
    }

    public function send(string $user, string $base64UrlMessage): void
    {
        $client = $this->client();

        try {
            $gmail = new Gmail($client);
            $message = new Message;
            $message->setRaw($base64UrlMessage);
            $gmail->users_messages->send($user, $message);
        } catch (Throwable $exception) {
            throw GmailApiTransportException::failure(
                'gmail_send',
                GmailApiTransportException::categoryFor($exception, 'gmail_send'),
                $exception,
            );
        }
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $this->client = new Client;
        }

        $this->client->setApplicationName((string) config('app.name', 'eReserve'));
        $this->client->setClientId($this->clientId);
        $this->client->setClientSecret($this->clientSecret);
        $this->client->setScopes([Gmail::GMAIL_SEND]);
        $this->client->setAccessType('offline');

        if ($this->client->isAccessTokenExpired()) {
            try {
                $token = $this->client->fetchAccessTokenWithRefreshToken($this->refreshToken);
            } catch (Throwable $exception) {
                throw GmailApiTransportException::failure(
                    'oauth_refresh',
                    GmailApiTransportException::categoryFor($exception, 'oauth_refresh'),
                    $exception,
                );
            }

            if (! is_array($token) || empty($token['access_token'])) {
                throw GmailApiTransportException::failure(
                    'oauth_refresh',
                    'authentication',
                    new \RuntimeException('OAuth token refresh response was rejected.'),
                );
            }
        }

        return $this->client;
    }
}
