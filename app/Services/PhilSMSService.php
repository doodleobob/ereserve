<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class PhilSMSService
{
    /** @return array{status:string, uid:?string, error:?string} */
    public function send(string $recipient, string $message): array
    {
        $normalized = $this->normalizeRecipient($recipient);
        if ($normalized === null) {
            return ['status' => 'failed', 'uid' => null, 'error' => 'invalid_mobile_number'];
        }
        $token = config('services.philsms.token');
        $sender = config('services.philsms.sender_id');
        if (! is_string($token) || $token === '' || ! is_string($sender) || $sender === '') {
            return ['status' => 'failed', 'uid' => null, 'error' => 'missing_configuration'];
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.philsms.base_url'), '/'))
                ->acceptJson()->withToken($token)->asJson()->connectTimeout(5)->timeout(15)
                ->post('/api/v3/sms/send', [
                    'recipient' => $normalized,
                    'sender_id' => $sender,
                    'type' => 'plain',
                    'message' => $message,
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unknown', 'uid' => null, 'error' => 'transport_outcome_unknown'];
        }

        $body = $response->json();
        if ($response->successful() && is_array($body) && ($body['status'] ?? null) === 'success') {
            $data = $body['data'] ?? null;
            $uid = is_array($data) ? ($data['uid'] ?? $data['id'] ?? null) : null;

            return ['status' => 'accepted', 'uid' => is_string($uid) ? $uid : null, 'error' => null];
        }

        return ['status' => 'failed', 'uid' => null, 'error' => $this->errorCategory($response->status(), $body)];
    }

    public function normalizeRecipient(string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if (preg_match('/^09\d{9}$/', $digits)) {
            return '63'.substr($digits, 1);
        }
        if (preg_match('/^639\d{9}$/', $digits)) {
            return $digits;
        }

        return null;
    }

    private function errorCategory(int $status, mixed $body): string
    {
        $message = is_array($body) ? strtolower((string) ($body['message'] ?? '')) : '';
        if (str_contains($message, 'credit') || str_contains($message, 'balance')) return 'insufficient_credits';
        if (str_contains($message, 'number') || str_contains($message, 'recipient')) return 'invalid_recipient';

        return $status >= 500 ? 'provider_unavailable' : 'provider_rejected';
    }
}
