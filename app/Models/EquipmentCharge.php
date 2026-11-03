<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['resource_usage_id', 'created_by', 'quantity', 'amount', 'reason', 'notes', 'status', 'request_key'])]
class EquipmentCharge extends Model
{
    public const STATUSES = ['unpaid' => 'Unpaid', 'paid' => 'Paid', 'cancelled' => 'Cancelled', 'waived' => 'Waived'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'amount' => 'decimal:2'];
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(ResourceUsage::class, 'resource_usage_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(EquipmentChargeChange::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EquipmentChargePayment::class)->orderBy('payment_date')->orderBy('id');
    }
}
