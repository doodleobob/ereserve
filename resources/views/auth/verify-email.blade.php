<x-layouts.auth title="Verify Email - eReserve">
    <section class="auth-card auth-card-login verification-card">
        <div data-verification-waiting>
            <div class="auth-header">
                <h1>Verify your email</h1>
                <p>Enter the 6-digit code sent to your email to continue to eReserve.</p>
            </div>

            <p class="auth-switch">{{ auth()->user()->email }}</p>
            <p class="auth-switch">Check your inbox and spam folder. Codes expire after 5 minutes. Request a new code below if needed.</p>

            @if (session('status') === 'verification-code-sent')
                <p class="auth-switch" role="status">A new verification code has been sent. Previous codes no longer work.</p>
            @endif

            @error('verification')
                <p class="form-error" role="alert">{{ $message }}</p>
            @enderror

            <form method="POST" action="{{ route('verification.verify') }}" class="auth-form">
                @csrf
                <div class="form-group">
                    <label for="code">Verification code</label>
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
                    @error('code')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="auth-button auth-button-blue">Verify email</button>
            </form>

            <div class="auth-form auth-switch">
                <form method="POST" action="{{ route('verification.send') }}" class="auth-form">
                    @csrf
                    <button type="submit" class="auth-button auth-button-blue">Resend verification code</button>
                </form>

                <form method="POST" action="{{ route('logout') }}" class="auth-form">
                    @csrf
                    <button type="submit" class="auth-button auth-button-blue">Log out</button>
                </form>
            </div>
        </div>
    </section>
</x-layouts.auth>
