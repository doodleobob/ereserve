@props(['name'])
<svg viewBox="0 0 24 24" aria-hidden="true">
    @switch($name)
        @case('home')
            <path d="M3 10.5 12 3l9 7.5M5 10v10h14V10M9 20v-6h6v6" />
            @break
        @case('calendar')
            <path d="M8 2v4M16 2v4M3 10h18M8 14h2M14 14h2M8 18h2" /><rect x="3" y="4" width="18" height="18" rx="2" />
            @break
        @case('clipboard')
            <rect x="8" y="2" width="8" height="4" rx="1" /><path d="M8 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2M8 11h.01M12 11h4M8 16h.01M12 16h4" />
            @break
        @case('shield')
            <path d="M12 3 3 7v5c0 5 9 10 9 10s9-5 9-10V7l-9-4Z" /><path d="m8 12 3 3 5-5" />
            @break
        @case('payment')
            <rect x="2" y="4" width="20" height="16" rx="2" /><path d="M2 9h20M6 15h4" />
            @break
        @case('grid')
            <rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" />
            @break
        @case('building')
            <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2M10 6h4M10 10h4M10 14h4" />
            @break
        @case('package')
            <path d="m12 2 9 5v10l-9 5-9-5V7l9-5ZM3 7l9 5 9-5M12 12v10M7.5 4.5l9 5" />
            @break
        @case('users')
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /><circle cx="9" cy="7" r="4" />
            @break
        @case('admin')
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M19 8v6M22 11h-6" /><circle cx="9" cy="7" r="4" />
            @break
        @case('chart')
            <path d="M4 3v18h17M8 17v-5M13 17V8M18 17V4" />
            @break
    @endswitch
</svg>
