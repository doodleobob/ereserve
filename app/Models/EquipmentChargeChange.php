<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['equipment_charge_id', 'changed_by', 'before_values', 'after_values', 'created_at'])]
class EquipmentChargeChange extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'created_at' => 'datetime'];
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(EquipmentCharge::class, 'equipment_charge_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
