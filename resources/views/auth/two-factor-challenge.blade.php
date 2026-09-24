<x-layouts.auth title="Security Code - eReserve">
    <section class="auth-card auth-card-login">
        <div class="auth-header">
            <h1>Two-Factor Authentication</h1>
            <p>We sent a 6-digit security code to:<br>{{ $user->maskedTwoFactorDestination() }}<br>Enter the code to continue.</p>
        </div>

        @if (session('status'))
            <p class="reservation-alert" role="status">{{ session('status') }}</p>
        @endif
        @error('two_factor')
            <p class="form-error" role="alert">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('two-factor.verify') }}" class="auth-form">
            @csrf
            <div class="form-group">
                <label for="code">Security code</label>
                <input id="code" name="code" class="security-code-input" type="text" inputmode="numeric"
                    autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required autofocus>
                @error('code')
                    <p class="form-error" role="alert">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="auth-button auth-button-blue">Verify</button>
        </form>
        <p class="auth-switch">Codes expire after 5 minutes. Check your spam folder if needed.</p>
        <div class="auth-form auth-switch">
            <form method="POST" action="{{ route('two-factor.resend') }}">
                @csrf
                <button type="submit" class="auth-button auth-button-blue">Resend Code</button>
            </form>
            <form method="POST" action="{{ route('two-factor.cancel') }}">
                @csrf
                <button type="submit" class="auth-button auth-button-blue">Cancel and return to login</button>
            </form>
        </div>
    </section>
</x-layouts.auth>
