<x-layouts.auth title="Please Wait - eReserve">
    <section class="auth-card auth-card-login">
        <div class="auth-header">
            <h1>Too Many Attempts</h1>
            <p>Please wait a moment before trying again.</p>
        </div>

        <div class="reservation-alert reservation-alert-error" role="alert">
            Your request is temporarily limited. Please wait before trying again.
        </div>

        <p class="auth-switch"><a href="{{ route('login') }}">Return to Login</a></p>
    </section>
</x-layouts.auth>
