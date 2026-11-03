<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['equipment_charge_id', 'amount', 'payment_date', 'recorded_by', 'request_key'])]
class EquipmentChargePayment extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Equipment charge payment records are immutable.'));
        static::deleting(fn () => throw new LogicException('Equipment charge payment records are immutable.'));
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(EquipmentCharge::class, 'equipment_charge_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
