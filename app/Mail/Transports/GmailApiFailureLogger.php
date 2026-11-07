<?php

namespace App\Mail\Transports;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GmailApiFailureLogger
{
    public static function log(
        GmailApiTransportException $exception,
        string $purpose,
        ?string $correlationId = null,
    ): void {
        $purpose = in_array($purpose, ['setup', 'login', 'verify', 'password_reset'], true)
            ? $purpose
            : 'other';
        $context = $exception->diagnosticContext() + ['purpose' => $purpose];

        if (is_string($correlationId) && Str::isUuid($correlationId)) {
            $context['correlation_id'] = $correlationId;
        }

        try {
            Log::warning('Gmail API delivery failed.', $context);
        } catch (Throwable) {
            // A diagnostic sink failure must not replace the generic delivery error.
        }
    }
}
