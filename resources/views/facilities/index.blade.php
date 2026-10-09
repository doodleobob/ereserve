<x-layouts.user title="{{ $isAdmin ? 'Facility Management' : 'Facilities' }} - eReserve" active="facilities">
    @if ($isAdmin)
        <section class="page-heading page-header admin-facility-heading">
            <div>
                <h2>Facility Management</h2>
                <p>Add, edit, and manage facilities and equipment</p>
            </div>

            <button type="button" class="add-facility-button button-create" data-modal-open="add-facility-modal">
                <x-add-icon />
                Add Facility
            </button>
        </section>

        @if (session('facility_status'))
            <p class="reservation-alert">{{ session('facility_status') }}</p>
        @endif

        <section class="facility-grid admin-facility-grid">
            @forelse ($items as $item)
                <article class="facility-card">
                    @include('facilities.partials.card-image', ['item' => $item])

                    <div class="facility-body">
                        @include('facilities.partials.facility-card-content')

                        <div class="facility-card-footer admin-facility-card-footer">
                            <button type="button" class="facility-card-action facility-modal-secondary" data-modal-open="view-facility-{{ $item['slug'] }}">View</button>
                            <button type="button" class="facility-card-action edit-facility-button" data-modal-open="edit-facility-{{ $item['slug'] }}">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M12 20h9" />
                                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                </svg>
                                Edit
                            </button>
                            <button type="button" class="facility-card-action delete-facility-button" data-modal-open="delete-facility-{{ $item['slug'] }}" aria-label="Delete {{ $item['name'] }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v5M14 11v5" /></svg></button>
                        </div>
                    </div>
                </article>

                @include('facilities.partials.facility-view-modal', ['facility' => $item])
                <x-modal id="edit-facility-{{ $item['slug'] }}" title="Edit Facility" size="large">
                    <form method="POST" action="{{ route('facilities.update', $item['slug']) }}" class="facility-modal-form" data-modal-action enctype="multipart/form-data">
                        @csrf
                        @method('PATCH')
                        @include('facilities.partials.facility-form-fields', ['item' => $item, 'submitLabel' => 'Update Facility'])
                    </form>
                </x-modal>
                <x-modal id="delete-facility-{{ $item['slug'] }}" title="Delete Facility" size="small">
                    <form method="POST" action="{{ route('facilities.destroy', $item['slug']) }}" data-modal-action>
                        @csrf @method('DELETE')
                        <div class="facility-modal-body"><p>Delete {{ $item['name'] }}?</p><p data-action-error role="alert" hidden></p></div>
                        <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button><button type="submit" class="facility-modal-primary button-danger">Delete Facility</button></div>
                    </form>
                </x-modal>
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

        <x-modal id="add-facility-modal" title="Add New Facility" size="large">
            <form method="POST" action="{{ route('facilities.store') }}" class="facility-modal-form" data-modal-action enctype="multipart/form-data">
                @csrf
                @include('facilities.partials.facility-form-fields', ['item' => null, 'submitLabel' => 'Add Facility'])
            </form>
        </x-modal>
    @else
        <section class="page-heading">
            <h2>Facilities and Equipment</h2>
            <p>Browse and reserve available facilities and equipment</p>
        </section>

        <form method="GET" action="{{ route('facilities') }}" class="filter-card filter-toolbar">
            <div class="filter-group">
                <label for="browse-barangay">Barangay</label>
                <select id="browse-barangay" name="barangay" data-browse-barangay>
                    @foreach ($barangays as $barangay)
                        <option value="{{ $barangay }}" @selected($selectedBarangay === $barangay)>{{ $barangay }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-actions"><button type="submit" class="button button-primary">Browse</button></div>
        </form>
        <section class="filter-card filter-toolbar" aria-label="Facility filters">
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
                    @include('facilities.partials.card-image', ['item' => $item])

                    <div class="facility-body">
                        @include('facilities.partials.facility-card-content')

                        @if (! $item['can_reserve'])
                            <p class="facility-reservation-restriction">Residents Only</p>
                        @elseif (! $item['is_available'])
                            <p class="facility-reservation-restriction">Currently unavailable for reservations.</p>
                        @endif
                        <div class="facility-card-footer resident-facility-card-footer">
                            <button type="button" class="facility-card-action facility-modal-secondary" data-modal-open="view-facility-{{ $item['slug'] }}">View Details</button>
                            <button type="button" class="facility-card-action facility-modal-primary" @if ($item['can_reserve'] && $item['is_available']) data-modal-open="reserve-facility-{{ $item['slug'] }}" @else disabled @endif>Reserve</button>
                        </div>
                    </div>
                </article>
                @include('facilities.partials.facility-view-modal', ['facility' => $item, 'residentDetails' => true])
                @if ($item['can_reserve'] && $item['is_available'])
                    <x-modal id="reserve-facility-{{ $item['slug'] }}" title="Reserve Facility" size="medium" class="facility-reservation-modal">
                        @include('facilities.partials.facility-reservation-form', ['facility' => $item, 'inModal' => true])
                    </x-modal>
                @endif
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

    @push('scripts')
        <script src="{{ asset('js/facility-management.js') }}?v={{ filemtime(public_path('js/facility-management.js')) }}" defer></script>
    @endpush
    @include('partials.reservation-time-validation')
</x-layouts.user>
