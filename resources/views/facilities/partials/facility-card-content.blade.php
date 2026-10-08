<div class="facility-title-row">
    <h3>{{ $item['name'] }}</h3>
    <span class="availability-badge availability-badge-{{ \Illuminate\Support\Str::slug($item['display_status']) }}">
        {{ $item['display_status'] }}
    </span>
</div>
@if ($item['current_reservation'])
    <p class="facility-description">
        {{ $item['current_reservation']['start_time'] }} - {{ $item['current_reservation']['end_time'] }}
        {{ $item['current_reservation']['end_date_label'] }}
    </p>
@endif
<p class="facility-description facility-summary">{{ $item['list_description'] }}</p>
<p class="facility-description">Barangay: {{ $item['barangay'] }}</p>
<p class="facility-description">Open to: {{ $item['reservation_access'] === 'all_registered_users' ? 'All Registered Users' : $item['barangay'].' Residents Only' }}</p>
<p class="facility-description">Hourly Rate: {{ \App\Support\Money::format($item['hourly_rate']) }} / hour</p>
<span class="category-badge">{{ $item['category'] }}</span>

<div class="facility-meta">
    <p>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" />
            <circle cx="12" cy="10" r="3" />
        </svg>
        {{ $item['location'] }}
    </p>
    <p>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
        Capacity: {{ $item['capacity'] }}
    </p>
</div>
