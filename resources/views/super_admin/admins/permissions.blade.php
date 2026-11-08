<x-layouts.user title="Admin Permissions - eReserve" active="admins">
    <section class="page-heading profile-heading">
        <h2>Admin Permissions</h2>
        <p><a href="{{ route('admins.show', $admin) }}">Back to Admin Details</a></p>
    </section>

    @if (session('permission_status'))<p class="reservation-alert" role="status">{{ session('permission_status') }}</p>@endif

    <section class="profile-grid">
        <div class="profile-main-column">
            <article class="profile-card">
                <h3>Barangay Admin Capabilities</h3>
                <p>Capabilities are enabled by default for Barangay Admins. Check Restrict to disable a capability for this account. Unchecking it restores the normal default.</p>

                <dl class="account-info-list">
                    <div><dt>Administrator</dt><dd>{{ $admin->name }}@if($admin->username) ({{ $admin->username }})@endif</dd></div>
                    <div><dt>Assigned Barangay</dt><dd>{{ $admin->barangay }}</dd></div>
                </dl>

                <div class="profile-form" aria-label="Individual Barangay Admin restrictions">
                    @foreach ($capabilities as $capability)
                        <div class="profile-group">
                            <strong>{{ $capability->label() }}</strong>
                            <p>{{ $capability->description() }}</p>
                            <label for="permission-{{ $loop->index }}-restricted">
                                <input id="permission-{{ $loop->index }}-restricted" type="checkbox" name="restricted_permissions[]"
                                    value="{{ $capability->value }}" form="admin-permission-update-form"
                                    @checked(in_array($capability->value, $restricted, true))>
                                Restrict this capability
                            </label>
                            <small>{{ in_array($capability->value, $restricted, true) ? 'Currently restricted' : 'Currently enabled by default' }}</small>
                        </div>
                    @endforeach
                </div>

                <div class="profile-actions">
                    <a class="button button-secondary" href="{{ route('admins.show', $admin) }}">Cancel</a>
                    <button type="button" class="profile-primary-button" data-modal-open="admin-permission-confirmation">Review Changes</button>
                </div>
                <noscript><p class="reservation-alert">Enable JavaScript to review and confirm permission changes.</p></noscript>
            </article>
        </div>

        <aside class="profile-card account-card">
            <h3>Super Admin Access</h3>
            <p>Super Admin permissions are always unrestricted and cannot be edited here.</p>
            <h3>Restriction Only</h3>
            <p>This editor only restricts capabilities already enforced by backend authorization. It cannot grant new capabilities.</p>
            <p>Every listed capability is enforced by backend authorization. Restrictions can remove a Barangay Admin capability but cannot grant permissions beyond the normal role.</p>
        </aside>
    </section>

    <x-modal id="admin-permission-confirmation" title="Confirm Permission Changes" size="small">
        <form id="admin-permission-update-form" method="POST" action="{{ route('admins.permissions.update', $admin) }}" data-modal-action>
            @csrf
            @method('PATCH')
            <div class="facility-modal-body">
                <p>Save the selected restrictions for {{ $admin->name }}? Unchecked capabilities return to the Barangay Admin default.</p>
                <p data-action-error role="alert" hidden></p>
            </div>
            <div class="facility-modal-actions">
                <button type="button" class="facility-modal-secondary" data-modal-close autofocus>Cancel</button>
                <button type="submit" class="facility-modal-primary">Confirm Changes</button>
            </div>
        </form>
    </x-modal>
</x-layouts.user>
