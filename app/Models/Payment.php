<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reservation_id', 'amount', 'payment_status', 'refunded_at', 'refunded_by', 'revision', 'history'])]
class Payment extends Model
{
    public const STATUSES = ['pending', 'paid', 'refund_pending', 'refunded', 'cancelled'];

    public static function statusLabel(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'refunded_at' => 'datetime', 'revision' => 'integer', 'history' => 'array'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function scopeInBarangayFor(Builder $query, User $user): Builder
    {
        return $query->whereHas('reservation', fn (Builder $reservation) => $reservation->inBarangayFor($user));
    }

    public function allowedStatuses(): array
    {
        return match ($this->payment_status) {
            'pending' => ['pending', 'paid', 'cancelled'],
            'paid' => ['paid', 'refund_pending'],
            'refund_pending' => ['refund_pending', 'refunded'],
            default => [$this->payment_status],
        };
    }
}
