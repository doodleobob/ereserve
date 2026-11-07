<?php

namespace App\Mail\Transports;

interface GmailApiSender
{
    public function send(string $user, string $base64UrlMessage): void;
}
