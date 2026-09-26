@if ($paginator->hasPages())
    <nav class="profile-actions" aria-label="Pagination">
        @if ($paginator->previousPageUrl())<a class="profile-secondary-button" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>@endif
        <span>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @if ($paginator->nextPageUrl())<a class="profile-secondary-button" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>@endif
    </nav>
@endif
