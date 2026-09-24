<article class="profile-card" id="security">
    <h3>Security</h3>
    <p>Email Verification: <strong>{{ $user->hasVerifiedEmail() ? 'Verified' : 'Not verified' }}</strong></p>
    <h3>Two-Factor Authentication</h3>
    <p>Add an extra layer of security to your account.</p>
    <p>Status: <strong>{{ $user->twoFactorEnabled() ? 'Enabled' : 'Disabled' }}</strong></p>
    <p>{{ $user->twoFactorEnabled() ? 'A security code will be sent to your verified email when you sign in.' : 'When enabled, a 6-digit security code will be sent to your verified email each time you sign in.' }}</p>
    <p>Email: <strong>{{ $user->maskedTwoFactorDestination() }}</strong></p>

    @if (session('security_status'))
        <div class="reservation-alert" role="status">{{ session('security_status') }}</div>
    @endif
    @if ($securityErrors->any())
        <div class="form-error" role="alert">
            @foreach ($securityErrors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    @if ($user->twoFactorEnabled())
        <details class="security-confirmation" @if ($securityErrors->has('current_password')) open @endif>
            <summary>Disable Two-Factor Authentication</summary>
            <h4>Disable Two-Factor Authentication?</h4>
            <p>Your account will no longer require an email security code when signing in.</p>
            <form method="POST" action="{{ route('two-factor.disable') }}" class="profile-form">
                @csrf
                @method('DELETE')
                <div class="profile-group">
                    <label for="disable-password">Current Password</label>
                    <input id="disable-password" name="current_password" type="password" autocomplete="current-password" required>
                </div>
                <div class="profile-actions">
                    <button type="reset" class="profile-secondary-button" onclick="this.closest('details').open = false">Cancel</button>
                    <button type="submit" class="profile-primary-button">Disable Two-Factor Authentication</button>
                </div>
            </form>
        </details>
    @elseif ($pending)
        <div class="security-confirmation">
            <h4>Enable Two-Factor Authentication</h4>
            <p>We sent a 6-digit security code to {{ $user->maskedTwoFactorDestination() }}.</p>
            <p>Enter the code to confirm that you want to enable two-factor authentication. The code expires in 5 minutes.</p>
            <form method="POST" action="{{ route('two-factor.confirm') }}" class="profile-form">
                @csrf
                <div class="profile-group">
                    <label for="setup-code">Security code</label>
                    <input id="setup-code" class="security-code-input" name="code" type="text" inputmode="numeric"
                        autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
                </div>
                <button class="profile-primary-button" type="submit">Verify and Enable</button>
            </form>
            <form method="POST" action="{{ route('two-factor.setup.resend') }}" class="security-resend">
                @csrf
                <button class="profile-secondary-button" type="submit">Resend Code</button>
            </form>
            <form method="POST" action="{{ route('two-factor.setup.cancel') }}" class="security-resend">
                @csrf
                <button class="profile-secondary-button" type="submit">Cancel setup</button>
            </form>
        </div>
    @else
        <details class="security-confirmation" @if ($securityErrors->has('current_password')) open @endif>
            <summary>Enable Two-Factor Authentication</summary>
            <p>Confirm your current password to request an email security code.</p>
            <form method="POST" action="{{ route('two-factor.setup') }}" class="profile-form">
                @csrf
                <div class="profile-group">
                    <label for="setup-password">Current password</label>
                    <input id="setup-password" name="current_password" type="password" autocomplete="current-password" required>
                </div>
                <div class="profile-actions">
                    <button type="reset" class="profile-secondary-button" onclick="this.closest('details').open = false">Cancel</button>
                    <button class="profile-primary-button" type="submit">Send security code</button>
                </div>
            </form>
        </details>
    @endif
</article>
