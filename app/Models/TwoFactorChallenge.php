<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TwoFactorChallenge extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['code_hash', 'binding_hash', 'context_hash', 'destination'];

    protected function casts(): array
    {
        return ['destination' => 'encrypted', 'expires_at' => 'immutable_datetime'];
    }
}
