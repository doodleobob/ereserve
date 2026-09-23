<x-layouts.user title="Admin Management - eReserve" active="admins">
    <section class="page-heading profile-heading">
        <h2>Admin Management</h2>
        <p>Create barangay admin accounts</p>
    </section>

    <section class="profile-grid">
        <div class="profile-main-column">
            <article class="profile-card">
                <h3>Create Admin</h3>

                @if (session('admin_status'))
                    <div class="reservation-alert" role="status">{{ session('admin_status') }}</div>
                @endif

                <form method="POST" action="{{ route('admins.store') }}" class="profile-form">
                    @csrf

                    <div class="profile-group">
                        <label for="name">Full Name <span>*</span></label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required>
                        @error('name')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="profile-group">
                        <label for="email">Email Address <span>*</span></label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required>
                        @error('email')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="profile-group">
                        <label for="barangay">Barangay <span>*</span></label>
                        <select id="barangay" name="barangay" required>
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
                        <label for="password">Password <span>*</span></label>
                        <input id="password" name="password" type="password" required>
                        @error('password')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="profile-group">
                        <label for="password_confirmation">Confirm Password <span>*</span></label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required>
                    </div>

                    <div class="profile-actions">
                        <button type="submit" class="profile-primary-button">Create Admin</button>
                    </div>
                </form>
            </article>
        </div>

        <aside class="profile-card account-card">
            <h3>Current Admins</h3>

            <dl class="account-info-list">
                @forelse ($admins as $admin)
                    <div>
                        <dt>{{ $admin->name }}</dt>
                        <dd>{{ $admin->barangay }}</dd>
                    </div>
                @empty
                    <div>
                        <dt>No admins yet</dt>
                        <dd>Create the first barangay admin account.</dd>
                    </div>
                @endforelse
            </dl>
        </aside>
    </section>
</x-layouts.user>
