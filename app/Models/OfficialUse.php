<?php

namespace App\Models;

use App\Support\ReservationPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['barangay', 'facility_id', 'date', 'start_time', 'end_time', 'purpose', 'status', 'created_by'])]
class OfficialUse extends Model
{
    public function scopeInBarangayFor(Builder $query, User $user): Builder
    {
        return $user->role === 'super_admin' ? $query : $query->where('official_uses.barangay', $user->barangay);
    }

    public function period(): ReservationPeriod
    {
        return new ReservationPeriod($this->date, $this->start_time, $this->end_time);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(OfficialUseConflict::class);
    }
}
