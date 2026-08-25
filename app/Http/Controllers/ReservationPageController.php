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

        $query = Reservation::query()
            ->where('user_id', $request->user()->id);

        if (in_array($status, ['pending', 'approved', 'declined'], true)) {
            $query->where('status', $status);
        }

        match ($sort) {
            'facility' => $query->orderBy('facility_name')->orderByDesc('reservation_date'),
            'status' => $query->orderBy('status')->orderByDesc('reservation_date'),
            default => $query->orderByDesc('reservation_date')->orderByDesc('start_time'),
        };

        return view('reservations.index', [
            'reservations' => $query->get(),
            'selectedStatus' => $status,
            'selectedSort' => $sort,
        ]);
    }
}
