<x-layouts.user title="Edit Admin - eReserve" active="admins">
    <section class="page-heading profile-heading">
        <h2>Edit Barangay Admin</h2>
    </section>

    <section class="profile-grid">
        <div class="profile-main-column">
            <article class="profile-card">
                <h3>Admin Information</h3>
                @if ($errors->any())
                    <div class="reservation-alert" role="alert">Please review the highlighted fields.</div>
                @endif
                <form method="POST" action="{{ route('admins.update', $admin) }}" class="profile-form" data-admin-edit-form>
                    @csrf
                    @method('PATCH')
                    <div class="profile-group">
                        <label for="admin-name">Full Name <span>*</span></label>
                        <input id="admin-name" name="name" type="text" value="{{ old('name', $admin->name) }}" required maxlength="255">
                        @error('name')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="profile-group">
                        <label for="admin-email">Email Address <span>*</span></label>
                        <input id="admin-email" name="email" type="email" value="{{ old('email', $admin->email) }}" required maxlength="255">
                        @error('email')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <x-phone-number-field :value="$admin->phone_number" />
                    <div class="profile-group">
                        <label for="admin-barangay">Assigned Barangay <span>*</span></label>
                        <select id="admin-barangay" name="barangay" required>
                            @foreach ($barangays as $barangay)
                                <option value="{{ $barangay }}" @selected(old('barangay', $admin->barangay) === $barangay)>{{ $barangay }}</option>
                            @endforeach
                        </select>
                        @error('barangay')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <p>Barangay reassignment changes future administrative scope. Existing records and permission restrictions are preserved.</p>
                    <div class="profile-actions">
                        <a class="button button-secondary" href="{{ route('admins.index') }}">Cancel</a>
                        <button type="submit" class="profile-primary-button">Save Changes</button>
                    </div>
                </form>
                <noscript><p class="reservation-alert">Enable JavaScript to review and confirm administrator changes.</p></noscript>
            </article>
        </div>
        <aside class="profile-card account-card">
            <h3>Account Recovery</h3>
            <p>Send a secure reset link to {{ $admin->email }} through the existing password recovery system. Passwords and reset tokens are not shown here.</p>
            <button type="button" class="button button-secondary" data-modal-open="admin-password-reset-confirmation">Send Password Reset Link</button>
        </aside>
    </section>
    <x-modal id="admin-password-reset-confirmation" title="Send Password Reset Link" size="small">
        <form method="POST" action="{{ route('admins.password-reset', $admin) }}" data-modal-action>
            @csrf
            <div class="facility-modal-body">
                <p>A secure reset link will be sent to {{ $admin->email }}. The password and reset token will not be shown.</p>
                <p data-action-error role="alert" hidden></p>
            </div>
            <div class="facility-modal-actions">
                <button type="button" class="facility-modal-secondary" data-modal-close autofocus>Cancel</button>
                <button type="submit" class="facility-modal-primary">Send Reset Link</button>
            </div>
        </form>
    </x-modal>
    <x-modal id="admin-edit-confirmation" title="Confirm Admin Changes" size="small">
        <div class="facility-modal-body">
            <p>Confirm these administrator changes. If the barangay changes, future access will use the new assignment. Historical records will remain with their original barangay.</p>
            <p data-action-error role="alert" hidden></p>
        </div>
        <div class="facility-modal-actions">
            <button type="button" class="facility-modal-secondary" data-modal-close autofocus>Cancel</button>
            <button type="button" class="facility-modal-primary" data-admin-edit-confirm>Confirm Changes</button>
        </div>
    </x-modal>
</x-layouts.user>
@push('scripts')
    <script src="{{ asset('js/admin-account-edit.js') }}?v={{ filemtime(public_path('js/admin-account-edit.js')) }}" defer></script>
@endpush
