<?php

namespace App\Mail\Transports;

use RuntimeException;

class GmailApiTransportException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('The Gmail API mail transport is not configured.');
    }

    public static function deliveryFailed(): self
    {
        return new self('Gmail API email delivery failed.');
    }
}
