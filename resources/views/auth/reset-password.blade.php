<x-layouts.auth title="Reset Password - eReserve">
    <section class="auth-card auth-card-login">
        <div class="auth-header">
            <h1>eReserve</h1>
            <p>Public Facility Reservation &amp; Resource Utilization Management System</p>
        </div>

        <h2 class="auth-form-heading">Create a new password</h2>
        <p class="auth-help">Use at least 8 characters and confirm your new password below.</p>

        @foreach (['reset', 'token', 'email'] as $errorKey)
            @error($errorKey)
                <p class="form-error auth-reset-error" role="alert">{{ $message }}</p>
            @enderror
        @endforeach

        <form method="POST" action="{{ route('password.update') }}" class="auth-form">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">
            <div class="form-group">
                <label for="password">New Password <span>*</span></label>
                <x-password-input id="password" name="password" placeholder="Enter new password" autocomplete="new-password" />
                @error('password')
                    <p class="form-error" role="alert">{{ $message }}</p>
                @enderror
            </div>
            <div class="form-group">
                <label for="password_confirmation">Confirm New Password <span>*</span></label>
                <x-password-input id="password_confirmation" name="password_confirmation" placeholder="Confirm new password" autocomplete="new-password" label="confirmation password" />
            </div>
            <button type="submit" class="auth-button auth-button-blue">Reset Password</button>
        </form>

        <p class="auth-switch"><a href="{{ route('password.request') }}">Request a new reset link</a></p>
        <p class="auth-switch"><a href="{{ route('login') }}">Back to Login</a></p>
    </section>
</x-layouts.auth>
