<?php

namespace App\Services;

use App\Models\EquipmentCharge;
use App\Models\EquipmentChargePayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EquipmentChargePayments
{
    public function record(User $actor, EquipmentCharge $charge, array $input): EquipmentChargePayment
    {
        Gate::forUser($actor)->authorize('manage', $charge);
        $data = Validator::make($input, [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'request_key' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $charge, $data) {
            $charge = EquipmentCharge::query()->lockForUpdate()->findOrFail($charge->id);
            Gate::forUser($actor)->authorize('manage', $charge);

            $previous = EquipmentChargePayment::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($previous) {
                $same = $previous->equipment_charge_id === $charge->id
                    && $previous->recorded_by === $actor->id
                    && $previous->amount === Money::decimal(Money::cents((string) $data['amount']))
                    && $previous->payment_date->format('Y-m-d') === $data['payment_date'];
                $this->require($same, 'This payment key has already been used for different payment details.', 'request_key');

                return $previous;
            }

            $this->require($charge->status === 'unpaid', 'Payments can only be recorded for unpaid charges.', 'payment');
            $paidCents = (int) DB::table('equipment_charge_payments')->where('equipment_charge_id', $charge->id)->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) as cents')->value('cents');
            $chargeCents = Money::cents((string) $charge->amount);
            $paymentCents = Money::cents((string) $data['amount']);
            $balanceCents = $chargeCents - $paidCents;
            $this->require($balanceCents > 0, 'This equipment charge has no outstanding balance.', 'payment');
            $this->require($paymentCents <= $balanceCents, 'The payment cannot exceed the outstanding charge balance.', 'amount');

            $payment = EquipmentChargePayment::create([
                'equipment_charge_id' => $charge->id,
                'amount' => Money::decimal($paymentCents),
                'payment_date' => $data['payment_date'],
                'recorded_by' => $actor->id,
                'request_key' => $data['request_key'],
            ]);

            if ($paidCents + $paymentCents === $chargeCents) {
                $before = $charge->only(['status']);
                $charge->status = 'paid';
                $charge->save();
                $charge->changes()->create([
                    'changed_by' => $actor->id,
                    'before_values' => $before + ['payment_id' => $payment->id],
                    'after_values' => ['status' => 'paid', 'payment_id' => $payment->id],
                    'created_at' => now(),
                ]);
            }

            return $payment;
        }, 3);
    }

    private function require(bool $condition, string $message, string $field): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
