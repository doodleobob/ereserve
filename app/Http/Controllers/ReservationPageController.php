<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReservationPageController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status', 'all');
        $sort = $request->query('sort', 'date');
        $search = trim((string) $request->query('search', ''));
        $dateRange = $request->query('date_range', 'all');
        $fromDate = $request->query('from_date');
        $toDate = $request->query('to_date');

        $user = $request->user();
        $isAdmin = in_array($user->role, ['admin', 'super_admin'], true);
        $query = Reservation::query();

        if ($isAdmin) {
            $query->leftJoin('users', 'reservations.user_id', '=', 'users.id')
                ->select('reservations.*', 'users.name as requester_name');

            if ($user->role !== 'super_admin') {
                $query->where('reservations.barangay', $user->barangay);
            }
        } else {
            $query->where('reservations.user_id', $user->id)
                ->where('reservations.barangay', $user->barangay);
        }

        if ($request->filled('reservation')) {
            $query->where('reservations.id', $request->integer('reservation'));
        }

        if ($search !== '') {
            $query->where(function ($query) use ($search, $isAdmin) {
                $query->where('reservations.facility_name', 'like', '%'.$search.'%')
                    ->orWhere('reservations.purpose', 'like', '%'.$search.'%');

                if ($isAdmin) {
                    $query->orWhere('users.name', 'like', '%'.$search.'%');
                }
            });
        }

        if (in_array($status, ['pending', 'accepted', 'rejected'], true)) {
            $query->where('reservations.status', $status);
        }

        if ($isAdmin && in_array($dateRange, ['today', 'week', 'month'], true)) {
            match ($dateRange) {
                'today' => $query->whereDate('reservations.reservation_date', today()),
                'week' => $query->whereBetween('reservations.reservation_date', [
                    now()->startOfWeek()->toDateString(),
                    now()->endOfWeek()->toDateString(),
                ]),
                'month' => $query->whereBetween('reservations.reservation_date', [
                    now()->startOfMonth()->toDateString(),
                    now()->endOfMonth()->toDateString(),
                ]),
            };
        }

        if ($isAdmin && $fromDate) {
            $query->whereDate('reservations.reservation_date', '>=', $fromDate);
        }

        if ($isAdmin && $toDate) {
            $query->whereDate('reservations.reservation_date', '<=', $toDate);
        }

        match ($sort) {
            'facility' => $query->orderBy('reservations.facility_name')->orderByDesc('reservations.reservation_date'),
            'status' => $query->orderBy('reservations.status')->orderByDesc('reservations.reservation_date'),
            default => $query->orderByDesc('reservations.reservation_date')->orderByDesc('reservations.start_time'),
        };

        return view('reservations.index', [
            'reservations' => $query->get(),
            'selectedStatus' => $status,
            'selectedSort' => $sort,
            'selectedSearch' => $search,
            'selectedDateRange' => $dateRange,
            'selectedFromDate' => $fromDate,
            'selectedToDate' => $toDate,
            'isAdmin' => $isAdmin,
        ]);
    }
}
