<section class="equipment-availability-summary" aria-label="Equipment availability summary">
    <h4>Equipment Availability</h4>
    <p class="payment-date-help">{{ $summary['equipment_name'] }} · {{ $summary['schedule_label'] }}</p>
    <p><span class="reservation-status reservation-status-{{ $summary['can_accept'] ? 'accepted' : 'rejected' }}">{{ $summary['indicator'] }}</span>@if($summary['return_risk']) <span class="reservation-status reservation-status-pending">Return Risk</span>@endif</p>
    <dl class="account-info-list">
        @foreach(['requested'=>'Resident Requested','total'=>'Total Serviceable Quantity','allocated'=>'Already Allocated','scheduled_available'=>'Scheduled Available','scheduled_remaining'=>'Scheduled Remaining After Acceptance','borrowed'=>'Currently Borrowed','physical_available'=>'Physically Available'] as $key=>$label)
            <div><dt>{{ $label }}</dt><dd>{{ $summary[$key] ?? ($key === 'requested' ? 'Whole-resource hold' : 'Unverified') }}</dd></div>
        @endforeach
        @if($summary['overdue'])<div><dt>Outstanding Overdue Quantity</dt><dd>{{ $summary['overdue'] }}</dd></div>@endif
    </dl>
    @if($summary['blocking_reason'])<p class="form-error" role="alert">{{ $summary['blocking_reason'] }}</p>@endif
    @foreach($summary['warnings'] as $warning)<p class="payment-date-help">{{ $warning }}</p>@endforeach
    <p class="payment-date-help">Scheduled stock determines acceptance. Physical stock shows confirmed handovers and returns now; it is checked again at release.</p>
</section>
