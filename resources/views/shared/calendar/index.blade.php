<x-layouts.user title="All Schedules - eReserve" active="{{ $isAdmin ? 'calendar' : 'dashboard' }}">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/resident-booking.css') }}?v={{ filemtime(public_path('css/resident-booking.css')) }}">
        <link rel="stylesheet" href="{{ asset('css/all-schedules.css') }}?v={{ filemtime(public_path('css/all-schedules.css')) }}">
    @endpush
    <section class="page-heading reservations-heading">
        <h2>All Schedules</h2>
        <p>Browse facility and equipment schedules across barangays.</p>
    </section>

    @if (session('reservation_status'))
        <div class="reservation-alert" role="status">
            {{ session('reservation_status') }}
        </div>
    @endif

    @include('shared.calendar.partials.filters')

    @if ($facilities->isEmpty())
        <p class="reservation-alert">{{ match($selectedType) {
            'facility' => 'No facilities are currently available for this barangay.',
            'equipment' => 'No equipment is currently available for this barangay.',
            default => 'No facilities or equipment are currently available for this barangay.',
        } }}</p>
    @endif

    @include('shared.calendar.partials.availability')

    @push('scripts')
        <script src="{{ asset('js/page-filters.js') }}?v={{ filemtime(public_path('js/page-filters.js')) }}" defer></script>
        <script src="{{ asset('js/all-schedules.js') }}?v={{ filemtime(public_path('js/all-schedules.js')) }}" defer></script>
    @endpush
</x-layouts.user>
