@php($row=\App\Support\PaymentReport::row($payment))
<dl class="account-info-list">
    <div><dt>Payment ID</dt><dd>#{{ $row[0] }}</dd></div>
    <div><dt>Reservation ID</dt><dd>#{{ $row[1] }}</dd></div>
    <div><dt>Resident</dt><dd>{{ $row[2] }}</dd></div>
    <div><dt>Resource</dt><dd>{{ $row[3] }}</dd></div>
    <div><dt>Reservation Date</dt><dd>{{ $payment->reservation->period()->start->format('F j, Y') }}</dd></div>
    <div><dt>Reservation Time</dt><dd>{{ $payment->reservation->period()->start->format('g:i A') }} – {{ $payment->reservation->period()->end->format('g:i A') }} {{ $payment->reservation->period()->endDateLabel() }}</dd></div>
    <div><dt>Total</dt><dd>{{ \App\Support\Money::format($row[5]) }}</dd></div>
    @if($showStatus)<div><dt>Payment Status</dt><dd>{{ $row[6] }}</dd></div>@endif
</dl>
