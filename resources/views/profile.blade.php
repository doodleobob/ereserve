<x-layouts.user title="Profile Settings - eReserve" active="profile">
    @php
        $user = auth()->user();
        $displayRole = match ($user->role) {
            'super_admin' => 'Super Admin',
            'admin' => 'Administrator',
            default => 'User',
        };
    @endphp

    <section class="page-heading profile-heading">
        <h2>Profile Settings</h2>
        <p>Manage your account information and security</p>
        <p><a href="#security">Security and two-factor authentication</a></p>
    </section>

    <section class="profile-grid">
        <div class="profile-main-column">
            <article class="profile-card">
                <h3>Personal Information</h3>

                @if (session('profile_status'))
                    <div class="reservation-alert" role="status">{{ session('profile_status') }}</div>
                @endif

                <form method="POST" action="{{ route('profile.update') }}" class="profile-form">
                    @csrf
                    @method('PATCH')

                    <div class="profile-group">
                        <label for="name">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M20 21a8 8 0 0 0-16 0" />
                                <circle cx="12" cy="7" r="4" />
                            </svg>
                            Full Name <span>*</span>
                        </label>
                        <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required>
                        @error('name')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="profile-group">
                        <label for="email">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="3" y="5" width="18" height="14" rx="2" />
                                <path d="m3 7 9 6 9-6" />
                            </svg>
                            Email Address <span>*</span>
                        </label>
                        <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required>
                        @error('email')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <x-phone-number-field :value="$user->phone_number" />
                    @if (!$user->phone_number)
                        <p>Add a phone number so authorized barangay personnel can contact you about reservations.</p>
                    @endif

                    @if ($user->two_factor_method === 'email')
                        <p>To change your verified email, first disable two-factor authentication in <a href="#security">the Security section below</a>.</p>
                    @endif
                    <div class="profile-actions">
                        <button type="submit" class="profile-primary-button">Save Changes</button>
                        <a class="profile-secondary-button" href="{{ route('profile.edit') }}">Cancel</a>
                    </div>
                </form>
            </article>

            <article class="profile-card">
                <h3>Change Password</h3>

                @if (session('password_status'))
                    <div class="reservation-alert" role="status">{{ session('password_status') }}</div>
                @endif

                <form method="POST" action="{{ route('profile.password.update') }}" class="profile-form">
                    @csrf
                    @method('PUT')

                    <div class="profile-group">
                        <label for="current_password">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="4" y="11" width="16" height="10" rx="2" />
                                <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                            </svg>
                            Current Password <span>*</span>
                        </label>
                        <input id="current_password" name="current_password" type="password" required>
                        @error('current_password')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="profile-group">
                        <label for="password">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="4" y="11" width="16" height="10" rx="2" />
                                <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                            </svg>
                            New Password <span>*</span>
                        </label>
                        <input id="password" name="password" type="password" placeholder="Minimum 8 characters" required>
                        @error('password')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="profile-group">
                        <label for="password_confirmation">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="4" y="11" width="16" height="10" rx="2" />
                                <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                            </svg>
                            Confirm New Password <span>*</span>
                        </label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required>
                    </div>

                    <div class="profile-actions">
                        <button type="submit" class="profile-primary-button">Change Password</button>
                        <a class="profile-secondary-button" href="{{ route('profile.edit') }}">Cancel</a>
                    </div>
                </form>
            </article>
            @include('partials.profile-security')
        </div>

        <aside class="profile-card account-card">
            <h3>Account Information</h3>

            <dl class="account-info-list">
                <div>
                    <dt>Account Role</dt>
                    <dd>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" />
                        </svg>
                        {{ $displayRole }}
                    </dd>
                </div>

                <div>
                    <dt>Barangay</dt>
                    <dd>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" />
                            <circle cx="12" cy="10" r="3" />
                        </svg>
                        {{ $user->barangay }}
                    </dd>
                </div>

                <div>
                    <dt>Account ID</dt>
                    <dd>{{ $user->id }}</dd>
                </div>
            </dl>

            <p>Your personal information is secure and will only be used for reservation management purposes.</p>
        </aside>
    </section>
</x-layouts.user>
