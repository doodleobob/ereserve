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
        if (self::schedules($facility->id, $period->start, $period->end, $facility->barangay)->isNotEmpty()) {
            throw ValidationException::withMessages(['reservation' => 'This resource is unavailable for the selected date and time due to Official Use.']);
        }
    }

    // Caller holds the facility lock and transaction shared with reservation creation/editing.
    public static function create(Facility $facility, array $data, User $actor): OfficialUse
    {
        $period = self::validateSchedule($facility, $data);
        $use = OfficialUse::create([
            ...$data, 'facility_id' => $facility->id, 'barangay' => $facility->barangay,
            'created_by' => $actor->id, 'status' => 'active',
        ]);
        self::synchronizeConflicts($use, $facility, $period, $actor);

        return $use;
    }

    // Caller holds both old/new facility locks (in ID order), the schedule lock, and a transaction.
    public static function update(OfficialUse $use, Facility $facility, array $data, User $actor): OfficialUse
    {
        $period = self::validateSchedule($facility, $data, $use->id);
        $use->fill([...$data, 'facility_id' => $facility->id, 'barangay' => $facility->barangay])->save();
        self::synchronizeConflicts($use, $facility, $period, $actor);

        return $use;
    }

    private static function validateSchedule(Facility $facility, array $data, ?int $exceptOfficialUseId = null): ReservationPeriod
    {
        $period = new ReservationPeriod($data['date'], $data['start_time'], $data['end_time']);
        if (! $period->isValid()) {
            throw ValidationException::withMessages(['end_time' => 'End Time must be different from Start Time. An earlier End Time ends the next day.']);
        }
        if (self::schedules($facility->id, $period->start, $period->end, $facility->barangay, $exceptOfficialUseId)->isNotEmpty()) {
            throw ValidationException::withMessages(['date' => 'This schedule overlaps an existing Official Use for this resource.']);
        }

        return $period;
    }

    private static function synchronizeConflicts(OfficialUse $use, Facility $facility, ReservationPeriod $period, User $actor): void
    {
        $reservations = Reservation::query()->with('user')->forFacility($facility)
            ->whereIn('status', ['pending', 'accepted'])
            ->whereBetween('reservation_date', [$period->start->copy()->subDay()->toDateString(), $period->end->toDateString()])
            ->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (Reservation $reservation) => $reservation->period()->overlaps($period->start, $period->end));
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
