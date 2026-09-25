<div class="notification-center" data-notifications data-url="{{ route('notifications.index') }}" data-read-all="{{ route('notifications.read-all') }}" data-admin="{{ $user->role === 'admin' ? 'true' : 'false' }}" data-user="{{ $user->id }}">
    <button type="button" class="logout-button notification-bell" aria-label="Notifications" aria-expanded="false" aria-controls="notification-panel">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>
        <span class="notification-badge" hidden></span>
    </button>
    <section id="notification-panel" class="notification-panel" aria-label="Notifications" hidden>
        <div class="notification-panel-heading"><strong>Notifications</strong><button type="button" data-read-all>Mark all as read</button></div>
        <div data-notification-list><p>Loading notifications…</p></div>
        <button type="button" data-notification-more hidden>Load more</button>
        <p data-notification-error role="status" hidden>Notifications could not be loaded. Please try again.</p>
    </section>
    <div class="notification-toasts" aria-live="polite" aria-label="New reservation notifications"></div>
</div>
