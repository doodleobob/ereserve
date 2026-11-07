<?php

namespace Tests\Unit;

use App\Mail\Transports\GmailApiTransportException;
use App\Notifications\Middleware\SanitizeMailFailure;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class SanitizeMailFailureTest extends TestCase
{
    public function test_gmail_categories_are_logged_safely_and_provider_exception_is_not_chained(): void
    {
        Log::spy();
        $categories = ['authentication', 'authorization', 'network', 'api_rejection'];

        foreach ($categories as $category) {
            $providerFailure = new RuntimeException('private-refresh-token recipient@example.test provider response body');
            $gmailFailure = GmailApiTransportException::failure('gmail_send', $category, $providerFailure);

            try {
                (new SanitizeMailFailure)->handle($this->job(), static function () use ($gmailFailure): never {
                    throw $gmailFailure;
                });
                $this->fail('Expected a sanitized queue failure.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Reservation email delivery failed.', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
        }

        $seen = 0;
        Log::shouldHaveReceived('warning')->times(4)->withArgs(function (string $message, array $context) use (&$seen, $categories): bool {
            $this->assertSame('Reservation email attempt failed.', $message);
            $this->assertSame($categories[$seen++], $context['failure_category']);
            $this->assertSame('gmail_send', $context['operation']);
            $this->assertSame(RuntimeException::class, $context['exception_type']);
            $this->assertSame(RuntimeException::class, $context['exception_class']);
            $this->assertSame('accepted', $context['event']);
            foreach (['notification_id', 'reservation_id', 'job_id', 'attempt', 'connection', 'queue'] as $key) {
                $this->assertArrayHasKey($key, $context);
            }
            $this->assertDoesNotMatchRegularExpression(
                '/private-refresh-token|recipient@example\.test|provider response body/',
                json_encode($context, JSON_THROW_ON_ERROR),
            );

            return true;
        });
    }

    public function test_non_gmail_failure_keeps_existing_message_classification(): void
    {
        Log::spy();

        try {
            (new SanitizeMailFailure)->handle($this->job(), static function (): never {
                throw new RuntimeException('535 private SMTP authentication details');
            });
            $this->fail('Expected a sanitized queue failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Reservation email delivery failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Reservation email attempt failed.', $message);
            $this->assertSame(RuntimeException::class, $context['exception_type']);
            $this->assertSame('authentication', $context['failure_category']);
            $this->assertArrayNotHasKey('operation', $context);
            $this->assertDoesNotMatchRegularExpression('/535 private SMTP/', json_encode($context, JSON_THROW_ON_ERROR));

            return true;
        });
    }

    private function job(): object
    {
        $recipient = new class
        {
            public function getConnection(): object
            {
                return new class
                {
                    public function transaction(callable $callback): mixed
                    {
                        return $callback();
                    }
                };
            }

            public function notifications(): object
            {
                return new class
                {
                    public function whereKey(mixed $key): self
                    {
                        return $this;
                    }

                    public function lockForUpdate(): self
                    {
                        return $this;
                    }

                    public function first(): object
                    {
                        return (object) ['data' => []];
                    }
                };
            }
        };

        $notification = new class
        {
            public string $id = 'notification-id';

            public function mailContext(?object $recipient = null): array
            {
                return [
                    'notification_id' => $this->id,
                    'reservation_id' => 17,
                    'event' => 'accepted',
                    'recipient_id' => 23,
                ];
            }
        };

        return new class($recipient, $notification)
        {
            public $notifiables;

            public object $notification;

            public object $job;

            public string $connection = 'database';

            public function __construct(object $recipient, object $notification)
            {
                $this->notifiables = collect([$recipient]);
                $this->notification = $notification;
                $this->job = new class
                {
                    public function getJobId(): string
                    {
                        return 'job-id';
                    }

                    public function getQueue(): string
                    {
                        return 'default';
                    }
                };
            }

            public function attempts(): int
            {
                return 2;
            }
        };
    }
}
