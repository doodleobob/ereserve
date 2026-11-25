<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Throwable;

class SimGateService
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 15;

    /** @return array{status:string, uid:?string, error:?string} */
    public function send(string $recipient, string $message): array
    {
        $normalized = $this->normalizeRecipient($recipient);
        if ($normalized === null) {
            return ['status' => 'failed', 'uid' => null, 'error' => 'invalid_mobile_number'];
        }

        $apiKey = config('services.simgate.api_key');
        $deviceId = config('services.simgate.device_id');
        if (! is_string($apiKey) || $apiKey === '' || ! is_string($deviceId) || $deviceId === '') {
            return ['status' => 'failed', 'uid' => null, 'error' => 'missing_configuration'];
        }

        $startedAt = hrtime(true);
        try {
            $response = Http::baseUrl(rtrim((string) config('services.simgate.base_url'), '/'))
                ->acceptJson()->withHeaders(['x-api-key' => $apiKey])->asJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->post('/v1/sms/send', [
                    'deviceId' => $deviceId,
                    'phoneNumber' => $normalized,
                    'message' => $message,
                ]);
        } catch (Throwable $exception) {
            // A transport timeout may happen after SimGate accepted the request.
            // Keep the outcome ambiguous so the caller suppresses automatic resend.
            $category = $this->transportErrorCategory($exception);
            $elapsedMs = $this->elapsedMilliseconds($startedAt);
            $handlerContext = $this->safeHandlerContext($exception);
            Log::warning('SimGate SMS transport failed; outcome unknown.', [
                'exception_class' => $exception::class,
                'error_category' => $category,
                'transport_failure_type' => $this->transportFailureType($category),
                'curl_error_code' => $this->curlErrorCode($exception, $handlerContext),
                'timeout_phase' => $this->timeoutPhase($exception, $category, $elapsedMs, $handlerContext),
                'elapsed_ms' => $elapsedMs,
                'http_status' => isset($handlerContext['http_code']) && $handlerContext['http_code'] > 0
                    ? $handlerContext['http_code']
                    : null,
            ]);

            return ['status' => 'unknown', 'uid' => null, 'error' => $category];
        }

        $elapsedMs = $this->elapsedMilliseconds($startedAt);
        $body = $response->json();
        if (in_array($response->status(), [200, 202], true)
            && is_array($body)
            && ($body['success'] ?? false) === true
            && is_string($body['messageId'] ?? null)
            && ($body['queued'] ?? null) === in_array($response->status(), [202], true)) {
            Log::info('SimGate SMS request completed.', [
                'outcome' => 'accepted',
                'http_status' => $response->status(),
                'elapsed_ms' => $elapsedMs,
            ]);

            return ['status' => 'accepted', 'uid' => $body['messageId'], 'error' => null];
        }

        $error = $this->errorCategory($response->status());
        Log::warning('SimGate SMS API returned an error.', [
            'error_category' => $error,
            'http_status' => $response->status(),
            'elapsed_ms' => $elapsedMs,
        ]);

        return ['status' => 'failed', 'uid' => null, 'error' => $error];
    }

    public function normalizeRecipient(string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if (preg_match('/^09\d{9}$/', $digits)) {
            return '+63'.substr($digits, 1);
        }
        if (preg_match('/^639\d{9}$/', $digits)) {
            return '+'.$digits;
        }

        return null;
    }

    private function errorCategory(int $status): string
    {
        return match ($status) {
            401 => 'invalid_api_key',
            404 => 'gateway_unavailable',
            403 => 'provider_rejected',
            default => $status >= 500 ? 'provider_unavailable' : 'provider_rejected',
        };
    }

    private function transportErrorCategory(Throwable $exception): string
    {
        $messages = [];
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $messages[] = strtolower($current->getMessage());
        }
        $message = implode(' ', $messages);

        if (preg_match('/could not resolve|couldn.t resolve|name or service not known|temporary failure in name resolution|getaddrinfo|curl error 6\b/', $message)) {
            return 'transport_dns_unknown';
        }
        if (preg_match('/ssl|tls|certificate|handshake|curl error (35|51|60)\b|wrong version number/', $message)) {
            return 'transport_ssl_unknown';
        }
        if (preg_match('/timed? ?out|timeout|curl error 28\b|operation exceeded/', $message)) {
            return 'transport_timeout_unknown';
        }
        if (preg_match('/connection refused|failed to connect|connection reset|network is unreachable|no route to host|curl error (7|52|55|56)\b/', $message)) {
            return 'transport_connection_unknown';
        }

        return 'transport_error_unknown';
    }

    private function transportFailureType(string $category): string
    {
        return match ($category) {
            'transport_timeout_unknown' => 'timeout',
            'transport_ssl_unknown' => 'tls',
            'transport_dns_unknown' => 'dns',
            'transport_connection_unknown' => 'connection',
            default => 'other',
        };
    }

    private function curlErrorCode(Throwable $exception, array $context): ?int
    {
        if (isset($context['errno']) && is_numeric($context['errno'])) {
            return (int) $context['errno'];
        }

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (preg_match('/\\bcurl error\\s+(\\d+)\\b/i', $current->getMessage(), $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function safeHandlerContext(Throwable $exception): array
    {
        $safeKeys = ['errno', 'http_code', 'namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'total_time'];
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (! method_exists($current, 'getHandlerContext')) {
                continue;
            }

            try {
                $context = $current->getHandlerContext();
            } catch (Throwable) {
                return [];
            }
            if (! is_array($context)) {
                return [];
            }

            return array_filter(array_intersect_key($context, array_flip($safeKeys)),
                fn ($value) => is_int($value) || is_float($value));
        }

        return [];
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function timeoutPhase(Throwable $exception, string $category, int $elapsedMs, array $context): string
    {
        if ($category !== 'transport_timeout_unknown') {
            return 'not_applicable';
        }

        if (($context['starttransfer_time'] ?? 0) > 0 || ($context['appconnect_time'] ?? 0) > 0 || ($context['http_code'] ?? 0) > 0) {
            return 'overall_request';
        }
        if (isset($context['total_time']) && $context['total_time'] >= self::REQUEST_TIMEOUT_SECONDS * 0.8) {
            return 'overall_request';
        }
        if ($elapsedMs >= self::REQUEST_TIMEOUT_SECONDS * 800) {
            return 'overall_request';
        }

        for ($current = $exception->getPrevious(); $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof GuzzleConnectException) {
                return 'connection';
            }
            if ($current instanceof GuzzleRequestException) {
                return 'overall_request';
            }
        }

        if (($context['connect_time'] ?? 0) > 0) {
            return 'overall_request';
        }
        if ($elapsedMs >= self::CONNECT_TIMEOUT_SECONDS * 800) {
            return 'connection';
        }

        return 'unknown';
    }
}
