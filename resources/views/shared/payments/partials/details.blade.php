@php($row=\App\Support\PaymentReport::row($payment))
<dl class="account-info-list">
    <div><dt>Payment ID</dt><dd>#{{ $row[0] }}</dd></div>
    <div><dt>Reservation ID</dt><dd>#{{ $row[1] }}</dd></div>
    @if(auth()->user()->role === 'super_admin')<div><dt>Barangay</dt><dd>{{ $payment->reservation->managingBarangay() }}</dd></div>@endif
    <div><dt>Resident</dt><dd>{{ $row[2] }}</dd></div>
    <div><dt>Resource</dt><dd>{{ $row[3] }}</dd></div>
    <div><dt>Reservation Date</dt><dd>{{ $payment->reservation->period()->start->format('F j, Y') }}</dd></div>
    <div><dt>Reservation Time</dt><dd>{{ $payment->reservation->period()->start->format('g:i A') }} – {{ $payment->reservation->period()->end->format('g:i A') }} {{ $payment->reservation->period()->endDateLabel() }}</dd></div>
    <div><dt>Total</dt><dd>{{ \App\Support\Money::format($row[5]) }}</dd></div>
    @if($showStatus)<div><dt>Payment Status</dt><dd>{{ $row[6] }}</dd></div>@endif
</dl>
@if($payment->history)
    <h4>Payment History</h4>
    <ul>
        @foreach($payment->history as $entry)
            <li>
                @if(($entry['type'] ?? null) === 'total_correction')
                    Reservation total corrected from {{ \App\Support\Money::format($entry['before']['total_payment'] ?? null) }} to {{ \App\Support\Money::format($entry['after']['total_payment'] ?? null) }}
                @else
                    Status changed from {{ \App\Models\Payment::statusLabel($entry['before'] ?? 'unknown') }} to {{ \App\Models\Payment::statusLabel($entry['after'] ?? 'unknown') }}
                @endif
                by {{ $entry['actor_name'] ?? 'Admin #'.($entry['actor_id'] ?? 'unknown') }} on {{ \Illuminate\Support\Carbon::parse($entry['at'] ?? $payment->created_at)->format('F j, Y g:i A') }}.
                @if(!empty($entry['reason'])) Reason: {{ $entry['reason'] }}.@endif
            </li>
        @endforeach
    </ul>
@endif
