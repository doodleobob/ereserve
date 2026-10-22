<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResourceUsage extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['not_started' => 'Not Started', 'in_use' => 'In Use', 'completed' => 'Completed', 'not_released' => 'Not Released', 'borrowed' => 'Borrowed', 'partially_returned' => 'Partially Returned', 'returned' => 'Returned'];

    protected function casts(): array
    {
        return ['approved_quantity' => 'integer', 'released_quantity' => 'integer', 'returned_quantity' => 'integer', 'actual_started_at' => 'datetime', 'actual_ended_at' => 'datetime'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ResourceUsageEvent::class)->orderBy('id');
    }

    public function outstanding(): int
    {
        return $this->released_quantity - $this->returned_quantity;
    }

    public function status(): string
    {
        if ($this->resource_type === 'Facility') {
            return $this->actual_ended_at ? 'completed' : ($this->actual_started_at ? 'in_use' : 'not_started');
        }

        return match (true) {
            $this->released_quantity === 0 => 'not_released',
            $this->returned_quantity === $this->approved_quantity => 'returned',
            $this->returned_quantity > 0 => 'partially_returned',
            default => 'borrowed',
        };
    }

    public function overdue(): bool
    {
        return $this->reservation->period()->end->lt(now())
            && ($this->resource_type === 'Equipment' ? $this->outstanding() > 0 : $this->actual_started_at !== null && $this->actual_ended_at === null);
    }
}
