<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit" class="logout-button">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
            <path d="M10 17l5-5-5-5" />
            <path d="M15 12H3" />
        </svg>
        Logout
    </button>
</form>
