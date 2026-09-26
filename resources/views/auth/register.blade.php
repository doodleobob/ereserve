<x-layouts.auth title="Register - eReserve">
    <section class="auth-card auth-card-register">
        <div class="auth-header">
            <div class="auth-icon auth-icon-blue" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="img">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M19 8v6" />
                    <path d="M22 11h-6" />
                </svg>
            </div>
            <h1>eReserve</h1>
            <p>Public Facility Reservation &amp; Resource Utilization Management System</p>
        </div>

        <form method="POST" action="{{ route('register.store') }}" class="auth-form">
            @csrf

            <div class="form-group">
                <label for="name">Full Name <span>*</span></label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="Juan Dela Cruz" autocomplete="name" required autofocus>
                @error('name')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="email">Email Address <span>*</span></label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="your.email@example.com" autocomplete="email" required>
                @error('email')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <x-phone-number-field :required="true" group-class="form-group" />

            <div class="form-group">
                <label for="barangay">Barangay <span>*</span></label>
                <select id="barangay" name="barangay" required>
                    <option value="">Select your barangay</option>
                    @foreach ($barangays as $barangay)
                        <option value="{{ $barangay }}" @selected(old('barangay') === $barangay)>{{ $barangay }}</option>
                    @endforeach
                </select>
                @error('barangay')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="password">Password <span>*</span></label>
                <x-password-input id="password" name="password" placeholder="Minimum 8 characters" autocomplete="new-password" />
                @error('password')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="password_confirmation">Confirm Password <span>*</span></label>
                <x-password-input id="password_confirmation" name="password_confirmation" placeholder="Re-enter your password" autocomplete="new-password" label="confirmation password" />
            </div>

            <button type="submit" class="auth-button auth-button-blue">Register</button>
        </form>

        <p class="auth-switch">Already have an account? <a href="{{ route('login') }}">Login here</a></p>
    </section>
</x-layouts.auth>
