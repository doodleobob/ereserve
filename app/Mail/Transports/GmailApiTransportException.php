<?php

namespace App\Mail\Transports;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use RuntimeException;
use Throwable;

class GmailApiTransportException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $operation,
        private readonly string $errorCategory,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('The Gmail API mail transport is not configured.', 'gmail_send', 'internal');
    }

    public static function failure(string $operation, string $errorCategory, Throwable $previous): self
    {
        return new self('Gmail API email delivery failed.', $operation, $errorCategory, $previous);
    }

    public static function categoryFor(Throwable $exception, string $operation): string
    {
        if ($exception instanceof ConnectException
            || ($exception instanceof RequestException && ! $exception->hasResponse())) {
            return 'network';
        }

        $status = (int) $exception->getCode();

        if ($status === 401 || ($operation === 'oauth_refresh' && $status === 400)) {
            return 'authentication';
        }

        if ($status === 403) {
            return 'authorization';
        }

        if ($status >= 400 && $status < 600) {
            return 'api_rejection';
        }

        if ($exception instanceof TransferException) {
            return 'network';
        }

        return 'internal';
    }

    /** @return array{operation: string, exception_class: class-string, error_category: string} */
    public function diagnosticContext(): array
    {
        $previous = $this->getPrevious();

        return [
            'operation' => $this->operation,
            'exception_class' => $previous === null ? self::class : $previous::class,
            'error_category' => $this->errorCategory,
        ];
    }
}
