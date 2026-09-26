<dialog id="account-confirmation" class="facility-modal-panel payment-confirmation" aria-labelledby="account-confirmation-title" aria-describedby="account-confirmation-description">
    <div class="facility-modal-header"><h3 id="account-confirmation-title">Deactivate this {{ $managingAdmins ? 'admin' : 'resident' }}?</h3></div>
    <div class="facility-modal-body"><p id="account-confirmation-description">{{ $managingAdmins ? 'This admin will no longer be able to access the Barangay Admin dashboard. Existing barangay records will remain unchanged.' : 'This resident will no longer be able to log in. Their reservation and payment history will remain available.' }}</p></div>
    <div class="facility-modal-actions">
        <button type="button" class="facility-modal-secondary" data-account-cancel autofocus>Cancel</button>
        <button type="button" class="facility-modal-primary" data-account-confirm>Deactivate</button>
    </div>
</dialog>
<noscript><p class="reservation-alert">Enable JavaScript to review and confirm account deactivation.</p></noscript>
<script src="{{ asset('js/account-management.js') }}?v={{ filemtime(public_path('js/account-management.js')) }}" defer></script>
