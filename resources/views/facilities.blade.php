<x-layouts.user title="Facilities - eReserve" active="facilities">
    <section class="page-heading">
        <h2>Facilities and Equipment</h2>
        <p>Browse and reserve available facilities and equipment</p>
    </section>

    <section class="filter-card" aria-label="Facility filters">
        <div class="filter-group search-group">
            <label for="facility-search">Search</label>
            <div class="search-field">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-4-4" />
                </svg>
                <input id="facility-search" type="search" placeholder="Search facilities...">
            </div>
        </div>

        <div class="filter-group">
            <label for="facility-category">Category</label>
            <select id="facility-category">
                <option value="all">All Categories</option>
                <option value="facility">Facility</option>
                <option value="equipment">Equipment</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="facility-status">Availability</label>
            <select id="facility-status">
                <option value="all">All Status</option>
                <option value="available">Available</option>
                <option value="unavailable">Unavailable</option>
            </select>
        </div>
    </section>

    <section class="facility-grid">
        @foreach ($items as $item)
            <article
                class="facility-card"
                data-facility-card
                data-name="{{ strtolower($item['name']) }}"
                data-category="{{ strtolower($item['category']) }}"
                data-status="{{ strtolower($item['status']) }}"
            >
                <div class="facility-image" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18" />
                        <path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2" />
                        <path d="M10 6h4M10 10h4M10 14h4" />
                    </svg>
                </div>

                <div class="facility-body">
                    <div class="facility-title-row">
                        <h3>{{ $item['name'] }}</h3>
                        <span class="availability-badge">{{ $item['status'] }}</span>
                    </div>
                    <p class="facility-description">{{ $item['list_description'] }}</p>
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

                    <div class="facility-card-footer">
                        <a class="details-button" href="{{ route('facilities.show', $item['slug']) }}">View Details</a>
                    </div>
                </div>
            </article>
        @endforeach
    </section>

    <p class="empty-facility-message" data-empty-facilities hidden>No facilities found.</p>

    <script>
        const searchInput = document.querySelector('#facility-search');
        const categorySelect = document.querySelector('#facility-category');
        const statusSelect = document.querySelector('#facility-status');
        const cards = Array.from(document.querySelectorAll('[data-facility-card]'));
        const emptyMessage = document.querySelector('[data-empty-facilities]');

        function filterFacilities() {
            const search = searchInput.value.trim().toLowerCase();
            const category = categorySelect.value;
            const status = statusSelect.value;
            let visibleCount = 0;

            cards.forEach((card) => {
                const matchesSearch = !search || card.dataset.name.includes(search);
                const matchesCategory = category === 'all' || card.dataset.category === category;
                const matchesStatus = status === 'all' || card.dataset.status === status;
                const isVisible = matchesSearch && matchesCategory && matchesStatus;

                card.hidden = !isVisible;
                if (isVisible) {
                    visibleCount += 1;
                }
            });

            emptyMessage.hidden = visibleCount !== 0;
        }

        searchInput.addEventListener('input', filterFacilities);
        categorySelect.addEventListener('change', filterFacilities);
        statusSelect.addEventListener('change', filterFacilities);
    </script>
</x-layouts.user>
