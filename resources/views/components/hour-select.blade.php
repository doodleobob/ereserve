@props(['name', 'value' => '', 'legacy' => false])
@php($selectedHour = substr((string) $value, 0, 5))
<select name="{{ $name }}" {{ $attributes->merge(['class' => 'hourly-time-select']) }} required>
    <option value="">Select hour</option>
    @if($legacy && $selectedHour && substr($selectedHour, 3) !== '00')
        <option value="{{ $selectedHour }}" data-legacy-time selected>{{ \Illuminate\Support\Carbon::parse($selectedHour)->format('g:i A') }} (existing)</option>
    @endif
    @foreach(range(0, 23) as $hour)
        @php($time = sprintf('%02d:00', $hour))
        <option value="{{ $time }}" @selected($time === $selectedHour)>{{ \Illuminate\Support\Carbon::parse($time)->format('g:i A') }}</option>
    @endforeach
</select>
