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
])]
class Facility extends Model
{
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
