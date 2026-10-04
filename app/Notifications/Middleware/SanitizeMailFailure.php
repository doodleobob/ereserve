<?php

namespace App\Notifications\Middleware;

use Closure;
use RuntimeException;
use Throwable;

class SanitizeMailFailure
{
    public function handle(object $job, Closure $next): mixed
    {
        try {
            return $next($job);
        } catch (Throwable) {
            // SMTP errors may include authentication details. Do not retain the
            // provider exception in worker logs, failed_jobs, or exception chains.
            throw new RuntimeException('Reservation email delivery failed.');
        }
    }
}
