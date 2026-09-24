<x-layouts.auth title="Verification Link - eReserve">
    <section class="auth-card auth-card-login">
        <div class="auth-header">
            <h1>Unable to verify this account</h1>
            @if ($reason === 'missing-account')
                <p>This verification link belongs to an account that no longer exists. Request a new verification email for your current account.</p>
            @elseif ($reason === 'wrong-account')
                <p>This verification link belongs to a different account. Log out, sign in to the account that received the email, then reopen the verification link.</p>
            @else
                <p>This link does not match your current email address. Request a new verification email and use the latest link.</p>
            @endif
        </div>

        <div class="auth-form">
            <a class="auth-button auth-button-blue verification-continue" href="{{ route('verification.notice') }}">Continue with current account</a>
            <form method="POST" action="{{ route('logout') }}" class="auth-form">
                @csrf
                <button type="submit" class="auth-button auth-button-blue">Log out to switch accounts</button>
            </form>
        </div>
    </section>
</x-layouts.auth>
