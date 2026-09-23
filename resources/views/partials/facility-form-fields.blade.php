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
        <label for="{{ $fieldPrefix }}-photo">Facility Photo</label>
        @if ($item && $item['photo_url'])
            <img class="facility-photo-preview" src="{{ $item['photo_url'] }}" alt="Current photo of {{ $item['name'] }}">
        @endif
        <input id="{{ $fieldPrefix }}-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
        <small>JPG, PNG, or WebP up to 5 MB.</small>
        @error('photo')
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
