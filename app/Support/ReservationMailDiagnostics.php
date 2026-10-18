<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\View\ViewException;
use Throwable;

class ReservationMailDiagnostics
{
    public static function log(string $level, string $stage, array $context): void
    {
        try {
            Log::{$level}('Reservation email '.$stage.'.', $context);
        } catch (Throwable) {
            // A broken log sink must not turn an accepted email into a retry.
            error_log('Reservation email diagnostic logging is unavailable.');
        }
    }

    public static function failureCategory(Throwable $exception): string
    {
        if ($exception instanceof ViewException) {
            return 'rendering';
        }

        // Inspect only to classify; never retain the message, trace, or SMTP transcript.
        $message = $exception->getMessage();
        foreach ([
            'tls' => '/certificate verify|SSL operation failed|STARTTLS|TLS required/i',
            'authentication' => '/\b53[45]\b|could not authenticate|failed to authenticate|badcredentials/i',
            'rate_limit' => '/\b(421|450|452)\b|daily.*limit|sending.*limit|rate.*limit|quota/i',
            'timeout' => '/timed out|timeout/i',
            'connection' => '/connection could not be established|connection refused|network is unreachable|php_network_getaddresses/i',
            'recipient' => '/\b(550|551|553)\b|invalid.*address/i',
        ] as $category => $pattern) {
            if (preg_match($pattern, $message)) {
                return $category;
            }
        }

        return 'other';
    }
}
