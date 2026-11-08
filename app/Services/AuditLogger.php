<?php

namespace App\Services;

use App\Models\AuditLogEntry;
use App\Models\EquipmentCharge;
use App\Models\EquipmentChargePayment;
use App\Models\EquipmentInventoryAdjustment;
use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\OfficialUseConflict;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ResourceUsage;
use App\Models\ResourceUsageEvent;
use App\Models\User;
use App\Support\Barangays;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use LogicException;

/** Writes minimal, allowlisted accountability events inside the caller's transaction. */
class AuditLogger
{
    private const DESCRIPTIONS = [
        'admin.created' => 'Created a Barangay Admin account',
        'admin.updated' => 'Updated a Barangay Admin account',
        'admin.status_changed' => 'Changed a Barangay Admin account status',
        'admin.permissions_changed' => 'Changed Barangay Admin permission restrictions',
        'admin.password_reset_requested' => 'Requested a Barangay Admin password reset',
        'resident.status_changed' => 'Changed a resident account status',
        'reservation.accepted' => 'Accepted a reservation', 'reservation.rejected' => 'Rejected a reservation',
        'reservation.updated' => 'Updated or cancelled an accepted reservation', 'reservation.payment_corrected' => 'Corrected a reservation total',
        'reservation.cancelled_by_official_use' => 'Cancelled a pending reservation for an Official Use schedule',
        'payment.status_changed' => 'Changed a reservation payment status', 'payment.acceptance_recorded' => 'Recorded a reservation acceptance payment',
        'payment.cancellation_recorded' => 'Recorded a reservation cancellation payment transition',
        'payment.report_exported' => 'Exported a payment report',
        'facility.created' => 'Created a facility or equipment record', 'facility.updated' => 'Updated a facility or equipment record',
        'facility.deleted' => 'Deleted a facility or equipment record', 'inventory.adjusted' => 'Adjusted equipment inventory',
        'usage.operated' => 'Recorded resource use, equipment release, or return',
        'charge.created' => 'Created an equipment charge', 'charge.updated' => 'Updated an equipment charge',
        'charge.payment_recorded' => 'Recorded an equipment charge payment',
        'official_use.created' => 'Created an Official Use schedule', 'official_use.updated' => 'Updated an Official Use schedule',
        'official_use.conflicts_synchronized' => 'Synchronized Official Use reservation conflicts',
        'official_use.conflict_created' => 'Created or reopened an Official Use reservation conflict',
    ];

    public static function descriptions(): array
    {
        return self::DESCRIPTIONS;
    }

    public function record(
        string $action,
        ?Model $target,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?User $actorContext = null,
        ?string $barangayContext = null,
    ): ?AuditLogEntry
    {
        if (! isset(self::DESCRIPTIONS[$action])) {
            throw new LogicException('Audit action is not in the supported action list.');
        }

        $authenticated = Auth::user() ?? $actorContext;
        if (! $authenticated instanceof User || ! in_array($authenticated->role, ['admin', 'super_admin'], true)) {
            return null;
        }
        if ($actorContext !== null && Auth::check() && (int) Auth::id() !== (int) $actorContext->getKey()) {
            throw new LogicException('Audit actor context must match the authenticated administrator.');
        }

        // Reload actor and target relations from persisted state, never from request fields.
        $actor = User::query()->find($authenticated->getKey());
        if (! $actor || ! $actor->is_active || ! in_array($actor->role, ['admin', 'super_admin'], true)) {
            throw new LogicException('An active persisted administrator is required to write an audit event.');
        }

        $storedTarget = $target !== null && $target->exists ? $target->newQuery()->find($target->getKey()) : null;
        $scopeTarget = $storedTarget ?? $target;
        $barangay = $scopeTarget ? $this->barangay($scopeTarget) : null;
        if ($barangay === null && $actor->role === 'admin') {
            $barangay = $actor->barangay;
        } elseif ($barangay === null && $actor->role === 'super_admin' && in_array($barangayContext, Barangays::ALL, true)) {
            $barangay = $barangayContext;
        }

        return AuditLogEntry::create([
            'actor_id' => $actor->getKey(),
            'actor_name' => mb_substr($actor->name, 0, 191),
            'actor_role' => $actor->role,
            'action' => $action,
            'target_type' => $scopeTarget ? class_basename($scopeTarget) : null,
            'target_id' => $scopeTarget?->getKey() === null ? null : (string) $scopeTarget->getKey(),
            'barangay' => $barangay,
            'description' => self::DESCRIPTIONS[$action],
            'before_values' => $this->sanitize($before),
            'after_values' => $this->sanitize($after),
            'reason' => $this->safeReason($reason),
            'occurred_at' => now(),
        ]);
    }

