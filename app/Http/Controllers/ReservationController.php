<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Support\EquipmentAvailabilitySummary;
use App\Support\FacilityCatalog;
use App\Support\HourlyBooking;
use App\Support\Money;
use App\Support\OfficialUseScheduling;
use App\Support\PaymentRecords;
use App\Support\ReservationAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function store(Request $request, string $slug): RedirectResponse|JsonResponse
    {
        $facility = FacilityCatalog::findForUser($slug, $request->user());

        abort_if($facility === null, 404);
        abort_unless($facility['can_reserve'], 403, 'Residents Only');

        if (! $facility['is_available']) {
            throw ValidationException::withMessages([
                'reservation' => 'This facility is currently unavailable for reservations.',
            ]);
        }

        $validated = $request->validate([
            ...HourlyBooking::rules(),
            'purpose' => ['required', 'string', 'max:500'],
            'attendees' => [$facility['category'] === 'Equipment' ? 'exclude' : 'required', 'integer', 'min:1', 'max:'.$facility['capacity']],
            'quantity' => [$facility['category'] === 'Equipment' ? 'required' : 'exclude', 'integer', 'min:1', 'max:4294967295'],
        ], ['attendees.max' => "The number of attendees exceeds this facility's maximum capacity of {$facility['capacity']} people."]);

        DB::transaction(function () use ($facility, $request, $validated) {
            $resource = Facility::query()->lockForUpdate()->findOrFail($facility['id']);
            if ($resource->category !== $facility['category']) {
                throw ValidationException::withMessages(['reservation' => 'This resource changed while you were booking. Refresh the page and try again.']);
            }
            abort_unless($resource->allowsReservationsBy($request->user()), 403, 'Residents Only');
            if ($resource->status !== 'Available') {
                throw ValidationException::withMessages(['reservation' => 'This facility is currently unavailable for reservations.']);
            }

            $period = HourlyBooking::period($validated);
            if ($resource->isEquipment()) {
                ReservationAvailability::validateQuantity($resource, $period, (int) $validated['quantity']);
            } else {
                ReservationAvailability::validateAttendees($resource, (int) $validated['attendees']);
                ReservationAvailability::validateBooking($resource, $period);
            }

            if (Reservation::query()
                ->where('user_id', $request->user()->id)
                ->where('facility_id', $facility['id'])
                ->whereIn('status', ['pending', 'accepted'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'reservation' => 'You already have an active reservation for this '.($resource->isEquipment() ? 'equipment' : 'facility').'.',
                ]);
            }

            $reservation = Reservation::create([
                'user_id' => $request->user()->id,
                'barangay' => $resource->barangay,
                'facility_id' => $facility['id'],
                'facility_slug' => $facility['slug'],
                'facility_name' => $facility['name'],
                'category' => $facility['category'],
                'location' => $facility['location'],
                'reservation_date' => $validated['reservation_date'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'purpose' => $validated['purpose'],
                'attendees' => $validated['attendees'] ?? null,
                'quantity' => $validated['quantity'] ?? null,
                'status' => 'pending',
                'hourly_rate_snapshot' => $resource->hourly_rate,
            ]);
            User::query()->where('role', 'admin')->where('barangay', $reservation->barangay)
                ->each(fn (User $admin) => $admin->notify(new ReservationActivity($reservation, 'submitted')));
            $request->user()->notify(new ReservationActivity($reservation, 'submission_confirmed'));
        });

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Reservation request submitted successfully.'], 201);
        }

        return redirect()
            ->route(FacilityCatalog::detailRoute($facility['category']), [
                'slug' => $facility['slug'],
                'date' => $validated['reservation_date'],
            ])
            ->with('reservation_status', 'Reservation request submitted successfully.');
    }

    public function equipmentAvailability(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize('viewEquipmentAvailability', $reservation);
        abort_unless($reservation->category === 'Equipment', 404);

        return DB::transaction(function () use ($reservation) {
            $resource = $reservation->facility ?? Facility::where('barangay', $reservation->barangay)->where('slug', $reservation->facility_slug)->first();
            $summary = EquipmentAvailabilitySummary::forReservation($reservation, $resource);

            return response()->json(['summary' => $summary, 'html' => view('shared.reservations.partials.equipment-availability', compact('summary'))->render()])
                ->header('Cache-Control', 'private, no-store');
        });
    }

    public function accept(Request $request, Reservation $reservation): RedirectResponse|JsonResponse
    {
        Gate::authorize('accept', $reservation);

        DB::transaction(function () use ($reservation, $request) {
            // Use the same resource lock as rescheduling before changing accepted occupancy.
            $resource = Facility::query()->when($reservation->facility_id !== null,
                fn ($query) => $query->whereKey($reservation->facility_id),
                fn ($query) => $query->where('barangay', $reservation->barangay)->where('slug', $reservation->facility_slug)
            )->lockForUpdate()->first();
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            Gate::authorize('accept', $reservation);

            if ($reservation->status !== 'pending') {
                if ($request->expectsJson()) {
                    throw ValidationException::withMessages(['reservation' => 'This reservation is no longer pending. Refresh the table to review its current status.']);
                }

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

            if ($resource !== null) {
                ReservationAvailability::validateAttendees($resource, (int) $reservation->attendees);
                ReservationAvailability::validateBooking($resource, $reservation->period(), $reservation->id, $reservation->quantity);
            }

            if ($reservation->category === 'Equipment' || $resource?->isEquipment()) {
                $summary = EquipmentAvailabilitySummary::forReservation($reservation, $resource);
                if (! $summary['can_accept']) {
                    throw ValidationException::withMessages(['reservation' => $summary['blocking_reason']]);
                }
                // Physical return risks are advisory: scheduled stock still determines acceptance.
            }

            $reservation->status = 'accepted';
            $reservation->save();
            PaymentRecords::recordAcceptance($reservation, $request->user());
            $reservation->user?->notify(new ReservationActivity($reservation, 'accepted'));
        });

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Reservation accepted successfully.']);
        }

        return redirect()
            ->route('reservations.index')
            ->with('reservation_status', 'Reservation accepted successfully.');
    }

    public function reject(Request $request, Reservation $reservation): RedirectResponse|JsonResponse
    {
        $this->authorizeReservationManagement($request, $reservation);

        DB::transaction(function () use ($request, $reservation) {
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);
            $this->authorizeReservationManagement($request, $reservation);
            if ($reservation->status !== 'pending') {
                throw ValidationException::withMessages(['reservation' => 'Only pending reservations can be rejected.']);
            }
            if ($request->expectsJson()) {
                $request->validate(['rejection_confirmed' => ['required', 'accepted']]);
            }
            $reservation->update(['status' => 'rejected']);
            $reservation->user?->notify(new ReservationActivity($reservation, 'rejected'));
        });

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Reservation rejected successfully.']);
        }

        return redirect()
            ->route('reservations.index')
            ->with('reservation_status', 'Reservation rejected successfully.');
    }

    public function editAccepted(Request $request, Reservation $reservation): RedirectResponse|JsonResponse
    {
        $this->authorizeReservationManagement($request, $reservation);

        $message = DB::transaction(function () use ($request, $reservation) {
            // Serialize schedule changes for this resource, including legacy reservations.
            $resource = Facility::query()->when($reservation->facility_id !== null,
                fn ($query) => $query->whereKey($reservation->facility_id),
                fn ($query) => $query->where('barangay', $reservation->barangay)->where('slug', $reservation->facility_slug)
            )->lockForUpdate()->first();
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);
            $this->authorizeReservationManagement($request, $reservation);
            if ($reservation->status !== 'accepted') {
                throw ValidationException::withMessages(['reservation' => 'Only accepted reservations can be edited.']);
            }

            $request->validate(['action' => ['required', 'in:reschedule,cancel']]);
            $before = $reservation->only(['reservation_date', 'start_time', 'end_time', 'status', 'total_payment']);
            $scheduleChanged = false;
            if ($request->input('action') === 'reschedule') {
                $validated = $request->validate([
                    ...HourlyBooking::rules(),
                ]);
                $period = HourlyBooking::period($validated, existing: $reservation->period());
                if ($resource === null || $resource->status !== 'Available') {
                    throw ValidationException::withMessages(['reservation' => 'This resource is currently unavailable for reservations.']);
                }
                ReservationAvailability::validateBooking($resource, $period, $reservation->id, $reservation->quantity);
                HourlyBooking::preserveUnchangedTimes($validated, $reservation->period());
                $reservation->fill($validated);
                $scheduleChanged = $before['reservation_date'] !== $reservation->reservation_date
                    || substr($before['start_time'], 0, 5) !== substr($reservation->start_time, 0, 5)
                    || substr($before['end_time'], 0, 5) !== substr($reservation->end_time, 0, 5);
                $message = 'Reservation rescheduled successfully.';
            } else {
                $validated = $request->validate([
                    'cancellation_confirmed' => ['required', 'accepted'],
                    'cancellation_reason' => ['required', 'in:User Requested Cancellation,Official Use,Other'],
                    'cancellation_notes' => ['nullable', 'string', 'max:1000'],
                ]);
                $reservation->fill([
                    'status' => 'cancelled',
                    'cancellation_reason' => $validated['cancellation_reason'],
                    'cancellation_notes' => $validated['cancellation_notes'] ?? null,
                    'cancelled_at' => now(),
                ]);
                $message = 'Reservation cancelled successfully.';
            }
            $reservation->change_history = [...($reservation->change_history ?? []), [
                'action' => $request->input('action'), 'actor_id' => $request->user()->id, 'actor_name' => $request->user()->name,
                'at' => now()->toIso8601String(), 'before' => $before,
                'after' => $reservation->only(['reservation_date', 'start_time', 'end_time', 'status', 'total_payment']),
                'reason' => $reservation->cancellation_reason, 'notes' => $reservation->cancellation_notes,
            ]];
            $reservation->save();
            OfficialUseScheduling::resolveForReservation($reservation);
            if ($resource !== null) {
                OfficialUseScheduling::refreshEquipmentConflicts($resource, $request->user());
            }
            if ($reservation->status === 'cancelled') {
                PaymentRecords::recordCancellation($reservation, $request->user());
                $reservation->user?->notify(new ReservationActivity($reservation, 'cancelled'));
            } elseif ($scheduleChanged) {
                $reservation->user?->notify(new ReservationActivity($reservation, 'rescheduled', previousSchedule: $before));
            }

            return $message;
        });

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message])
            : redirect()->route('reservations.index')->with('reservation_status', $message);
    }

    public function payment(Request $request, Reservation $reservation): RedirectResponse|JsonResponse
    {
        // This legacy amount-correction endpoint is also a payment mutation.
        abort_unless($request->user()->role === 'admin', 403);
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

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Total Payment saved successfully.']);
        }

        return redirect()->route('reservations.index')->with('reservation_status', 'Total Payment saved successfully.');
    }

    private function authorizeReservationManagement(Request $request, Reservation $reservation): void
    {
        Gate::authorize('manage', $reservation);
    }
}
