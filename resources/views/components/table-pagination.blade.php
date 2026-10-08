@props(['paginator', 'label'])
<nav class="reservation-table-pagination" aria-label="{{ $label }}">
    @if($paginator->previousPageUrl())<a data-table-link href="{{ $paginator->previousPageUrl() }}">Previous</a>@else<span aria-disabled="true">Previous</span>@endif
    @foreach($paginator->getUrlRange(max(1,$paginator->currentPage()-2),min($paginator->lastPage(),$paginator->currentPage()+2)) as $page=>$url)
        <a data-table-link href="{{ $url }}" @if($page===$paginator->currentPage()) aria-current="page" @endif>{{ $page }}</a>
    @endforeach
    @if($paginator->nextPageUrl())<a data-table-link href="{{ $paginator->nextPageUrl() }}">Next</a>@else<span aria-disabled="true">Next</span>@endif
</nav>
