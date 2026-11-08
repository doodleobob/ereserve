<x-layouts.user title="Audit Entry - eReserve" active="audit-logs">
    <section class="page-heading profile-heading"><h2>Audit Entry</h2><p><a href="{{ route('super-admin.audit-logs.index') }}">Back to Audit Log</a></p></section>
    <section class="content-card charge-details-card">
        <dl>
            <dt>Date and time</dt><dd>{{ $auditLog->occurred_at->timezone(config('app.timezone'))->format('M j, Y g:i:s A') }}</dd>
            <dt>Administrator</dt><dd>{{ $auditLog->actor_name }}{{ $auditLog->actor_id ? '' : ' (deleted account)' }} ({{ $auditLog->actor_role === 'super_admin' ? 'Super Admin' : 'Barangay Admin' }})</dd>
            <dt>Barangay</dt><dd>{{ $auditLog->barangay ?? 'System-wide' }}</dd>
            <dt>Action</dt><dd>{{ $auditLog->description }}</dd>
            <dt>Record</dt><dd>{{ $auditLog->target_type ?? '—' }}{{ $auditLog->target_id ? ' #'.$auditLog->target_id : '' }}</dd>
            @if($auditLog->reason)<dt>Reason</dt><dd>{{ $auditLog->reason }}</dd>@endif
        </dl>
        @foreach(['before_values' => 'Before', 'after_values' => 'After'] as $field => $label)
            @if($auditLog->{$field})<h3>{{ $label }}</h3><pre>{{ json_encode($auditLog->{$field}, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>@endif
        @endforeach
    </section>
</x-layouts.user>
