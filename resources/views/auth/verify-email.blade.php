<x-layouts.auth title="Verify Email - eReserve">
    <section class="auth-card auth-card-login verification-card" data-email-verification
        data-status-url="{{ route('verification.status') }}" data-csrf-token="{{ csrf_token() }}"
        data-destination="{{ $destination }}">
        <div data-verification-waiting>
            <div class="auth-header">
                <h1>Verify your email</h1>
                <p>Open the verification link in your email to continue to eReserve.</p>
            </div>

            <p class="auth-switch">{{ auth()->user()->email }}</p>
            <p class="auth-switch">Check your inbox and spam folder. If you need another link, request one below.</p>

            @if (session('status') === 'verification-link-sent')
                <p class="auth-switch" role="status">A new verification link has been sent to your email address.</p>
            @endif

            @error('verification')
                <p class="form-error" role="alert">{{ $message }}</p>
            @enderror

            <div class="auth-form auth-switch">
                <form method="POST" action="{{ route('verification.send') }}" class="auth-form">
                    @csrf
                    <button type="submit" class="auth-button auth-button-blue">Resend verification email</button>
                </form>

                <form method="POST" action="{{ route('logout') }}" class="auth-form">
                    @csrf
                    <button type="submit" class="auth-button auth-button-blue">Log out</button>
                </form>
            </div>
        </div>
        <div data-verification-success hidden role="status" aria-live="polite" tabindex="-1">
            @include('auth.partials.verification-success', ['alreadyVerified' => false])
        </div>
    </section>
    <script src="{{ asset('js/email-verification.js') }}" defer></script>
</x-layouts.auth>
