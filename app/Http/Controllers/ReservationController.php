<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Support\FacilityCatalog;
use App\Support\Money;
use App\Support\ReservationPeriod;
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

        if (! $facility['is_available']) {
            throw ValidationException::withMessages([
                'reservation' => 'This facility is currently unavailable for reservations.',
            ]);
        }

        $validated = $request->validate([
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'purpose' => ['required', 'string', 'max:500'],
            'attendees' => ['required', 'integer', 'min:1', 'max:'.$facility['capacity']],
        ]);

        $period = new ReservationPeriod($validated['reservation_date'], $validated['start_time'], $validated['end_time']);
        if (! $period->isValid()) {
            throw ValidationException::withMessages(['end_time' => 'End Time must be different from Start Time. An earlier End Time ends the next day.']);
        }

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

            $reservation = Reservation::create([
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
                'hourly_rate_snapshot' => Facility::query()->whereKey($facility['id'])->lockForUpdate()->value('hourly_rate'),
            ]);
            User::query()->where('role', 'admin')->where('barangay', $reservation->barangay)
                ->each(fn (User $admin) => $admin->notify(new ReservationActivity($reservation, 'submitted')));
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

        DB::transaction(function () use ($reservation, $request) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->authorizeReservationManagement($request, $reservation);

            if ($reservation->status !== 'pending') {
                return;
            }

            $validated = $request->validate([
                'total_payment' => Money::rules('9999999999.99'),
                'payment_confirmed' => ['required', 'accepted'],
            ], ['payment_confirmed.accepted' => 'Confirm that payment has been received before accepting.', 'payment_confirmed.required' => 'Confirm that payment has been received before accepting.']);
            $reservation->total_payment = $validated['total_payment'];

            if ($reservation->facility_id === null) {
                $reservation->facility_id = Facility::query()
                    ->where('barangay', $reservation->barangay)
                    ->where('slug', $reservation->facility_slug)
                    ->value('id');
            }

            $reservation->status = 'accepted';
            $reservation->save();
            $reservation->user?->notify(new ReservationActivity($reservation, 'accepted'));
        });

        return redirect()
            ->route('reservations.index')
            ->with('reservation_status', 'Reservation accepted successfully.');
    }

    public function reject(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorizeReservationManagement($request, $reservation);

        Reservation::query()->whereKey($reservation->id)->where('status', 'pending')->update(['status' => 'rejected']);

        return redirect()
            ->route('reservations.index')
            ->with('reservation_status', 'Reservation rejected successfully.');
    }

    public function payment(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorizeReservationManagement($request, $reservation);
        $validated = $request->validate(['total_payment' => Money::rules('9999999999.99')]);

        DB::transaction(function () use ($reservation, $request, $validated) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->authorizeReservationManagement($request, $reservation);
            $previous = $reservation->total_payment;
            $reservation->total_payment = $validated['total_payment'];
            $changed = $reservation->isDirty('total_payment');
            $reservation->save();
            if ($changed && $reservation->status === 'accepted') {
                $reservation->user?->notify(new ReservationActivity($reservation, 'payment_updated', $previous));
            }
        });

        return redirect()->route('reservations.index')->with('reservation_status', 'Total Payment saved successfully.');
    }

    private function authorizeReservationManagement(Request $request, Reservation $reservation): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        if ($request->user()->role !== 'super_admin') {
            abort_unless($reservation->barangay === $request->user()->barangay, 403);
        }
    }
}
