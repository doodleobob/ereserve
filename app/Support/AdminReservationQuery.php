<?php

namespace App\Support;

use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AdminReservationQuery
{
    public const SORTS = ['id' => 'reservations.id', 'resident' => 'users.name', 'facility' => 'reservations.facility_name', 'date' => 'reservations.reservation_date', 'time' => 'reservations.start_time', 'amount' => 'reservations.total_payment', 'status' => 'reservations.status'];

    public static function forRequest(Request $request): Builder
    {
        $user = $request->user();
        $query = Reservation::query()->with('facility')->leftJoin('users', 'reservations.user_id', '=', 'users.id')
            ->select('reservations.*', 'users.name as requester_name', 'users.email as requester_email', 'users.phone_number as requester_phone_number', 'users.barangay as requester_barangay');
        if ($user->role !== 'super_admin') {
            $query->inBarangayFor($user);
        }
        if ($request->filled('reservation')) {
            $query->where('reservations.id', $request->integer('reservation'));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', '%'.$search.'%')->orWhere('reservations.facility_name', 'like', '%'.$search.'%')->orWhere('reservations.purpose', 'like', '%'.$search.'%');
                if (ctype_digit(ltrim($search, '#'))) {
                    $q->orWhere('reservations.id', (int) ltrim($search, '#'));
                }
            });
        }
        if (in_array($request->query('status'), ['pending', 'accepted', 'rejected', 'cancelled'], true)) {
            $query->where('reservations.status', $request->query('status'));
        }
        match ($request->query('date_range')) {
            'today' => $query->whereDate('reservations.reservation_date', today()),
            'week' => $query->whereBetween('reservations.reservation_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]),
            'month' => $query->whereBetween('reservations.reservation_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]),
            default => null,
        };
        if ($request->filled('from_date')) {
            $query->whereDate('reservations.reservation_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('reservations.reservation_date', '<=', $request->query('to_date'));
        }
        $column = self::SORTS[$request->query('sort', 'date')] ?? self::SORTS['date'];
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($column, $direction)->orderBy('reservations.id', $direction);
    }
}
