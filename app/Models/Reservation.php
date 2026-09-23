<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

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
])]
class Reservation extends Model
{
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
