<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['official_use_id', 'reservation_id', 'resolution', 'decided_at', 'resolved_at', 'resolution_history'])]
class OfficialUseConflict extends Model
{
    public const RESOLUTIONS = [
        'awaiting_user_decision' => 'Awaiting User Decision',
        'reschedule_requested' => 'Reschedule Requested',
        'cancellation_requested' => 'Cancellation Requested',
        'resolved' => 'Resolved',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'resolved_at' => 'datetime', 'resolution_history' => 'array'];
    }

    public function transitionTo(string $resolution, array $attributes = []): void
    {
        if ($this->resolution === $resolution) {
            return;
        }
        $history = [...($this->resolution_history ?? []), [
            'resolution' => $this->resolution,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'at' => now()->toIso8601String(),
        ]];
        $this->update([...$attributes, 'resolution' => $resolution, 'resolution_history' => $history]);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('resolution', '!=', 'resolved');
    }

    public function resolutionLabel(): string
    {
        return self::RESOLUTIONS[$this->resolution];
    }

    public function officialUse(): BelongsTo
    {
        return $this->belongsTo(OfficialUse::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
