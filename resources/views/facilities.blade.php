<x-layouts.user title="{{ $isAdmin ? 'Facility Management' : 'Facilities' }} - eReserve" active="facilities">
    @if ($isAdmin)
        <section class="page-heading admin-facility-heading">
            <div>
                <h2>Facility Management</h2>
                <p>Add, edit, and manage facilities and equipment</p>
            </div>

            <button type="button" class="add-facility-button" data-modal-open="add-facility-modal">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Add Facility
            </button>
        </section>

        @if (session('facility_status'))
            <p class="reservation-alert">{{ session('facility_status') }}</p>
        @endif

        <section class="facility-grid admin-facility-grid">
            @forelse ($items as $item)
                <article class="facility-card">
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

                    <div class="facility-body">
                        <div class="facility-title-row">
                            <h3>{{ $item['name'] }}</h3>
                            <span class="availability-badge availability-badge-{{ \Illuminate\Support\Str::slug($item['display_status']) }}">
                                {{ $item['display_status'] }}
                            </span>
                        </div>
                        @if ($item['current_reservation'])
                            <p class="facility-description">
                                {{ $item['current_reservation']['start_time'] }} - {{ $item['current_reservation']['end_time'] }}
                            </p>
                        @endif
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

                        <div class="facility-card-footer admin-facility-card-footer">
                            <button type="button" class="edit-facility-button" data-modal-open="edit-facility-{{ $item['slug'] }}">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M12 20h9" />
                                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                </svg>
                                Edit
                            </button>
                            <form method="POST" action="{{ route('facilities.destroy', $item['slug']) }}" data-delete-facility-form>
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="delete-facility-button" aria-label="Delete {{ $item['name'] }}">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M3 6h18" />
                                        <path d="M8 6V4h8v2" />
                                        <path d="M19 6l-1 14H6L5 6" />
                                        <path d="M10 11v5M14 11v5" />
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </article>

                <div class="facility-modal" id="edit-facility-{{ $item['slug'] }}" hidden>
                    <div class="facility-modal-panel" role="dialog" aria-modal="true" aria-labelledby="edit-facility-title-{{ $item['slug'] }}">
                        <div class="facility-modal-header">
                            <h3 id="edit-facility-title-{{ $item['slug'] }}">Edit Facility</h3>
                            <button type="button" class="facility-modal-close" data-modal-close aria-label="Close edit facility form">&times;</button>
                        </div>
                        <form method="POST" action="{{ route('facilities.update', $item['slug']) }}" class="facility-modal-form" enctype="multipart/form-data">
                            @csrf
                            @method('PATCH')
                            @include('partials.facility-form-fields', ['item' => $item, 'submitLabel' => 'Update Facility'])
                        </form>
                    </div>
                </div>
            @empty
                <section class="reservation-empty-card admin-reservation-empty-card" aria-label="No facilities">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18" />
                        <path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2" />
                        <path d="M10 6h4M10 10h4M10 14h4" />
                    </svg>
                    <p>No facilities have been added yet.</p>
                </section>
            @endforelse
        </section>

        <div class="facility-modal" id="add-facility-modal" hidden>
            <div class="facility-modal-panel" role="dialog" aria-modal="true" aria-labelledby="add-facility-title">
                <div class="facility-modal-header">
                    <h3 id="add-facility-title">Add New Facility</h3>
                    <button type="button" class="facility-modal-close" data-modal-close aria-label="Close add facility form">&times;</button>
                </div>
                <form method="POST" action="{{ route('facilities.store') }}" class="facility-modal-form" enctype="multipart/form-data">
                    @csrf
                    @include('partials.facility-form-fields', ['item' => null, 'submitLabel' => 'Add Facility'])
                </form>
            </div>
        </div>
    @else
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
            @forelse ($items as $item)
                <article
                    class="facility-card"
                    data-facility-card
                    data-name="{{ strtolower($item['name']) }}"
                    data-category="{{ strtolower($item['category']) }}"
                    data-status="{{ strtolower($item['status']) }}"
                >
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

                    <div class="facility-body">
                        <div class="facility-title-row">
                            <h3>{{ $item['name'] }}</h3>
                            <span class="availability-badge availability-badge-{{ \Illuminate\Support\Str::slug($item['display_status']) }}">
                                {{ $item['display_status'] }}
                            </span>
                        </div>
                        @if ($item['current_reservation'])
                            <p class="facility-description">
                                {{ $item['current_reservation']['start_time'] }} - {{ $item['current_reservation']['end_time'] }}
                            </p>
                        @endif
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
            @empty
                <section class="reservation-empty-card" aria-label="No facilities">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18" />
                        <path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2" />
                        <path d="M10 6h4M10 10h4M10 14h4" />
                    </svg>
                    <p>No facilities are available yet.</p>
                </section>
            @endforelse
        </section>

        <p class="empty-facility-message" data-empty-facilities hidden>No facilities found.</p>
    @endif

    <script>
        const searchInput = document.querySelector('#facility-search');
        const categorySelect = document.querySelector('#facility-category');
        const statusSelect = document.querySelector('#facility-status');
        const cards = Array.from(document.querySelectorAll('[data-facility-card]'));
        const emptyMessage = document.querySelector('[data-empty-facilities]');

        function filterFacilities() {
            const search = searchInput ? searchInput.value.trim().toLowerCase() : '';
            const category = categorySelect ? categorySelect.value : 'all';
            const status = statusSelect ? statusSelect.value : 'all';
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

            if (emptyMessage) {
                emptyMessage.hidden = visibleCount !== 0;
            }
        }

        searchInput?.addEventListener('input', filterFacilities);
        categorySelect?.addEventListener('change', filterFacilities);
        statusSelect?.addEventListener('change', filterFacilities);

        document.querySelectorAll('[data-modal-open]').forEach((button) => {
            button.addEventListener('click', () => {
                document.getElementById(button.dataset.modalOpen).hidden = false;
            });
        });

        document.querySelectorAll('[data-modal-close]').forEach((button) => {
            button.addEventListener('click', () => {
                button.closest('.facility-modal').hidden = true;
            });
        });

        document.querySelectorAll('.facility-modal').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    modal.hidden = true;
                }
            });
        });

        document.querySelectorAll('[data-delete-facility-form]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (!confirm('Delete this facility?')) {
                    event.preventDefault();
                }
            });
        });
    </script>
</x-layouts.user>
