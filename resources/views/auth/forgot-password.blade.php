<x-layouts.auth title="Forgot Password - eReserve">
    <section class="auth-card auth-card-login">
        <div class="auth-header">
            <h1>eReserve</h1>
            <p>Public Facility Reservation &amp; Resource Utilization Management System</p>
        </div>

        <h2 class="auth-form-heading">Forgot password?</h2>
        <p class="auth-help">Enter your registered email address to request a password reset link.</p>

        @if (session('status'))
            <p class="auth-status" role="status">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="auth-form">
            @csrf
            <div class="form-group">
                <label for="email">Email Address <span>*</span></label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="Enter your registered email" autocomplete="email" required autofocus>
                @error('email')
                    <p class="form-error" role="alert">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="auth-button auth-button-blue">Send Password Reset Link</button>
        </form>

        <p class="auth-switch"><a href="{{ route('login') }}">Back to Login</a></p>
    </section>
</x-layouts.auth>
