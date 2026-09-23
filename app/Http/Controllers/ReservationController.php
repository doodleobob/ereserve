<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\Reservation;
use App\Support\FacilityCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function store(Request $request, string $slug): RedirectResponse
    {
        $facility = FacilityCatalog::findForUser($slug, $request->user());

        abort_if($facility === null, 404);
        abort_unless($facility['barangay'] === $request->user()->barangay, 403);

        $validated = $request->validate([
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'purpose' => ['required', 'string', 'max:500'],
            'attendees' => ['required', 'integer', 'min:1', 'max:'.$facility['capacity']],
        ]);

        DB::transaction(function () use ($facility, $request, $validated) {
            if (Reservation::query()
                ->where('user_id', $request->user()->id)
                ->where('facility_id', $facility['id'])
                ->whereIn('status', ['pending', 'accepted'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'reservation' => 'You already have an active reservation for this facility.',
                ]);
            }

            Reservation::create([
                'user_id' => $request->user()->id,
                'barangay' => $request->user()->barangay,
                'facility_id' => $facility['id'],
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
        $this->authorizeReservationManagement($request, $reservation);

        DB::transaction(function () use ($reservation) {
            $reservation->refresh();

            if ($reservation->status !== 'pending') {
                return;
            }

            if ($reservation->facility_id === null) {
                $reservation->facility_id = Facility::query()
                    ->where('barangay', $reservation->barangay)
                    ->where('slug', $reservation->facility_slug)
                    ->value('id');
            }

            $reservation->status = 'accepted';
            $reservation->save();
        });

        return redirect()
            ->route('reservations.index')
            ->with('reservation_status', 'Reservation accepted successfully.');
    }

    public function reject(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorizeReservationManagement($request, $reservation);

        if ($reservation->status === 'pending') {
            $reservation->update(['status' => 'rejected']);
        }

        return redirect()
            ->route('reservations.index')
            ->with('reservation_status', 'Reservation rejected successfully.');
    }

    private function authorizeReservationManagement(Request $request, Reservation $reservation): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        if ($request->user()->role !== 'super_admin') {
            abort_unless($reservation->barangay === $request->user()->barangay, 403);
        }
    }
}
