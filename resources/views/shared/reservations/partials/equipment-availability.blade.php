@php
    $start = \Illuminate\Support\Carbon::parse($summary['scheduled_start']);
    $end = \Illuminate\Support\Carbon::parse($summary['scheduled_end']);
    $allocationEnd = \Illuminate\Support\Carbon::parse($summary['effective_end']);
    $overnight = ! $start->isSameDay($end);
    $bufferDates = $overnight || ! $start->isSameDay($allocationEnd);
@endphp
<section class="equipment-availability-summary" aria-label="Equipment availability summary">
    <div class="acceptance-overview">
        <h4>Reservation Overview</h4>
        <p class="acceptance-resource">{{ $summary['equipment_name'] }}</p>
        <p class="acceptance-schedule">
            @if($overnight)
                {{ $start->format('M j, Y g:i A') }} &ndash; {{ $end->format('M j, Y g:i A') }}
            @else
                {{ $start->format('M j, Y') }} &middot; {{ $start->format('g:i A') }} &ndash; {{ $end->format('g:i A') }}
            @endif
        </p>
        <p class="acceptance-status"><span class="reservation-status reservation-status-{{ $summary['can_accept'] ? 'accepted' : 'rejected' }}">{{ $summary['indicator'] }}</span>@if($summary['return_risk']) <span class="reservation-status reservation-status-pending">Return Risk</span>@endif</p>
    </div>
    <h4>Equipment Availability</h4>
    <dl class="acceptance-quantities">
        @foreach(['requested'=>'Requested quantity','scheduled_available'=>'Scheduled available','scheduled_remaining'=>'Remaining after acceptance'] as $key=>$label)
            <div><dt>{{ $label }}</dt><dd>{{ $summary[$key] ?? ($key === 'requested' ? 'Whole-resource hold' : 'Unverified') }}</dd></div>
        @endforeach
    </dl>
    @if($summary['blocking_reason'])<p class="form-error" role="alert">{{ $summary['blocking_reason'] }}</p>@endif
    @foreach($summary['warnings'] as $warning)<p class="payment-date-help acceptance-warning">{{ $warning }}</p>@endforeach
    <details class="acceptance-stock-details">
        <summary>Stock Details</summary>
        <dl class="account-info-list">
            @foreach(['total'=>'Total Recorded Stock','usable'=>'Usable Stock','damaged'=>'Damaged / Unavailable Units','allocated'=>'Already Allocated','borrowed'=>'Currently Borrowed','physical_available'=>'Physically Available'] as $key=>$label)
                <div><dt>{{ $label }}</dt><dd>{{ $summary[$key] ?? 'Unverified' }}</dd></div>
            @endforeach
            @if($summary['overdue'])<div><dt>Outstanding Overdue Quantity</dt><dd>{{ $summary['overdue'] }}</dd></div>@endif
        </dl>
        <p class="payment-date-help">Scheduled stock determines acceptance. Physical stock shows confirmed handovers and returns now; it is checked again at release.</p>
    </details>
    <section class="acceptance-buffer" aria-label="Equipment return buffer">
        <h4>Equipment Buffer <span>{{ $summary['buffer_minutes'] }} minutes</span></h4>
        <dl class="account-info-list">
            <div><dt>Return buffer</dt><dd>{{ $end->format($bufferDates ? 'M j, Y g:i A' : 'g:i A') }} &ndash; {{ $allocationEnd->format($bufferDates ? 'M j, Y g:i A' : 'g:i A') }}</dd></div>
            <div><dt>Effective allocation end</dt><dd>{{ $allocationEnd->format($bufferDates ? 'M j, Y g:i A' : 'g:i A') }}</dd></div>
        </dl>
    </section>
</section>
