<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Support\FacilityCatalog;
use App\Support\ReservationAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($facility, $request, $validated) {
            if (ReservationAvailability::hasConflict(
                $facility['slug'],
                $validated['reservation_date'],
                $validated['start_time'],
                $validated['end_time'],
            )) {
                throw ValidationException::withMessages([
                    'start_time' => 'This time slot is no longer available. Please select another time.',
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
        });

        return redirect()
            ->route('dashboard', [
                'facility' => $facility['slug'],
                'date' => $validated['reservation_date'],
            ])
            ->with('reservation_status', 'Reservation request submitted successfully.');
    }

    public function accept(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorizeAdmin($request);

        DB::transaction(function () use ($reservation) {
            $reservation->refresh();

            if ($reservation->status !== 'pending') {
                return;
            }

            $hasConflict = Reservation::query()
                ->whereKeyNot($reservation->id)
                ->where('facility_slug', $reservation->facility_slug)
                ->where('reservation_date', $reservation->reservation_date)
                ->where('status', 'approved')
                ->where('start_time', '<', $reservation->end_time)
                ->where('end_time', '>', $reservation->start_time)
                ->exists();

            if ($hasConflict) {
                throw ValidationException::withMessages([
                    'reservation' => 'This reservation conflicts with an already approved reservation.',
                ]);
            }

            $reservation->update(['status' => 'approved']);
        });

        return redirect()
            ->route('reservations.index')
            ->with('reservation_status', 'Reservation accepted successfully.');
    }

    public function reject(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorizeAdmin($request);

        if ($reservation->status === 'pending') {
            $reservation->update(['status' => 'declined']);
        }

        return redirect()
            ->route('reservations.index')
            ->with('reservation_status', 'Reservation rejected successfully.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403);
    }
}
