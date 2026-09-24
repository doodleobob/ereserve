<x-layouts.auth title="Email Verified - eReserve">
    <section class="auth-card auth-card-login verification-card" data-email-verification
        data-verified="true" data-destination="{{ $destination }}">
        <div data-verification-success role="status" aria-live="polite" tabindex="-1">
            @include('auth.partials.verification-success')
        </div>
    </section>
    <script src="{{ asset('js/email-verification.js') }}" defer></script>
</x-layouts.auth>
