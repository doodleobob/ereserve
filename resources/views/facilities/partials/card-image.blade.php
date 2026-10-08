<div class="facility-image">
    @if ($item['photo_url'])
        <img src="{{ $item['photo_url'] }}" alt="Photo of {{ $item['name'] }}">
    @else
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18" />
            <path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2" />
            <path d="M10 6h4M10 10h4M10 14h4" />
        </svg>
    @endif
</div>
