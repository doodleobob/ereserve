<x-layouts.user title="Admin Management - eReserve" active="admins">
    <section class="page-heading profile-heading">
        <h2>Admin Management</h2>
        <p>Create barangay admin accounts</p>
        <p><a href="{{ route('admins.index') }}">Back to Admin Management</a></p>
    </section>

    <section class="profile-grid">
        <div class="profile-main-column">
            <article class="profile-card">
                <h3>Create Admin</h3>

                @if (session('admin_status'))
                    <div class="reservation-alert" role="status">{{ session('admin_status') }}</div>
                @endif

                <form method="POST" action="{{ route('admins.store') }}" class="profile-form">
                    @csrf

                    @include('admins.form-fields')

                    <div class="profile-actions">
                        <button type="submit" class="profile-primary-button">Create Admin</button>
                    </div>
                </form>
            </article>
        </div>

        <aside class="profile-card account-card">
            <h3>Current Admins</h3>

            <dl class="account-info-list">
                @forelse ($admins as $admin)
                    <div>
                        <dt>{{ $admin->name }}</dt>
                        <dd>{{ $admin->barangay }}</dd>
                    </div>
                @empty
                    <div>
                        <dt>No admins yet</dt>
                        <dd>Create the first barangay admin account.</dd>
                    </div>
                @endforelse
            </dl>
        </aside>
    </section>
</x-layouts.user>
