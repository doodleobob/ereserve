<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Support\HourlyBooking;
use App\Support\ReservationAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PendingReservationController extends Controller
{
    public function __invoke(Request $request, Reservation $reservation): JsonResponse|RedirectResponse
    {
        Gate::authorize('editOwnRequest', $reservation);

        $changed = DB::transaction(function () use ($request, $reservation) {
            // Match acceptance/Official Use lock order: resource, then fresh reservation.
            $resource = Facility::query()->when($reservation->facility_id !== null,
                fn ($query) => $query->whereKey($reservation->facility_id),
                fn ($query) => $query->where('barangay', $reservation->barangay)->where('slug', $reservation->facility_slug)
            )->lockForUpdate()->first();
            $current = Reservation::lockForUpdate()->findOrFail($reservation->id);
            Gate::authorize('editOwnRequest', $current);
            if ($current->status !== 'pending') {
                throw ValidationException::withMessages(['reservation' => 'This reservation is no longer pending. Refresh the page to review its current status.']);
            }
            if ($current->only(['facility_id', 'barangay', 'facility_slug']) !== $reservation->only(['facility_id', 'barangay', 'facility_slug'])) {
                throw ValidationException::withMessages(['reservation' => 'This reservation changed while editing. Refresh the page and try again.']);
            }
            if ($resource === null || $resource->status !== 'Available') {
                throw ValidationException::withMessages(['reservation' => 'This resource is currently unavailable for reservations.']);
            }
            abort_unless($resource->allowsReservationsBy($request->user()), 403, 'Residents Only');

            // Only request details can be filled; inventory, payment and status are never writable here.
            $validated = $request->validate([
                ...HourlyBooking::rules(),
                'purpose' => ['required', 'string', 'max:500'],
                'attendees' => [$resource->isEquipment() ? 'exclude' : 'required', 'integer', 'min:1', 'max:'.$resource->capacity],
                'quantity' => [$resource->isEquipment() ? ($current->quantity === null ? 'nullable' : 'required') : 'exclude', 'integer', 'min:1', 'max:4294967295'],
            ], ['attendees.max' => "The number of attendees exceeds this facility's maximum capacity of {$resource->capacity} people."]);
            $period = HourlyBooking::period($validated, existing: $current->period());
            if ($resource->isEquipment()) {
                ReservationAvailability::validateBooking($resource, $period, $current->id, isset($validated['quantity']) ? (int) $validated['quantity'] : null);
            } else {
                ReservationAvailability::validateBooking($resource, $period, $current->id);
            }
            // Pending requests do not allocate stock, but must respect accepted occupancy.
            $previousPeriod = $current->period();
            if ($previousPeriod->start->eq($period->start) && $previousPeriod->end->eq($period->end)
                && $current->purpose === $validated['purpose']
                && ($resource->isEquipment() ? (int) $current->quantity === (int) ($validated['quantity'] ?? null) : (int) $current->attendees === (int) $validated['attendees'])) {
                return false;
            }

            $fields = ['reservation_date', 'start_time', 'end_time', 'purpose', 'attendees'];
            if ($resource->isEquipment()) {
                $fields[] = 'quantity';
            }
            $before = $current->only($fields);
            HourlyBooking::preserveUnchangedTimes($validated, $current->period());
            $current->fill($validated);
            $current->change_history = [...($current->change_history ?? []), [
                'action' => 'pending_edit', 'actor_id' => $request->user()->id, 'actor_name' => $request->user()->name,
                'at' => now()->toIso8601String(), 'before' => $before, 'after' => $current->only($fields),
                'reason' => null, 'notes' => null,
            ]];
            $current->save();
            $current->setRelation('facility', $resource);
            User::query()->where('role', 'admin')->where('barangay', $resource->barangay)
                ->each(fn (User $admin) => $admin->notify(new ReservationActivity($current, 'pending_updated')));

            return true;
        });

        $message = $changed ? 'Reservation request updated successfully.' : 'No changes were made to this reservation.';

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message])
            : redirect()->route('reservations.index')->with('reservation_status', $message);
    }
}
