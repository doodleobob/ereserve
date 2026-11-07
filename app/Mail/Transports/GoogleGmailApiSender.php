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
        try {
            $client = $this->client();
            $gmail = new Gmail($client);
            $message = new Message;
            $message->setRaw($base64UrlMessage);
            $gmail->users_messages->send($user, $message);
        } catch (Throwable) {
            // Google exceptions can contain request details; discard the original chain.
            throw GmailApiTransportException::deliveryFailed();
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
            $token = $this->client->fetchAccessTokenWithRefreshToken($this->refreshToken);
            if (! is_array($token) || empty($token['access_token'])) {
                throw GmailApiTransportException::deliveryFailed();
            }
        }

        return $this->client;
    }
}
