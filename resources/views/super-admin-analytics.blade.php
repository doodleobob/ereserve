<x-layouts.user title="Super Admin Analytics - eReserve" active="analytics">
    <section id="super-admin-analytics" class="admin-analytics super-admin-analytics" aria-labelledby="super-analytics-heading">
        <link rel="stylesheet" href="{{ asset('css/admin-analytics.css') }}?v={{ filemtime(public_path('css/admin-analytics.css')) }}">
        <link rel="stylesheet" href="{{ asset('css/super-admin-analytics.css') }}?v={{ filemtime(public_path('css/super-admin-analytics.css')) }}">
        <div class="analytics-header">
            <div>
                <h2 id="super-analytics-heading">Super Admin Analytics</h2>
                <p>{{ $analytics['barangay'] ?? 'All Barangays' }} &middot; {{ $analytics['start'] ? $analytics['start'] . ' to ' . $analytics['end'] : 'All Time' }}</p>
            </div>
            @php
                $datePresets = \App\Support\AnalyticsPeriod::presets();
                $selectedPeriod = old('analytics_period', $analytics['period']);
                if (!is_string($selectedPeriod) || ($selectedPeriod !== 'custom' && !array_key_exists($selectedPeriod, $datePresets))) {
                    $selectedPeriod = $analytics['period'];
                }
                $customRange = $selectedPeriod === 'custom';
                $displayDates = $customRange
                    ? ['start' => old('analytics_start', $analytics['start']), 'end' => old('analytics_end', $analytics['end'])]
                    : $datePresets[$selectedPeriod];
            @endphp
            <form method="GET" action="{{ route('super-admin.analytics') }}" class="analytics-filters" data-date-presets="{{ json_encode($datePresets) }}">
                <div class="filter-group">
                    <label for="super-analytics-period">Analytics period</label>
                    <select id="super-analytics-period" name="analytics_period">
                        @foreach (['7' => 'Last 7 Days', '30' => 'Last 30 Days', 'month' => 'This Month', 'year' => 'This Year', 'custom' => 'Custom Range', 'all' => 'All Time'] as $value => $label)
                            <option value="{{ $value }}" @selected($selectedPeriod === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filter-group">
                    <label for="super-analytics-start">Start date</label>
                    <input id="super-analytics-start" type="date" name="analytics_start" value="{{ $displayDates['start'] }}" max="{{ today()->toDateString() }}" @disabled(!$customRange) @required($customRange)>
                </div>
                <div class="filter-group">
                    <label for="super-analytics-end">End date</label>
                    <input id="super-analytics-end" type="date" name="analytics_end" value="{{ $displayDates['end'] }}" max="{{ today()->toDateString() }}" @disabled(!$customRange) @required($customRange)>
                </div>
                <div class="filter-group">
                    <label for="super-analytics-barangay">Barangay</label>
                    <select id="super-analytics-barangay" name="barangay">
                        <option value="">All Barangays</option>
                        @foreach ($analytics['barangays'] as $barangay)
                            <option value="{{ $barangay }}" @selected(old('barangay', $analytics['barangay']) === $barangay)>{{ $barangay }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="analytics-apply">Apply</button>
            </form>
        </div>
        @foreach (['analytics_period', 'analytics_start', 'analytics_end', 'period', 'barangay'] as $field)
            @error($field)<p class="form-error" role="alert">{{ $message }}</p>@enderror
        @endforeach
        <p class="analytics-note">All sections use the selected period. Account totals count accounts created in this period, including inactive accounts. Barangays are counted when an account, facility, or reservation was created in this period. Reservation charts, rankings, and payments use reservation submission dates and current statuses. The barangay filter applies to every section. Custom ranges support up to 366 days.</p>
        <div class="analytics-summary">
            @foreach (['totalBarangays' => 'Total Barangays', 'totalAdmins' => 'Total Admins', 'totalResidents' => 'Total Residents', 'totalReservations' => 'Total Reservations'] as $key => $label)
                <article class="content-card"><span>{{ $label }}</span><strong>{{ number_format($analytics[$key]) }}</strong></article>
            @endforeach
        </div>
        @if ($analytics['totalReservations'] === 0)
            <p class="content-card">No reservation data available for the selected period.</p>
        @endif
        <article class="content-card">
            <h3>Reservations by Barangay</h3>
            <div class="analytics-chart" hidden><canvas id="super-analytics-barangays" role="img" aria-label="Reservations by Barangay; values in the table below"></canvas></div>
            <details><summary>View barangay data</summary><div class="analytics-table-wrap"><table>
                <caption>Reservations by Barangay</caption>
                <thead><tr><th scope="col">Barangay</th><th scope="col">Total Reservations</th></tr></thead>
                <tbody>
                    @forelse ($analytics['byBarangay'] as $row)
                        <tr><th scope="row">{{ $row['barangay'] }}</th><td>{{ number_format($row['count']) }}</td></tr>
                    @empty
                        <tr><td colspan="2">No reservation activity.</td></tr>
                    @endforelse
                </tbody>
            </table></div></details>
        </article>
        <div class="analytics-rankings">
            <article class="content-card">
                <h3>Reservation Trend</h3>
                <p class="analytics-note">Monthly requests submitted; months without requests show zero.</p>
                <div class="analytics-chart" hidden><canvas id="super-analytics-trend" role="img" aria-label="Reservation Trend; monthly values in the table below"></canvas></div>
                <details><summary>View monthly data</summary><div class="analytics-table-wrap"><table>
                    <caption>Monthly reservation submissions</caption>
                    <thead><tr><th scope="col">Month</th><th scope="col">Reservations</th></tr></thead>
                    <tbody>@foreach ($analytics['series'] as $row)<tr><th scope="row">{{ $row['month'] }}</th><td>{{ number_format($row['count']) }}</td></tr>@endforeach</tbody>
                </table></div></details>
            </article>
            <article class="content-card">
                <h3>Reservation Status</h3>
                <div class="analytics-chart" hidden><canvas id="super-analytics-status" role="img" aria-label="Reservation Status; values in the table below"></canvas></div>
                <div class="analytics-table-wrap"><table>
                    <caption>Current status of requests submitted in the selected period</caption>
                    <thead><tr><th scope="col">Status</th><th scope="col">Reservations</th></tr></thead>
                    <tbody>@foreach ($analytics['statuses'] as $status => $count)<tr><th scope="row">{{ $status }}</th><td>{{ number_format($count) }}</td></tr>@endforeach</tbody>
                </table></div>
            </article>
        </div>
        <div class="analytics-rankings">
            <article class="content-card">
                <h3>Most Active Barangays</h3>
                <div class="analytics-table-wrap"><table>
                    <caption>Top 5 by total reservation requests</caption>
                    <thead><tr><th scope="col">Barangay</th><th scope="col">Total Reservations</th></tr></thead>
                    <tbody>
                        @forelse ($analytics['mostActive'] as $row)
                            <tr><th scope="row">{{ $row['barangay'] }}</th><td>{{ number_format($row['count']) }}</td></tr>
                        @empty
                            <tr><td colspan="2">No reservation activity.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </article>
            <article class="content-card">
                <h3>Most Used Facilities &amp; Equipment</h3>
                <div class="analytics-table-wrap"><table>
                    <caption>Top 5 by reservation requests, including pending and rejected requests</caption>
                    <thead><tr><th scope="col">Facility/Equipment</th><th scope="col">Barangay</th><th scope="col">Reservations</th></tr></thead>
                    <tbody>
                        @forelse ($analytics['mostUsed'] as $row)
                            <tr><th scope="row">{{ $row['name'] }}</th><td>{{ $row['barangay'] }}</td><td>{{ number_format($row['count']) }}</td></tr>
                        @empty
                            <tr><td colspan="3">No reservation activity.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </article>
        </div>
        <article class="content-card">
            <h3>Payment Overview</h3>
            <p><strong>Total Paid Amount: {{ \App\Support\Money::format($analytics['collected']) }}</strong></p>
            <p class="analytics-note">Recorded payments on accepted reservations submitted in the selected period. Acceptance confirms payment in eReserve. Payment confirmation dates and outstanding balances are not recorded separately.</p>
            @if ($analytics['unrecorded'])
                <p class="analytics-note">{{ $analytics['unrecorded'] }} accepted reservation(s) have no recorded payment amount and are excluded from this total.</p>
            @endif
        </article>
        <script id="super-admin-analytics-data" type="application/json">{!! json_encode(\Illuminate\Support\Arr::only($analytics, ['byBarangay', 'series', 'statuses']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        <script src="{{ asset('js/vendor/chart.umd.min.js') }}" defer></script>
        <script src="{{ asset('js/super-admin-analytics.js') }}?v={{ filemtime(public_path('js/super-admin-analytics.js')) }}" defer></script>
    </section>
</x-layouts.user>
