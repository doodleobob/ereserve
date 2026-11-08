<?php

namespace App\Services;

use App\Models\EquipmentCharge;
use App\Models\Facility;
use App\Models\ResourceUsage;
use App\Models\User;
use App\Notifications\EquipmentChargeCreated;
use App\Support\AdminPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class EquipmentChargeManagement
{
    public const REASONS = ['Unreturned Equipment', 'Damaged Equipment', 'Lost Equipment', 'Incomplete Return', 'Other'];

    public function create(User $actor, array $input): EquipmentCharge
    {
        Gate::forUser($actor)->authorize('create', EquipmentCharge::class);
        $data = Validator::make($input, [
            'resource_usage_id' => ['required', 'integer', 'min:1', 'exists:resource_usages,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'reason' => ['required', Rule::in(self::REASONS)],
            'reason_other' => ['required_if:reason,Other', 'prohibited_unless:reason,Other', 'nullable', 'string', 'max:1900'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'request_key' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $usage = ResourceUsage::query()->lockForUpdate()->findOrFail($data['resource_usage_id']);
            $facility = Facility::query()->lockForUpdate()->findOrFail($usage->facility_id);
            $usage->setRelation('facility', $facility);
            Gate::forUser($actor)->authorize('create', EquipmentCharge::class);
            $this->require($usage->resource_type === 'Equipment' && $facility->category === 'Equipment', 'Charges can only be recorded for equipment borrowing records.', 'resource_usage_id');
            $this->require($usage->facility_id === $facility->id && $usage->barangay === $facility->barangay, 'This borrowing record has inconsistent resource ownership.', 'resource_usage_id');
            $this->require(app(Authorization::class)->managesFacility($actor, $facility), 'This borrowing record is outside your barangay.', 'resource_usage_id');
            $reservation = $usage->reservation;
            $this->require($reservation !== null && $reservation->facility_id === $facility->id
                && $reservation->managingBarangay() === $facility->barangay, 'The borrowing record has no valid reservation in this barangay.', 'resource_usage_id');

            $previous = EquipmentCharge::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($previous) {
                $same = $previous->created_by === $actor->id && $previous->resource_usage_id === $usage->id
                    && $previous->quantity === (int) $data['quantity'] && $previous->amount === number_format((float) $data['amount'], 2, '.', '')
                    && $previous->reason === $this->reasonValue($data) && $previous->notes === ($data['notes'] ?? null);
                $this->require($same, 'This submission key has already been used for different charge details.', 'request_key');

                return $previous;
            }

            $quantity = (int) $data['quantity'];
            $alreadyCharged = EquipmentCharge::where('resource_usage_id', $usage->id)->whereIn('status', ['unpaid', 'paid'])->sum('quantity');
            $this->require($quantity <= $usage->outstanding() - $alreadyCharged, 'The charge quantity exceeds the uncharged outstanding quantity.', 'quantity');
            $charge = EquipmentCharge::create([
                'resource_usage_id' => $usage->id,
                'created_by' => $actor->id,
                'quantity' => $quantity,
                'amount' => $data['amount'],
                'reason' => $this->reasonValue($data),
                'notes' => isset($data['notes']) ? trim($data['notes']) : null,
                'status' => 'unpaid',
                'request_key' => $data['request_key'],
            ]);
            app(AuditLogger::class)->record('charge.created', $charge->load('usage.facility'), null, ['quantity' => $charge->quantity, 'amount' => $charge->amount, 'status' => $charge->status, 'reason' => $charge->reason]);
            $resident = $reservation->user;
            $notification = new EquipmentChargeCreated(['id' => $charge->id, 'equipment' => $facility->name, 'quantity' => $quantity, 'amount' => $charge->amount]);
            DB::afterCommit(function () use ($resident, $notification, $charge) {
                try {
                    $resident?->notify($notification);
                } catch (Throwable $exception) {
                    Log::warning('Equipment charge notification failed', ['charge_id' => $charge->id, 'exception_type' => $exception::class]);
                }
            });

            return $charge;
        }, 3);
    }

    public function update(User $actor, EquipmentCharge $charge, array $input): EquipmentCharge
    {
        Gate::forUser($actor)->authorize('manage', $charge);
        $data = Validator::make($input, [
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'reason' => ['required', Rule::in(self::REASONS)],
            'reason_other' => ['required_if:reason,Other', 'prohibited_unless:reason,Other', 'nullable', 'string', 'max:1900'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['unpaid', 'paid', 'cancelled', 'waived'])],
        ])->validate();

        return DB::transaction(function () use ($actor, $charge, $data) {
            $usage = ResourceUsage::lockForUpdate()->findOrFail($charge->resource_usage_id);
            $facility = Facility::query()->lockForUpdate()->findOrFail($usage->facility_id);
            $usage->setRelation('facility', $facility);
            $charge = EquipmentCharge::lockForUpdate()->findOrFail($charge->id);
            Gate::forUser($actor)->authorize('manage', $charge);
            $this->require($usage->resource_type === 'Equipment' && $facility->category === 'Equipment'
                && $usage->barangay === $facility->barangay
                && $usage->reservation?->facility_id === $facility->id, 'The charge is not linked to a valid equipment borrowing record.', 'charge');
            $alreadyCharged = EquipmentCharge::where('resource_usage_id', $usage->id)->where('status', 'unpaid')->sum('quantity');
            $maximum = $usage->outstanding() - $alreadyCharged + ($charge->status === 'unpaid' ? $charge->quantity : 0);
            $this->require((int) $data['quantity'] <= $maximum, 'The charge quantity exceeds the uncharged outstanding quantity.', 'quantity');
            $paidCents = (int) DB::table('equipment_charge_payments')->where('equipment_charge_id', $charge->id)->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) as cents')->value('cents');
            $this->require($paidCents <= \App\Support\Money::cents((string) $data['amount']), 'The charge amount cannot be lower than the amount already received.', 'amount');
            $this->require($data['status'] !== 'cancelled' || $paidCents === 0, 'A charge with recorded payments cannot be cancelled. Waive the remaining balance if appropriate.', 'status');
            $this->require($data['status'] !== 'paid' || $paidCents === \App\Support\Money::cents((string) $data['amount']), 'A charge can only be marked paid after its full amount has been recorded.', 'status');
            $this->require($paidCents !== \App\Support\Money::cents((string) $data['amount']) || $data['status'] === 'paid', 'A fully settled charge must remain Paid.', 'status');
            $before = $charge->only(['quantity', 'amount', 'reason', 'notes', 'status']);
            $status = $data['status'];
            if ($status === 'unpaid' && $paidCents === \App\Support\Money::cents((string) $data['amount'])) {
                $status = 'paid';
            }
            $charge->fill(['quantity' => $data['quantity'], 'amount' => $data['amount'], 'reason' => $this->reasonValue($data),
                'notes' => isset($data['notes']) ? trim($data['notes']) : null, 'status' => $status])->save();
            $after = $charge->only(['quantity', 'amount', 'reason', 'notes', 'status']);
            if ($before !== $after) {
                $charge->changes()->create(['changed_by' => $actor->id, 'before_values' => $before, 'after_values' => $after, 'created_at' => now()]);
                app(AuditLogger::class)->record('charge.updated', $charge->load('usage.facility'), $before, $after);
            }

            return $charge;
        }, 3);
    }

    private function require(bool $condition, string $message, string $field): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function reasonValue(array $data): string
    {
        return $data['reason'] === 'Other' ? 'Other: '.trim($data['reason_other']) : $data['reason'];
    }
}
