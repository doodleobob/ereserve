<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OfficialUseScheduling
{
    public static function schedules(int $facilityId, Carbon $start, Carbon $end, ?string $barangay = null, ?int $exceptOfficialUseId = null): Collection
    {
        return OfficialUse::query()->where('facility_id', $facilityId)
            ->when($barangay !== null, fn ($query) => $query->where('barangay', $barangay))
            ->whereIn('status', ['active', 'conflict'])
            ->when($exceptOfficialUseId !== null, fn ($query) => $query->whereKeyNot($exceptOfficialUseId))
            ->whereBetween('date', [$start->copy()->subDay()->toDateString(), $end->toDateString()])
            ->get()->filter(fn (OfficialUse $use) => $use->period()->overlaps($start, $end))->values();
    }

    public static function validateAvailability(Facility $facility, ReservationPeriod $period): void
    {
        $schedules = self::schedules($facility->id, $period->start, $period->end, $facility->barangay);
        if ($facility->isEquipment() && $facility->total_quantity !== null) {
            if (ReservationAvailability::peakAllocation($facility, $schedules, $period->start, $period->end) < $facility->total_quantity) {
                return;
            }
        }
        if ($schedules->isNotEmpty()) {
            throw ValidationException::withMessages(['reservation' => 'This resource is unavailable for the selected date and time due to Official Use.']);
        }
    }

    // Caller holds the facility lock and transaction shared with reservation creation/editing.
    public static function create(Facility $facility, array $data, User $actor): OfficialUse
    {
        $data['quantity'] = self::validatedQuantity($facility, $data);
        $period = self::validateSchedule($facility, $data);
        unset($data['end_next_day']);
        $use = OfficialUse::create([
            ...$data, 'facility_id' => $facility->id, 'barangay' => $facility->barangay,
            'created_by' => $actor->id, 'status' => 'active',
        ]);
        self::synchronizeConflicts($use, $facility, $period, $actor);
        self::refreshEquipmentConflicts($facility, $actor, $use->id);

        return $use;
    }

    // Caller holds both old/new facility locks (in ID order), the schedule lock, and a transaction.
    public static function update(OfficialUse $use, Facility $facility, array $data, User $actor): OfficialUse
    {
        $oldFacility = $use->facility;
        $data['quantity'] = self::validatedQuantity($facility, $data, $use);
        $period = self::validateSchedule($facility, $data, $use->id, $use->facility_id === $facility->id ? $use->period() : null);
        if ($use->facility_id === $facility->id) {
            HourlyBooking::preserveUnchangedTimes($data, $use->period(), 'date');
        } else {
            unset($data['end_next_day']);
        }
        $use->fill([...$data, 'facility_id' => $facility->id, 'barangay' => $facility->barangay])->save();
        self::synchronizeConflicts($use, $facility, $period, $actor);
        self::refreshEquipmentConflicts($facility, $actor, $use->id);
        if ($oldFacility->id !== $facility->id) {
            self::refreshEquipmentConflicts($oldFacility, $actor);
        }

        return $use;
    }

    private static function validateSchedule(Facility $facility, array $data, ?int $exceptOfficialUseId = null, ?ReservationPeriod $existing = null): ReservationPeriod
    {
        $period = HourlyBooking::period($data, 'date', $existing);
        $others = self::schedules($facility->id, $period->start, $period->end, $facility->barangay, $exceptOfficialUseId);
        $overlaps = $facility->isEquipment() && $facility->total_quantity !== null
            ? ReservationAvailability::peakAllocation($facility, $others, $period->start, $period->end) + ($data['quantity'] ?? $facility->total_quantity) > $facility->total_quantity
            : $others->isNotEmpty();
        if ($overlaps) {
            throw ValidationException::withMessages(['date' => 'This schedule overlaps an existing Official Use for this resource.']);
        }

        return $period;
    }

    private static function validatedQuantity(Facility $facility, array $data, ?OfficialUse $existing = null): ?int
    {
        if (! $facility->isEquipment()) {
            return null;
        }
        // Editing an existing exclusive hold may retain that contract; never invent a unit count.
        if ($existing && $existing->facility_id === $facility->id && $existing->quantity === null && empty($data['quantity'])) {
            return null;
        }
        if ($facility->total_quantity === null || empty($data['quantity']) || $data['quantity'] > $facility->total_quantity) {
            throw ValidationException::withMessages(['quantity' => 'Enter a positive Quantity Requested within the verified Total Quantity for this equipment.']);
        }

        return (int) $data['quantity'];
    }

    /** Reconcile all related uses when shared stock is freed or an Official Use changes. Caller holds the resource lock. */
    public static function refreshEquipmentConflicts(Facility $facility, User $actor, ?int $exceptId = null): void
    {
        if (! $facility->isEquipment()) {
            return;
        }
        foreach ($facility->officialUses()->whereIn('status', ['active', 'conflict'])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))->orderBy('id')->get() as $use) {
            self::synchronizeConflicts($use, $facility, $use->period(), $actor);
        }
    }

    private static function quantityConflict(Facility $facility, OfficialUse $use, Reservation $reservation): bool
    {
        if (! $facility->isEquipment() || $facility->total_quantity === null) {
            return true;
        }
        $period = $reservation->period();
        // Only affect requests whose overlap with this use lacks stock. A shortage elsewhere
        // in a longer request is not caused by this Official Use.
        $start = $period->start->max($use->period()->start);
        $end = $period->end->min($use->period()->end);
        $schedules = ReservationAvailability::acceptedReservations($facility->id, $start, $end, $facility->barangay)
            ->concat(self::schedules($facility->id, $start, $end, $facility->barangay));

        $allocation = ReservationAvailability::peakAllocation($facility, $schedules, $start, $end);
        if ($reservation->status === 'pending') {
            $allocation += $reservation->quantity ?? $facility->total_quantity;
        }

        return $allocation > $facility->total_quantity;
    }

    private static function synchronizeConflicts(OfficialUse $use, Facility $facility, ReservationPeriod $period, User $actor): void
    {
        $reservations = Reservation::query()->with('user')->forFacility($facility)
            ->whereIn('status', ['pending', 'accepted'])
            ->whereBetween('reservation_date', [$period->start->copy()->subDay()->toDateString(), $period->end->toDateString()])
            ->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (Reservation $reservation) => $reservation->period()->overlaps($period->start, $period->end)
                && self::quantityConflict($facility, $use, $reservation));
        $acceptedIds = $reservations->where('status', 'accepted')->pluck('id');
        $conflicts = $use->conflicts()->orderBy('id')->lockForUpdate()->get()->keyBy('reservation_id');
        foreach ($conflicts as $conflict) {
            if ($conflict->resolution !== 'resolved' && ! $acceptedIds->contains($conflict->reservation_id)) {
                // Resolve only the relationship; the resident's Accepted booking stays untouched.
                $conflict->transitionTo('resolved', ['resolved_at' => now()]);
            }
        }
        foreach ($reservations as $reservation) {
            if ($reservation->status === 'pending') {
                $before = $reservation->only(['reservation_date', 'start_time', 'end_time', 'status', 'total_payment']);
                $reservation->fill(['status' => 'cancelled', 'cancellation_reason' => 'Official Use', 'cancelled_at' => now()]);
                $reservation->change_history = [...($reservation->change_history ?? []), [
                    'action' => 'cancel', 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'at' => now()->toIso8601String(),
                    'before' => $before, 'after' => $reservation->only(array_keys($before)), 'reason' => 'Official Use',
                    'notes' => null, 'official_use_id' => $use->id,
                ]];
                $reservation->save();
                $reservation->user?->notify(new ReservationActivity($reservation, 'official_use_cancelled'));
            } else {
                $conflict = $conflicts->get($reservation->id);
                if ($conflict === null) {
                    $use->conflicts()->create(['reservation_id' => $reservation->id, 'resolution' => 'awaiting_user_decision']);
                } elseif ($conflict->resolution === 'resolved') {
                    // A later move back creates a new conflict occurrence while retaining prior history.
                    $conflict->transitionTo('awaiting_user_decision', ['decided_at' => null, 'resolved_at' => null]);
                } else {
                    continue; // Still overlaps: keep the resident's preference and do not renotify.
                }
                $reservation->user?->notify(new ReservationActivity($reservation, 'official_use_conflict'));
            }
        }
        self::recalculate($use);

    }

    public static function resolveForReservation(Reservation $reservation): void
    {
        foreach ($reservation->officialUseConflicts()->unresolved()->with('officialUse')->get() as $conflict) {
            $use = $conflict->officialUse;
            if ($reservation->status !== 'accepted' || ! $reservation->period()->overlaps($use->period()->start, $use->period()->end)) {
                $conflict->transitionTo('resolved', ['resolved_at' => now()]);
            }
            self::recalculate($use);
        }
    }

    public static function recalculate(OfficialUse $use): void
    {
        if ($use->status === 'cancelled') {
            return;
        }
        $use->update(['status' => $use->conflicts()->unresolved()->whereHas('reservation', fn ($q) => $q->where('status', 'accepted'))->exists() ? 'conflict' : 'active']);
    }
}
