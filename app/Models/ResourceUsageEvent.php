<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ResourceUsageEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'occurred_at' => 'datetime', 'scheduled_start' => 'datetime', 'scheduled_end' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Usage events are immutable.'));
        static::deleting(fn () => throw new LogicException('Usage events are immutable.'));
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(ResourceUsage::class, 'resource_usage_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
