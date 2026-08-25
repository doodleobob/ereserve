<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
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
}
