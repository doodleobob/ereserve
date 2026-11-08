<?php

namespace App\Models;

use App\Support\AdminPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class AdminPermissionRestriction extends Model
{
    protected $fillable = ['user_id', 'permission'];

    protected static function booted(): void
    {
        static::saving(function (self $restriction): void {
            $user = $restriction->user ?? User::query()->find($restriction->user_id);
            if (! $user || $user->role !== 'admin') {
                throw ValidationException::withMessages(['user_id' => 'Restrictions can only be assigned to Barangay Admin accounts.']);
            }
            if (! is_string($restriction->permission) || AdminPermission::tryFrom($restriction->permission) === null) {
                throw ValidationException::withMessages(['permission' => 'Choose a supported permission.']);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
