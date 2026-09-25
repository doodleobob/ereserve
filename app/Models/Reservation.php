<?php

namespace App\Models;

use App\Support\Money;
use App\Support\ReservationPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'barangay',
    'facility_id',
    'facility_slug',
    'facility_name',
    'category',
    'location',
    'reservation_date',
    'start_time',
    'end_time',
    'purpose',
    'attendees',
    'status',
    'hourly_rate_snapshot',
    'total_payment',
])]
class Reservation extends Model
{
    public function scopeInBarangayFor(Builder $query, User $user): Builder
    {
        return $user->role === 'super_admin' ? $query : $query->where('barangay', $user->barangay);
    }

    protected function casts(): array
    {
        return ['hourly_rate_snapshot' => 'decimal:2', 'total_payment' => 'decimal:2'];
    }

    public function durationMinutes(): int
    {
        return $this->period()->durationMinutes();
    }

    public function period(): ReservationPeriod
    {
        return new ReservationPeriod($this->reservation_date, $this->start_time, $this->end_time);
    }

    public function calculatedAmount(): ?string
    {
        if ($this->hourly_rate_snapshot === null) {
            return null;
        }

        // Round fractional hours half-up to the nearest cent using integer arithmetic.
        $cents = intdiv(Money::cents($this->hourly_rate_snapshot) * $this->durationMinutes() + 30, 60);

        return Money::decimal($cents);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
