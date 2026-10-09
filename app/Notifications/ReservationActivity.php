<?php

namespace App\Notifications;

use App\Models\Reservation;
use App\Notifications\Channels\QueuedReservationMail;
use App\Support\Money;
use App\Support\ReservationPeriod;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReservationActivity extends Notification
{
    private array $snapshot;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(Reservation $reservation, public string $event, public ?string $previousTotal = null, public ?string $resolution = null, public ?array $previousSchedule = null)
    {
        // Store event-time values, rather than reloading a changed model in the mail worker.
        $this->snapshot = $reservation->only(['id', 'user_id', 'barangay', 'facility_name', 'reservation_date', 'start_time', 'end_time', 'status', 'hourly_rate_snapshot', 'total_payment', 'cancellation_reason', 'cancellation_notes']);
        $this->snapshot['total_payment'] = $reservation->total;
        if ($event === 'pending_updated') {
            $this->snapshot['barangay'] = $reservation->managingBarangay();
        }
    }

    public function via(object $notifiable): array
    {
        // Resident preferences remain an in-app message to administrators.
        return $this->event === 'official_use_decision' ? ['database'] : ['database', QueuedReservationMail::class];
    }

    public function toDatabase(object $notifiable): array
    {
        $r = (new Reservation)->setRawAttributes($this->snapshot, true);
        $title = match ($this->event) {
            'submitted' => 'New Reservation Request',
            'pending_updated' => 'Pending Reservation Updated',
            'submission_confirmed' => 'Reservation Submitted',
            'accepted' => 'Reservation Booked',
            'rejected' => 'Reservation Rejected',
            'rescheduled' => 'Reservation Rescheduled',
            'payment_refunded' => 'Payment Refunded',
            'payment_updated' => 'Total Paid Updated',
            'cancelled' => 'Reservation Cancelled',
            'official_use_cancelled' => 'Reservation Cancelled Due to Official Use',
            'official_use_conflict' => 'Official Use Schedule Conflict',
            'official_use_decision' => 'Official Use Conflict Preference',
        };
        $message = match ($this->event) {
            'submitted' => 'A new reservation request has been submitted.',
            'pending_updated' => 'A pending reservation request has been updated. Please review the latest reservation details.',
            'submission_confirmed' => 'Your reservation request has been submitted successfully and is awaiting review by the barangay administrator. Status: Pending.',
            'accepted' => "Your reservation for {$r->facility_name} has been successfully booked.",
            'rejected' => 'Your reservation request has been rejected.',
            'rescheduled' => 'Your accepted reservation has been rescheduled. The details below show your new schedule.',
            'payment_refunded' => 'The payment for your reservation has been marked as refunded.',
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
        if (! in_array($this->event, ['submitted', 'pending_updated', 'submission_confirmed', 'rejected'], true)) {
            $details[] = 'Hourly Rate: '.Money::format($r->hourly_rate_snapshot).' / hour';
            if ($this->event === 'payment_updated') {
                $details[] = 'Previous Total Paid: '.Money::format($this->previousTotal);
            }
            $details[] = ($this->event === 'payment_refunded' ? 'Total: ' : 'Total Paid: ').Money::format($r->total);
        }
        if ($this->event === 'payment_refunded') {
            $details[] = 'Payment Status: Refunded';
        }

        return [
            'event' => $this->event, 'title' => $title, 'message' => $message, 'details' => $details,
            'reservation_id' => $r->id, 'barangay' => $r->barangay,
            'hourly_rate' => in_array($this->event, ['submitted', 'pending_updated'], true) ? null : $r->hourly_rate_snapshot,
            'total_payment' => in_array($this->event, ['submitted', 'pending_updated'], true) ? null : $r->total_payment,
            'previous_total' => $this->previousTotal,
            'cancellation_reason' => in_array($this->event, ['cancelled', 'official_use_cancelled'], true) ? $r->cancellation_reason : null,
            'resolution' => $this->resolution,
            'status' => $r->status,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = (new Reservation)->setRawAttributes($this->snapshot, true);
        $data = $this->toDatabase($notifiable);
        $title = match ($this->event) {
            'submitted', 'submission_confirmed' => 'Reservation Submitted',
            'accepted' => 'Reservation Confirmed',
            'rejected' => 'Reservation Update',
            default => $data['title'],
        };
        $details = [
            'Reservation' => '#'.$r->id,
            'Resource' => $r->facility_name,
            $this->event === 'rescheduled' ? 'New Date' : 'Date' => $r->period()->start->format('F j, Y'),
            $this->event === 'rescheduled' ? 'New Time' : 'Time' => $r->period()->start->format('g:i A').' – '.$r->period()->end->format('F j, Y g:i A'),
            'Status' => ucfirst($r->status),
        ];
        if ($r->total !== null && ! in_array($this->event, ['submitted', 'pending_updated', 'submission_confirmed', 'rejected'], true)) {
            $details['Total'] = Money::format($r->total);
        }
        if ($this->event === 'payment_updated') {
            $details['Previous Total'] = Money::format($this->previousTotal);
        }
        if (in_array($this->event, ['cancelled', 'official_use_cancelled'], true) && $r->cancellation_reason) {
            $details['Reason'] = $r->cancellation_reason;
            if ($r->cancellation_notes) {
                $details['Notes'] = $r->cancellation_notes;
            }
        }
        if ($this->event === 'payment_refunded') {
            $details['Payment Status'] = 'Refunded';
        }
        if ($this->previousSchedule !== null) {
            $previous = new ReservationPeriod($this->previousSchedule['reservation_date'], $this->previousSchedule['start_time'], $this->previousSchedule['end_time']);
            $details['Previous Schedule'] = $previous->start->format('F j, Y g:i A').' – '.$previous->end->format('F j, Y g:i A');
        }

        return (new MailMessage)->subject('eReserve - '.$title)->view('emails.reservation-activity', [
            'title' => $title, 'name' => $notifiable->name, 'activityMessage' => $data['message'], 'details' => $details,
            'actionUrl' => rtrim(config('app.url'), '/').route('reservations.index', ['reservation' => $r->id], false),
        ]);
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel !== 'mail') {
            return true;
        }

        // Recheck recipient ownership after a queued admin has changed role/tenant.
        return in_array($this->event, ['submitted', 'pending_updated'], true)
            ? $notifiable->role === 'admin' && $notifiable->barangay === $this->snapshot['barangay']
            : $notifiable->id === $this->snapshot['user_id'];
    }

    public function failed(Throwable $exception): void
    {
        // Provider exception messages can contain private connection information.
        Log::warning('Reservation email delivery failed.', [
            'reservation_id' => $this->snapshot['id'], 'event' => $this->event, 'exception_type' => $exception::class,
        ]);
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
