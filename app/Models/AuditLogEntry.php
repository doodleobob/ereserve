<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'actor_name', 'actor_role', 'action', 'target_type', 'target_id', 'barangay', 'description', 'before_values', 'after_values', 'reason', 'occurred_at'])]
class AuditLogEntry extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'occurred_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
