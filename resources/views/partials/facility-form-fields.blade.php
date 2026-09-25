@php
    $fieldPrefix = \Illuminate\Support\Str::slug($submitLabel.'-'.($item['slug'] ?? 'new'));
    $oldName = old('name', $item['name'] ?? '');
    $oldCategory = old('category', $item['category'] ?? 'Facility');
    $oldDescription = old('description', $item['description'] ?? '');
    $oldLocation = old('location', $item['location'] ?? 'Barangay Washington');
    $oldCapacity = old('capacity', $item['capacity'] ?? '');
    $oldStatus = old('status', $item['status'] ?? 'Available');
@endphp

<div class="facility-modal-body">
    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-hourly-rate">Hourly Rate (₱ / hour)</label>
        <input id="{{ $fieldPrefix }}-hourly-rate" name="hourly_rate" type="number" min="0" max="99999999.99" step="0.01" value="{{ old('hourly_rate', $item['hourly_rate'] ?? '0.00') }}" required>
        @error('hourly_rate')<p class="form-error">{{ $message }}</p>@enderror
    </div>
    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-name">Facility/Resource Name <span>*</span></label>
        <input id="{{ $fieldPrefix }}-name" name="name" type="text" value="{{ $oldName }}" placeholder="e.g., Barangay Hall Main Function Room" required>
    </div>

    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-category">Category <span>*</span></label>
        <select id="{{ $fieldPrefix }}-category" name="category" required>
            <option value="Facility" @selected($oldCategory === 'Facility')>Facility</option>
            <option value="Equipment" @selected($oldCategory === 'Equipment')>Equipment</option>
        </select>
    </div>

    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-description">Description <span>*</span></label>
        <textarea id="{{ $fieldPrefix }}-description" name="description" placeholder="Describe the facility or equipment" required>{{ $oldDescription }}</textarea>
    </div>

    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-photos">Facility Photos</label>
        @if ($item && count($item['photos']) > 0)
            <div class="facility-photo-grid" aria-label="Current facility photos">
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
            <p class="form-error">{{ $message }}</p>
        @enderror
        @error('photos.*')
            <p class="form-error">{{ $message }}</p>
        @enderror
        @error('photo')
            <p class="form-error">{{ $message }}</p>
        @enderror
        @error('remove_photo_ids')
            <p class="form-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-location">Location <span>*</span></label>
        <input id="{{ $fieldPrefix }}-location" name="location" type="text" value="{{ $oldLocation }}" required>
    </div>

    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-capacity">Capacity <span>*</span></label>
        <input id="{{ $fieldPrefix }}-capacity" name="capacity" type="number" min="1" value="{{ $oldCapacity }}" placeholder="Number of people" required>
    </div>

    <div class="modal-form-group">
        <label for="{{ $fieldPrefix }}-status">Status <span>*</span></label>
        <select id="{{ $fieldPrefix }}-status" name="status" required>
            <option value="Available" @selected($oldStatus === 'Available')>Available</option>
            <option value="Unavailable" @selected($oldStatus === 'Unavailable')>Unavailable</option>
        </select>
    </div>
</div>

<div class="facility-modal-actions">
    <button type="submit" class="facility-modal-primary">{{ $submitLabel }}</button>
    <button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button>
</div>
