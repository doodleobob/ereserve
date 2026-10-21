<div class="profile-group">
    <label for="admin-name">Full Name <span>*</span></label>
    <input id="admin-name" name="name" type="text" value="{{ old('name') }}" required>
    @error('name')
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>

<div class="profile-group">
    <label for="admin-email">Email Address <span>*</span></label>
    <input id="admin-email" name="email" type="email" value="{{ old('email') }}" required>
    @error('email')
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>

<x-phone-number-field :required="true" />

<div class="profile-group">
    <label for="admin-barangay">Barangay <span>*</span></label>
    <select id="admin-barangay" name="barangay" required>
        <option value="">Select barangay</option>
        @foreach ($barangays as $barangay)
            <option value="{{ $barangay }}" @selected(old('barangay') === $barangay)>{{ $barangay }}</option>
        @endforeach
    </select>
    @error('barangay')
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>

<div class="profile-group">
    <label for="admin-password">Password <span>*</span></label>
    <input id="admin-password" name="password" type="password" required>
    @error('password')
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>

<div class="profile-group">
    <label for="admin-password_confirmation">Confirm Password <span>*</span></label>
    <input id="admin-password_confirmation" name="password_confirmation" type="password" required>
</div>

