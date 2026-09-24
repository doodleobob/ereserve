<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'facility_id',
    'path',
    'sort_order',
])]
class FacilityPhoto extends Model
{
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }
}
