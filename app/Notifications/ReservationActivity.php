<?php

namespace App\Notifications;

use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class ReservationActivity extends Notification
{
    public function __construct(public Reservation $reservation, public string $event, public ?string $previousTotal = null, public ?string $resolution = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $r = $this->reservation;
        $title = match ($this->event) {
            'submitted' => 'New Reservation Request',
            'accepted' => 'Reservation Booked',
            'payment_updated' => 'Total Paid Updated',
            'cancelled' => 'Reservation Cancelled',
            'official_use_cancelled' => 'Reservation Cancelled Due to Official Use',
            'official_use_conflict' => 'Official Use Schedule Conflict',
            'official_use_decision' => 'Official Use Conflict Preference',
        };
        $message = match ($this->event) {
            'submitted' => 'A new reservation request has been submitted.',
            'accepted' => "Your reservation for {$r->facility_name} has been successfully booked.",
            'payment_updated' => "The total paid for your {$r->facility_name} reservation has been corrected.",
            'cancelled' => 'Your reservation for '.$r->facility_name.' on '.$r->period()->start->format('F j, Y').' from '.$r->period()->start->format('g:i A').' to '.$r->period()->end->format('F j, Y g:i A').' has been cancelled. Reason: '.$r->cancellation_reason.'.',
            'official_use_cancelled' => 'Your pending reservation for '.$r->facility_name.' on '.$r->period()->start->format('F j, Y').' from '.$r->period()->start->format('g:i A').' to '.$r->period()->end->format('F j, Y g:i A').' has been cancelled because the resource is required for official barangay use. You may create another reservation for a different available schedule.',
            'official_use_conflict' => 'Your accepted reservation for '.$r->facility_name.' on '.$r->period()->start->format('F j, Y').' from '.$r->period()->start->format('g:i A').' to '.$r->period()->end->format('F j, Y g:i A').' is affected because the resource is required for official barangay use. Please choose Reschedule or Cancellation in My Reservations. The barangay administrator will process your selected option.',
            'official_use_decision' => 'The resident has selected '.($this->resolution === 'reschedule_requested' ? 'Reschedule' : 'Cancellation').' for reservation #'.$r->id.'. Process this preference through Accepted → Edit in Reservation Management.',
        };
        $details = [$r->facility_name, Carbon::parse($r->reservation_date)->format('F j, Y'), Carbon::parse($r->start_time)->format('g:i A').' – '.Carbon::parse($r->end_time)->format('g:i A')];
        if ($endDateLabel = $r->period()->endDateLabel()) {
            $details[] = $endDateLabel;
        }
        if (in_array($this->event, ['cancelled', 'official_use_cancelled'], true)) {
            $details[] = 'Reason: '.$r->cancellation_reason;
        }
        if ($this->event !== 'submitted') {
            $details[] = 'Hourly Rate: '.Money::format($r->hourly_rate_snapshot).' / hour';
            if ($this->event === 'payment_updated') {
                $details[] = 'Previous Total Paid: '.Money::format($this->previousTotal);
            }
            $details[] = 'Total Paid: '.Money::format($r->total_payment);
        }

        return [
            'event' => $this->event, 'title' => $title, 'message' => $message, 'details' => $details,
            'reservation_id' => $r->id, 'barangay' => $r->barangay,
            'hourly_rate' => $this->event === 'submitted' ? null : $r->hourly_rate_snapshot,
            'total_payment' => $this->event === 'submitted' ? null : $r->total_payment,
            'previous_total' => $this->previousTotal,
            'cancellation_reason' => in_array($this->event, ['cancelled', 'official_use_cancelled'], true) ? $r->cancellation_reason : null,
            'resolution' => $this->resolution,
        ];
    }

    public static function forDisplay(array $data): array
    {
        // Update historical wording without rewriting notification history or amounts.
        if (! in_array($data['event'] ?? null, ['accepted', 'payment_updated'], true)) {
            return $data;
        }
        $facility = $data['details'][0] ?? 'the facility';
        $data['title'] = $data['event'] === 'accepted' ? 'Reservation Booked' : 'Total Paid Updated';
        $data['message'] = $data['event'] === 'accepted'
            ? "Your reservation for {$facility} has been successfully booked."
            : "The total paid for your {$facility} reservation has been corrected.";
        $data['details'] = collect($data['details'] ?? [])
            ->reject(fn (string $detail) => str_starts_with($detail, 'Payment Method:') || str_starts_with($detail, 'Please proceed to your barangay'))
            ->map(fn (string $detail) => preg_replace('/^(Total Payment:|Updated Total:)/', 'Total Paid:', preg_replace('/^Previous Total:/', 'Previous Total Paid:', $detail)))
            ->values()->all();

        return $data;
    }
}
