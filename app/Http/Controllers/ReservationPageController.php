<?php

namespace App\Http\Controllers;

use App\Queries\Reservations\ResidentReservationQuery;
use App\Support\AdminReservationQuery;
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
        if ($isAdmin) {
            $request->validate(['search' => ['nullable', 'string', 'max:200'], 'sort' => ['nullable', 'string', 'max:50'], 'direction' => ['nullable', 'string', 'max:16'], 'from_date' => ['nullable', 'date_format:Y-m-d'], 'to_date' => ['nullable', 'date_format:Y-m-d'], 'per_page' => ['nullable', 'integer', 'in:10,25,50,100'], 'page' => ['nullable', 'integer', 'min:1']]);
            $query = AdminReservationQuery::forRequest($request);
            $perPage = $request->filled('per_page') ? $request->integer('per_page') : 10;
            $reservations = $query->paginate($perPage)->withQueryString();
            // Actions can empty the final filtered page; show the last available page instead.
            if ($reservations->isEmpty() && $reservations->currentPage() > $reservations->lastPage()) {
                $reservations = $query->paginate($perPage, ['*'], 'page', $reservations->lastPage())->withQueryString();
            }

            return view('reservations.index', compact('reservations', 'isAdmin') + ['selectedStatus' => $status, 'selectedSort' => $sort, 'selectedSearch' => $search, 'selectedDateRange' => $dateRange, 'selectedFromDate' => $fromDate, 'selectedToDate' => $toDate]);
        }
        $query = ResidentReservationQuery::forRequest($request);

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
