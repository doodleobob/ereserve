@push('styles')
    <link rel="stylesheet" href="{{ asset('css/hourly-booking.css') }}?v={{ filemtime(public_path('css/hourly-booking.css')) }}">
@endpush
@push('scripts')
    <script src="{{ asset('js/reservation-time-validation.js') }}?v={{ filemtime(public_path('js/reservation-time-validation.js')) }}" data-server-now="{{ now('Asia/Manila')->getTimestampMs() }}" defer></script>
@endpush
