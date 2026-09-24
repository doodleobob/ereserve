<div class="auth-header">
    <div class="auth-icon verification-check" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6" /></svg>
    </div>
    <h1>{{ $alreadyVerified ? 'Your email is already verified.' : 'Email verified successfully!' }}</h1>
    <p>Your email address has been verified.</p>
</div>
<p class="verification-progress">
    <span class="verification-spinner" aria-hidden="true"></span>
    Redirecting you to eReserve...
</p>
<a class="auth-button auth-button-blue verification-continue" href="{{ $destination }}">Continue to eReserve</a>
