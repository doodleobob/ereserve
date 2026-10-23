<div class="facility-title-row">
    <h3>{{ $item['name'] }}</h3>
    <span class="availability-badge availability-badge-{{ \Illuminate\Support\Str::slug($item['display_status']) }}">{{ $item['display_status'] }}</span>
</div>
<p class="facility-description">Barangay: {{ $item['barangay'] }}</p>
@if ($item['current_reservation'])
    <p class="facility-description">{{ $item['current_reservation']['start_time'] }} - {{ $item['current_reservation']['end_time'] }} {{ $item['current_reservation']['end_date_label'] }}</p>
@endif
<p class="facility-description facility-summary">{{ $item['list_description'] }}</p>
@if (! in_array(trim($item['location']), [$item['barangay'], 'Barangay '.$item['barangay']], true))
    <p class="facility-description">Location: {{ $item['location'] }}</p>
@endif
<p class="facility-description">Open to: {{ $item['reservation_access'] === 'all_registered_users' ? 'All Registered Users' : 'Residents of this barangay only' }}</p>
<dl class="equipment-card-facts">
    <div><dt>Total Quantity</dt><dd>{{ $item['total_quantity'] ?? 'Awaiting stock verification' }}</dd></div>
    <div><dt>Hourly Rate</dt><dd>{{ \App\Support\Money::format($item['hourly_rate']) }} <small>/ hour</small></dd></div>
</dl>
