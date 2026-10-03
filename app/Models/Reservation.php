<?php

namespace App\Models;

use App\Support\Money;
use App\Support\ReservationPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    'cancellation_reason',
    'cancellation_notes',
    'cancelled_at',
    'change_history',
])]
class Reservation extends Model
{
    // Preserve the existing stored financial field; reporting uses Reservation.total.
    public function getTotalAttribute(mixed $value): mixed
    {
        // Existing dashboard/analytics queries also select aggregate counts AS total.
        return array_key_exists('total', $this->attributes) ? $value : $this->total_payment;
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function scopeForFacility(Builder $query, Facility $facility): Builder
    {
        return $query->where(function (Builder $query) use ($facility) {
            $query->where('facility_id', $facility->id)->orWhere(function (Builder $legacy) use ($facility) {
                $legacy->whereNull('facility_id')->where('facility_slug', $facility->slug)->where('barangay', $facility->barangay);
            });
        });
    }

    public function scopeInBarangayFor(Builder $query, User $user): Builder
    {
        return $user->role === 'super_admin' ? $query : $query->where(function (Builder $query) use ($user) {
            $query->whereHas('facility', fn (Builder $facility) => $facility->where('barangay', $user->barangay))
                ->orWhere(function (Builder $legacy) use ($user) {
                    $legacy->whereNull('reservations.facility_id')->where('reservations.barangay', $user->barangay);
                });
        });
    }

    public function managingBarangay(): string
    {
        return $this->facility?->barangay ?? $this->barangay;
    }

    protected function casts(): array
    {
        return ['hourly_rate_snapshot' => 'decimal:2', 'total_payment' => 'decimal:2', 'cancelled_at' => 'datetime', 'change_history' => 'array'];
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

    public function officialUseConflicts(): HasMany
    {
        return $this->hasMany(OfficialUseConflict::class);
    }
}
