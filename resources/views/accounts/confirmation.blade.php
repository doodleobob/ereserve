@if ($canManageAccounts)
<x-modal id="account-confirmation" title="Confirm Account Status" size="small">
    <form method="POST" data-modal-action>
        @csrf @method('PATCH')
        <input type="hidden" name="is_active">
        <div class="facility-modal-body">
            <p data-account-confirm-description></p>
            <p>{{ $managingAdmins ? 'Existing barangay records will remain available.' : 'Reservation and payment history will remain available.' }}</p>
            <p data-action-error role="alert" hidden></p>
        </div>
        <div class="facility-modal-actions">
            <button type="button" class="facility-modal-secondary" data-modal-close autofocus>Cancel</button>
            <button type="submit" class="facility-modal-primary" data-account-confirm>Confirm</button>
        </div>
    </form>
</x-modal>
<noscript><p class="reservation-alert">Enable JavaScript to review and confirm account status changes.</p></noscript>
@endif
@push('scripts')
    <script src="{{ asset('js/account-management.js') }}?v={{ filemtime(public_path('js/account-management.js')) }}" defer></script>
@endpush
