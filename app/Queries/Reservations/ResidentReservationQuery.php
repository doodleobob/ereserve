<?php

namespace App\Queries\Reservations;

use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ResidentReservationQuery
{
    public static function forRequest(Request $request): Builder
    {
        $status = $request->query('status', 'all');
        $sort = $request->query('sort', 'date');
        $search = trim((string) $request->query('search', ''));
        $user = $request->user();
        $query = Reservation::query()->with(['facility', 'officialUseConflicts.officialUse'])
            ->where('reservations.user_id', $user->id);

        if ($request->filled('reservation')) {
            $query->where('reservations.id', $request->integer('reservation'));
        }

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('reservations.facility_name', 'like', '%'.$search.'%')
                    ->orWhere('reservations.purpose', 'like', '%'.$search.'%');
            });
        }

        if (in_array($status, ['pending', 'accepted', 'rejected', 'cancelled'], true)) {
            $query->where('reservations.status', $status);
        }

        match ($sort) {
            'facility' => $query->orderBy('reservations.facility_name')->orderByDesc('reservations.reservation_date'),
            'status' => $query->orderBy('reservations.status')->orderByDesc('reservations.reservation_date'),
            default => $query->orderByDesc('reservations.reservation_date')->orderByDesc('reservations.start_time'),
        };

        return $query;
    }
}
