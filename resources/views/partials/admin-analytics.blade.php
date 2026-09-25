<section id="admin-analytics" class="admin-analytics" aria-labelledby="analytics-heading">
    <link rel="stylesheet" href="{{ asset('css/admin-analytics.css') }}?v={{ filemtime(public_path('css/admin-analytics.css')) }}">
    <div class="analytics-header">
        <div>
            <h2 id="analytics-heading">Admin Analytics</h2>
            <p>{{ auth()->user()->role === 'super_admin' ? 'All barangays' : auth()->user()->barangay }} &middot; {{ $analytics['start'] }} to {{ $analytics['end'] }}</p>
        </div>
        <form method="GET" action="{{ route('analytics') }}#admin-analytics" class="analytics-filters">
            <div class="filter-group">
                <label for="analytics-period">Analytics period</label>
                <select id="analytics-period" name="analytics_period">
                    @foreach (['7' => 'Last 7 Days', '30' => 'Last 30 Days', 'month' => 'This Month', 'custom' => 'Custom Date Range'] as $value => $label)
                        <option value="{{ $value }}" @selected($analytics['period'] === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-group">
                <label for="analytics-start">Custom start</label>
                <input id="analytics-start" type="date" name="analytics_start" value="{{ old('analytics_start', $analytics['start']) }}" max="{{ today()->toDateString() }}">
            </div>
            <div class="filter-group">
                <label for="analytics-end">Custom end</label>
                <input id="analytics-end" type="date" name="analytics_end" value="{{ old('analytics_end', $analytics['end']) }}" max="{{ today()->toDateString() }}">
            </div>
            <button type="submit" class="analytics-apply">Apply</button>
        </form>
    </div>
    @foreach (['analytics_period', 'analytics_start', 'analytics_end'] as $field)
        @error($field)<p class="form-error" role="alert">{{ $message }}</p>@enderror
    @endforeach
    <p class="analytics-note">All metrics cover requests submitted in this period and their current status. Total Collected and Collection Trend show recorded payments for those booked requests, grouped by submission date. Payment confirmation dates are not recorded.</p>
    @if ($analytics['unrecorded'])
        <p class="analytics-note">{{ $analytics['unrecorded'] }} booked reservation(s) have no recorded payment amount and are excluded from monetary totals.</p>
    @endif
    <div class="analytics-summary">
        @foreach (['requests' => 'Total Reservation Requests', 'booked' => 'Booked Reservations', 'pending' => 'Pending Requests', 'rejected' => 'Rejected Requests', 'collected' => 'Total Collected'] as $key => $label)
            <article class="content-card">
                <span>{{ $label }}</span>
                <strong>{{ $key === 'collected' ? \App\Support\Money::format($analytics[$key]) : number_format($analytics[$key]) }}</strong>
            </article>
        @endforeach
    </div>
    @if ($analytics['requests'] === 0)
        <p class="content-card">No reservation data available for this period.</p>
    @endif
    <div class="analytics-charts">
        @foreach (['requests' => 'Reservations Over Time', 'status' => 'Reservation Status', 'collection' => 'Collection Trend'] as $key => $title)
            <article class="content-card">
                <h3>{{ $title }}</h3>
                <div class="analytics-chart" hidden><canvas id="analytics-{{ $key }}" role="img" aria-label="{{ $title }}; values available in the table below"></canvas></div>
                <details>
                    <summary>View {{ strtolower($title) }} data</summary>
                    <div class="analytics-table-wrap">
                        <table>
                            <caption>{{ $title }} &middot; {{ $analytics['start'] }} to {{ $analytics['end'] }}</caption>
                            <thead><tr><th scope="col">{{ $key === 'status' ? 'Status' : 'Submission date' }}</th><th scope="col">{{ $key === 'collection' ? 'Collected (PHP)' : 'Requests' }}</th></tr></thead>
                            <tbody>
                                @if ($key === 'status')
                                    @foreach (['pending' => 'Pending', 'booked' => 'Booked', 'rejected' => 'Rejected'] as $status => $label)
                                        <tr><th scope="row">{{ $label }}</th><td>{{ $analytics[$status] }}</td></tr>
                                    @endforeach
                                @else
                                    @foreach ($analytics['series'] as $point)
                                        <tr><th scope="row">{{ $point['date'] }}</th><td>{{ $key === 'collection' ? \App\Support\Money::format($point['collected']) : $point['requests'] }}</td></tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    </div>
                </details>
            </article>
        @endforeach
    </div>
    <div class="analytics-rankings">
        @foreach (['mostRequested' => ['Most Reserved Facilities / Equipment', 'requests'], 'mostBooked' => ['Most Booked Facilities / Equipment', 'bookings']] as $key => [$title, $unit])
            <article class="content-card">
                <h3>{{ $title }}</h3>
                <p class="analytics-note">Top 10 by {{ $unit }}{{ $unit === 'requests' ? ', across all statuses' : ', confirmed only' }}.</p>
                <ol class="analytics-ranking">
                    @forelse ($analytics[$key] as $item)
                        <li><span>{{ $item['name'] }}<small>{{ $item['category'] }} &middot; {{ $item['barangay'] }}</small></span><strong>{{ $item['count'] }} {{ $unit }}</strong></li>
                    @empty
                        <li>No {{ $unit }} in this period.</li>
                    @endforelse
                </ol>
            </article>
        @endforeach
    </div>
    <article class="content-card">
        <h3>Facility / Equipment Utilization</h3>
        <p class="analytics-note">Confirmed bookings among requests submitted in this period. Top 10; booking counts do not measure time in use.</p>
        @forelse ($analytics['mostBooked'] as $item)
            <div class="analytics-utilization">
                <span>{{ $item['name'] }} &middot; {{ $item['barangay'] }}</span>
                <meter min="0" max="{{ max(1, $analytics['booked']) }}" value="{{ $item['count'] }}" aria-label="{{ $item['name'] }}: {{ $item['count'] }} bookings">{{ $item['count'] }}</meter>
                <strong>{{ $item['count'] }} bookings</strong>
            </div>
        @empty
            <p>No booked reservations in this period.</p>
        @endforelse
    </article>
    <script id="admin-analytics-data" type="application/json">{!! json_encode(\Illuminate\Support\Arr::only($analytics, ['series', 'pending', 'booked', 'rejected']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <script src="{{ asset('js/vendor/chart.umd.min.js') }}" defer></script>
    <script src="{{ asset('js/admin-analytics.js') }}?v={{ filemtime(public_path('js/admin-analytics.js')) }}" defer></script>
</section>