    public function recordPaymentReportExport(User $actor, string $reportType, string $format, array $filters, ?string $barangayContext = null): ?AuditLogEntry
    {
        if (! in_array($reportType, ['payments', 'centralized_payments'], true)
            || ! in_array($format, ['pdf', 'xlsx', 'csv'], true)) {
            throw new LogicException('Payment report export metadata is invalid.');
        }

        $safeFilters = [];
        foreach (['type', 'status', 'from_date', 'to_date'] as $key) {
            if (isset($filters[$key]) && is_scalar($filters[$key])) {
                $safeFilters[$key] = mb_substr((string) $filters[$key], 0, 32);
            }
        }

        return $this->record('payment.report_exported', null, null, [
            'report_type' => $reportType,
            'format' => $format,
            'filters' => $safeFilters,
        ], actorContext: $actor, barangayContext: $barangayContext);
    }

    private function barangay(Model $target): ?string
    {
        return match (true) {
            $target instanceof User, $target instanceof Facility, $target instanceof OfficialUse => $target->barangay,
            $target instanceof Reservation => $target->managingBarangay(),
            $target instanceof Payment => $target->reservation?->managingBarangay(),
            $target instanceof ResourceUsage => $target->facility?->barangay,
            $target instanceof ResourceUsageEvent => $target->usage?->facility?->barangay,
            $target instanceof EquipmentCharge => $target->usage?->facility?->barangay,
            $target instanceof EquipmentChargePayment => $target->charge?->usage?->facility?->barangay,
            $target instanceof EquipmentInventoryAdjustment => Facility::query()->whereKey($target->facility_id)->value('barangay'),
            $target instanceof OfficialUseConflict => $target->officialUse?->barangay,
            default => is_string($target->getAttribute('barangay')) ? $target->getAttribute('barangay') : null,
        };
    }

    private function sanitize(?array $values): ?array
    {
        if ($values === null) return null;
        $clean = [];
        foreach ($values as $key => $value) {
            if (! is_string($key) || preg_match('/password|token|secret|code|remember|credential|authorization|api[_-]?key|session|cookie|private[_-]?key|notes|purpose|email|phone/i', $key)) continue;
            if (is_array($value)) $clean[$key] = $this->sanitizeNested($value, 0);
            elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) $clean[$key] = $value;
            elseif (is_string($value)) $clean[$key] = mb_substr($value, 0, 255);
        }
        return $clean;
    }

    private function sanitizeNested(array $values, int $depth): array|string|int|float|bool|null
    {
        if ($depth > 3) return '[truncated]';
        $clean = [];
        foreach (array_slice($values, 0, 50, true) as $key => $value) {
            if (is_string($key) && preg_match('/password|token|secret|code|remember|credential|authorization|api[_-]?key|session|cookie|private[_-]?key|notes|purpose|email|phone/i', $key)) continue;
            if (is_array($value)) $clean[$key] = $this->sanitizeNested($value, $depth + 1);
            elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) $clean[$key] = $value;
            elseif (is_string($value)) $clean[$key] = mb_substr($value, 0, 255);
        }
        return $clean;
    }

    private function safeReason(?string $reason): ?string
    {
        if ($reason === null) return null;
        $reason = trim(strip_tags($reason));
        $reason = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[redacted email]', $reason) ?? '';
        $reason = preg_replace('/\b(password|token|secret|verification code|2fa code|api[_ -]?key)\s*[:=]?\s*\S+/i', '$1 [redacted]', $reason) ?? '';
        $reason = preg_replace('/(?<!\w)(?=(?:\D*\d){9,})(?:\+?\d[\d ()-]{7,}\d)(?!\w)/', '[redacted number]', $reason) ?? '';
        return $reason === '' ? null : mb_substr($reason, 0, 1000);
    }
}
