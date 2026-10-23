@php
    $fieldPrefix = \Illuminate\Support\Str::slug($submitLabel.'-'.($item['slug'] ?? 'new'));
    $oldName = old('name', $item['name'] ?? '');
    $oldCategory = $item['category'] ?? $resourceCategory;
    $oldDescription = old('description', $item['description'] ?? '');
    $oldLocation = old('location', $item['location'] ?? 'Barangay '.auth()->user()->barangay);
    $oldCapacity = old('capacity', $item['capacity'] ?? '');
    $oldStatus = old('status', $item['status'] ?? 'Available');
@endphp

<div class="facility-modal-body">
    <fieldset class="modal-form-group reservation-access-options">
        <legend>Reservation Access</legend>
        @foreach (['residents_only' => 'Residents of this barangay only', 'all_registered_users' => 'All registered users'] as $access => $label)
            <label for="{{ $fieldPrefix }}-{{ $access }}">
                <input id="{{ $fieldPrefix }}-{{ $access }}" type="radio" name="reservation_access" value="{{ $access }}" @checked(old('reservation_access', $item['reservation_access'] ?? 'residents_only') === $access) required>
                {{ $label }}
            </label>
        @endforeach
        @error('reservation_access')<p data-validation-field="reservation_access" class="form-error">{{ $message }}</p>@enderror
    </fieldset>
    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-hourly-rate">Hourly Rate (₱ / hour)</label>
        <input id="{{ $fieldPrefix }}-hourly-rate" name="hourly_rate" type="number" min="0" max="99999999.99" step="0.01" value="{{ old('hourly_rate', $item['hourly_rate'] ?? '0.00') }}" required>
        @error('hourly_rate')<p data-validation-field="hourly_rate" class="form-error">{{ $message }}</p>@enderror
    </div>
    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-name">{{ $oldCategory }} Name <span>*</span></label>
        <input id="{{ $fieldPrefix }}-name" name="name" type="text" value="{{ $oldName }}" placeholder="{{ $oldCategory === 'Equipment' ? 'e.g., Folding Chairs' : 'e.g., Barangay Hall Main Function Room' }}" required>
        @error('name')<p class="form-error" data-validation-field="name">{{ $message }}</p>@enderror
    </div>

    <input type="hidden" name="category" value="{{ $oldCategory }}">

    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-description">Description <span>*</span></label>
        <textarea id="{{ $fieldPrefix }}-description" name="description" placeholder="Describe the {{ strtolower($oldCategory) }}" required>{{ $oldDescription }}</textarea>
        @error('description')<p class="form-error" data-validation-field="description">{{ $message }}</p>@enderror
    </div>

    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-photos">{{ $oldCategory }} Photos</label>
        @if ($item && count($item['photos']) > 0)
            <div class="facility-photo-grid" aria-label="Current {{ strtolower($oldCategory) }} photos">
                @foreach ($item['photos'] as $photo)
                    <label class="facility-photo-item">
                        <img src="{{ $photo['url'] }}" alt="Current photo {{ $loop->iteration }} of {{ $item['name'] }}">
                        @if ($photo['id'])
                            <span class="facility-photo-remove">
                                <input type="checkbox" name="remove_photo_ids[]" value="{{ $photo['id'] }}">
                                Remove
                            </span>
                        @endif
                    </label>
                @endforeach
            </div>
        @endif
        <input
            id="{{ $fieldPrefix }}-photos"
            name="photos[]"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            multiple
            data-facility-photo-input
        >
        <small>Select up to 4 JPG, PNG, or WebP photos, 5 MB each.</small>
        <div class="facility-photo-grid facility-photo-selection" data-facility-photo-preview hidden></div>
        @error('photos')
            <p data-validation-field="photos" class="form-error">{{ $message }}</p>
        @enderror
        @error('photos.*')
            <p data-validation-field="photos.*" class="form-error">{{ $message }}</p>
        @enderror
        @error('photo')
            <p data-validation-field="photo" class="form-error">{{ $message }}</p>
        @enderror
        @error('remove_photo_ids')
            <p data-validation-field="remove_photo_ids" class="form-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-location">Location <span>*</span></label>
        <input id="{{ $fieldPrefix }}-location" name="location" type="text" value="{{ $oldLocation }}" required>
        @error('location')<p class="form-error" data-validation-field="location">{{ $message }}</p>@enderror
    </div>

    @if ($oldCategory === 'Facility')
    <div class="modal-form-group" data-resource-field="Facility">
        <label for="{{ $fieldPrefix }}-capacity">Capacity (People) <span>*</span></label>
        <input id="{{ $fieldPrefix }}-capacity" name="capacity" type="number" min="1" step="1" max="10000" value="{{ $oldCapacity }}" placeholder="Number of people" required>
        @error('capacity')<p class="form-error" data-validation-field="capacity">{{ $message }}</p>@enderror
    </div>
    @else
    <div class="modal-form-group" data-resource-field="Equipment">
        <label for="{{ $fieldPrefix }}-quantity">Total Quantity <span>*</span></label>
        <input id="{{ $fieldPrefix }}-quantity" name="total_quantity" type="number" min="0" step="1" max="4294967295" value="{{ old('total_quantity', $item['total_quantity'] ?? '') }}" @if(($item['category'] ?? null) === 'Equipment' && $item['total_quantity'] === null) data-stock-unverified @else required @endif>
        <small>Enter the verified number of physical units. Existing bookings without a quantity continue to reserve the whole resource.</small>
        @if(($item['category'] ?? null) === 'Equipment' && $item['total_quantity'] === null)<small>You may leave stock blank while updating administrative availability. New quantity requests remain disabled until stock is verified.</small>@endif
        <small>The hourly rate applies to the reservation duration; quantity does not multiply the price.</small>
        @error('total_quantity')<p data-validation-field="total_quantity" class="form-error">{{ $message }}</p>@enderror
    </div>
    @if($item)
        <div class="modal-form-group">
            <label for="{{ $fieldPrefix }}-inventory-reason">Reason for stock change</label>
            <textarea id="{{ $fieldPrefix }}-inventory-reason" name="inventory_reason" maxlength="1000">{{ old('inventory_reason') }}</textarea>
            @error('inventory_reason')<p class="form-error" data-validation-field="inventory_reason">{{ $message }}</p>@enderror
            <small>Required when changing or verifying Total Quantity. Use the Inventory tab to record damage, repairs, and stock adjustments.</small>
        </div>
    @endif

    @endif

    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-status">Status <span>*</span></label>
        <select id="{{ $fieldPrefix }}-status" name="status" required>
            <option value="Available" @selected($oldStatus === 'Available')>Available</option>
            <option value="Unavailable" @selected($oldStatus === 'Unavailable')>Unavailable</option>
        </select>
        @error('status')<p class="form-error" data-validation-field="status">{{ $message }}</p>@enderror
    </div>
    <p data-action-error role="alert" hidden></p>
</div>

<div class="facility-modal-actions">
    <button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button>
    <button type="submit" class="facility-modal-primary">{{ $submitLabel }}</button>
</div>
