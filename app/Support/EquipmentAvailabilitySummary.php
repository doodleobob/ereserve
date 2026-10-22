<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\ResourceUsage;
use App\Services\ResourceUsageManagement;

class EquipmentAvailabilitySummary
{
    /** A review snapshot. Acceptance must call this again while holding the resource lock. */
    public static function forReservation(Reservation $reservation, ?Facility $resource): array
    {
        $period = $reservation->period();
        $summary = [
            'equipment_name' => $resource?->name ?? $reservation->facility_name,
            'scheduled_start' => $period->start->toIso8601String(), 'scheduled_end' => $period->end->toIso8601String(),
            'schedule_label' => $period->start->format('M j, Y g:i A').' – '.$period->end->format('M j, Y g:i A'),
            'requested' => $reservation->quantity === null ? null : (int) $reservation->quantity,
            'total' => $resource?->total_quantity, 'allocated' => null, 'scheduled_available' => null,
            'scheduled_remaining' => null, 'borrowed' => null, 'physical_available' => null, 'overdue' => null,
            'return_risk' => false, 'warnings' => [], 'can_accept' => false, 'indicator' => 'Unavailable',
            'blocking_reason' => null, 'checked_at' => now()->toIso8601String(),
        ];
        if (! $resource || ! $resource->isEquipment()) {
            $summary['blocking_reason'] = 'The equipment inventory record is unavailable. Refresh the reservation and verify its resource.';

            return $summary;
        }
        $inventory = ResourceUsageManagement::inventory($resource);
        $loans = ResourceUsage::where('facility_id', $resource->id)->whereColumn('released_quantity', '>', 'returned_quantity')->with('reservation')->get();
        $summary['borrowed'] = $inventory['outstanding'];
        $summary['physical_available'] = $inventory['available'];
        $summary['overdue'] = $loans->filter(fn ($loan) => $loan->overdue())->sum(fn ($loan) => $loan->outstanding());
        $interfering = $loans->filter(fn ($loan) => $loan->reservation->period()->end->gt($period->start))->sum(fn ($loan) => $loan->outstanding());
        // Preserve the established exclusive-stock meaning of a legacy NULL booking quantity.
        $requested = $summary['requested'] ?? $resource->total_quantity;
        if ($summary['requested'] === null) {
            $summary['warnings'][] = 'Legacy whole-resource hold: acceptance commits all verified units. No quantity will be written automatically; physical release still requires a verified approved quantity.';
        }
        if ($period->isValid() && $resource->total_quantity !== null) {
            $allocation = ReservationAvailability::allocationSummary($resource, $period, $reservation->id);
            $summary['allocated'] = $allocation['allocated'];
            $summary['scheduled_available'] = $allocation['available'];
            $summary['scheduled_remaining'] = $allocation['available'] - $requested;
        }
        if ($summary['overdue'] > 0) {
            $summary['warnings'][] = "{$summary['overdue']} outstanding units are overdue. Confirm their return before handover.";
        }
        if ($interfering > 0) {
            $summary['warnings'][] = "{$interfering} currently borrowed units are expected back after this reservation starts. Review their return schedule.";
        }
        if ($requested !== null && $inventory['available'] !== null && $requested > $inventory['available']) {
            $summary['warnings'][] = 'Current physical stock is below the requested quantity. Expected returns do not guarantee future availability.';
        }
        $summary['return_risk'] = $summary['overdue'] > 0 || $interfering > 0 || ($requested !== null && $inventory['available'] !== null && $requested > $inventory['available']);
        $summary['blocking_reason'] = match (true) {
            $reservation->status !== 'pending' => 'This reservation is no longer pending. Refresh the table to review its current status.',
            $resource->status !== 'Available' => 'This equipment is currently unavailable for reservations.',
            ! $period->isValid() => 'This reservation has an invalid schedule.',
            $resource->total_quantity === null => 'Equipment stock has not been verified. Please contact the barangay administrator.',
            $requested < 1 => 'The requested equipment quantity must be positive.',
            $requested > $summary['scheduled_available'] => "Cannot accept this reservation. Only {$summary['scheduled_available']} units are available for the selected schedule, but {$requested} were requested.",
            default => null,
        };
        $summary['can_accept'] = $summary['blocking_reason'] === null;
        if ($summary['can_accept']) {
            $summary['indicator'] = 'Available';
        } elseif ($resource->status === 'Available' && $resource->total_quantity !== null && $period->isValid() && $requested > $summary['scheduled_available']) {
            $official = OfficialUseScheduling::schedules($resource->id, $period->start, $period->end, $resource->barangay);
            $summary['indicator'] = $official->isNotEmpty() ? 'Unavailable' : 'Insufficient Quantity';
            if ($official->isNotEmpty()) {
                $summary['blocking_reason'] .= ' Overlapping Official Use commitments are included in the allocation.';
            }
        }

        return $summary;
    }
}
