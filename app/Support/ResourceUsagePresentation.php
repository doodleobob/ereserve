<?php

namespace App\Support;

use App\Models\Reservation;

class ResourceUsagePresentation
{
    public static function actions(Reservation $reservation): array
    {
        $usage = $reservation->resourceUsage;
        $equipment = ($usage?->resource_type ?? $reservation->category) === 'Equipment';
        $actions = [];
        if ($reservation->status === 'accepted') {
            if ($equipment && $reservation->quantity !== null && ($usage?->released_quantity ?? 0) < $reservation->quantity) {
                $actions['release'] = 'Release Equipment';
            } elseif (! $equipment && ! $usage?->actual_started_at) {
                $actions['check_in'] = 'Check In';
            }
        }
        if ($equipment && ($usage?->outstanding() ?? 0) > 0) {
            $actions['return'] = 'Record Return';
        } elseif (! $equipment && $usage?->actual_started_at && ! $usage->actual_ended_at) {
            $actions['check_out'] = 'Check Out';
        }

        return $actions;
    }
}
