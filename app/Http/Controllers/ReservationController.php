<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Support\FacilityCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function store(Request $request, string $slug): RedirectResponse
    {
        $facility = FacilityCatalog::find($slug);

        abort_if($facility === null, 404);

        $validated = $request->validate([
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'purpose' => ['required', 'string', 'max:500'],
            'attendees' => ['required', 'integer', 'min:1', 'max:'.$facility['capacity']],
        ]);

        $hasConflict = Reservation::query()
            ->where('facility_slug', $facility['slug'])
            ->where('reservation_date', $validated['reservation_date'])
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_time', '<', $validated['end_time'])
            ->where('end_time', '>', $validated['start_time'])
            ->exists();

        if ($hasConflict) {
            throw ValidationException::withMessages([
                'start_time' => 'This facility already has a reservation request for the selected time.',
            ]);
        }

        Reservation::create([
            'user_id' => $request->user()->id,
            'facility_slug' => $facility['slug'],
            'facility_name' => $facility['name'],
            'category' => $facility['category'],
            'location' => $facility['location'],
            'reservation_date' => $validated['reservation_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'purpose' => $validated['purpose'],
            'attendees' => $validated['attendees'],
            'status' => 'pending',
        ]);

        return redirect()
            ->route('facilities.show', $facility['slug'])
            ->with('reservation_status', 'Reservation request submitted successfully.');
    }
}
