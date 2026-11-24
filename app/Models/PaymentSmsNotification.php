<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSmsNotification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_attempted_at' => 'datetime', 'provider_accepted_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
