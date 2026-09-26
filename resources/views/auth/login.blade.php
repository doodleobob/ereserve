<x-layouts.auth title="Login - eReserve">
    <section class="auth-card auth-card-login">
        <div class="auth-header">
            <div class="auth-icon auth-icon-blue" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="img">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                    <path d="M10 17l5-5-5-5" />
                    <path d="M15 12H3" />
                </svg>
            </div>
            <h1>eReserve</h1>
            <p>Barangay Washington<br>Asset Reservation Platform</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="auth-form">
            @csrf

            <div class="form-group">
                <label for="email">Email Address <span>*</span></label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="your.email@example.com" required autofocus>
                @error('email')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="password">Password <span>*</span></label>
                <input id="password" name="password" type="password" placeholder="Enter your password" required>
                @error('password')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="auth-button auth-button-blue">Login</button>
        </form>

        <p class="auth-switch">Don't have an account? <a href="{{ route('register') }}">Register here</a></p>

    </section>
</x-layouts.auth>
