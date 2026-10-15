@props(['paginator', 'form', 'id', 'noun', 'ajax' => true])
<div class="reservation-table-toolbar">
    <label for="{{ $id }}">Show <select id="{{ $id }}" name="per_page" form="{{ $form }}" @if($ajax) data-table-filter @else data-native-table-filter @endif>@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($paginator->perPage() === $size)>{{ $size }}</option>@endforeach</select> entries</label>
    <p role="status">Showing {{ $paginator->firstItem() ?? 0 }} to {{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }} {{ $noun }}</p>
</div>
