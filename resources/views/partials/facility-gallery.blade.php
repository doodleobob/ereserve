<div
    class="facility-detail-image{{ count($facility['photos']) > 1 ? ' facility-carousel' : '' }}"
    @if (count($facility['photos']) > 1) data-facility-carousel tabindex="0" @endif
>
    @forelse ($facility['photos'] as $photo)
        <div class="facility-carousel-slide" data-carousel-slide @if (! $loop->first) hidden @endif>
            <img src="{{ $photo['url'] }}" alt="Photo {{ $loop->iteration }} of {{ $facility['name'] }}">
        </div>
    @empty
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18" />
            <path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2" />
            <path d="M10 6h4M10 10h4M10 14h4" />
        </svg>
    @endforelse

    @if (count($facility['photos']) > 1)
        <button type="button" class="facility-carousel-control facility-carousel-previous" data-carousel-previous aria-label="Previous photo">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
        </button>
        <button type="button" class="facility-carousel-control facility-carousel-next" data-carousel-next aria-label="Next photo">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
        </button>
        <div class="facility-carousel-indicators" aria-label="Choose facility photo">
            @foreach ($facility['photos'] as $photo)
                <button
                    type="button"
                    class="facility-carousel-indicator{{ $loop->first ? ' active' : '' }}"
                    data-carousel-indicator="{{ $loop->index }}"
                    aria-label="Show photo {{ $loop->iteration }}"
                    aria-current="{{ $loop->first ? 'true' : 'false' }}"
                ></button>
            @endforeach
        </div>
    @endif
</div>
