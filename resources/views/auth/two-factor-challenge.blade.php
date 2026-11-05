<x-layouts.auth title="Security Code - eReserve">
    <section class="auth-card auth-card-login">
        <div class="auth-header">
            <h1>Two-Factor Authentication</h1>
            <p>We sent a 6-digit security code to:<br>{{ $user->maskedTwoFactorDestination() }}<br>Enter the code to continue.</p>
        </div>

        @include('auth.partials.feedback')

        <form method="POST" action="{{ route('two-factor.verify') }}" class="auth-form">
            @csrf
            <div class="form-group">
                <label for="code">Security code</label>
                <input id="code" name="code" class="security-code-input" type="text" inputmode="numeric"
                    autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required autofocus>
            </div>
            <button type="submit" class="auth-button auth-button-blue">Verify</button>
        </form>
        <p class="auth-switch">Codes expire after 5 minutes. Check your spam folder if needed.</p>
        <div class="auth-form auth-switch">
            <form method="POST" action="{{ route('two-factor.resend') }}" data-security-code-resend data-resend-seconds="{{ $resendAvailableIn }}">
                @csrf
                <button type="submit" class="auth-button button-secondary" data-resend-button @disabled($resendAvailableIn > 0)>Resend Code</button>
                <p class="auth-switch" data-resend-countdown aria-live="polite">@if($resendAvailableIn > 0) Resend code in {{ $resendAvailableIn }} seconds. @endif</p>
            </form>
            <form method="POST" action="{{ route('two-factor.cancel') }}">
                @csrf
                <button type="submit" class="auth-button button-secondary">Cancel and return to login</button>
            </form>
        </div>
    </section>
</x-layouts.auth>
