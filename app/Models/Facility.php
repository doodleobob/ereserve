<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'barangay',
    'slug',
    'name',
    'category',
    'description',
    'photo_path',
    'capacity',
    'location',
    'status',
    'hourly_rate',
    'reservation_access',
])]
class Facility extends Model
{
    protected $attributes = ['reservation_access' => 'residents_only'];

    public function allowsReservationsBy(User $user): bool
    {
        return $this->reservation_access === 'all_registered_users' || $this->barangay === $user->barangay;
    }

    protected function casts(): array
    {
        return ['hourly_rate' => 'decimal:2'];
    }

    public function photos(): HasMany
    {
        return $this->hasMany(FacilityPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
