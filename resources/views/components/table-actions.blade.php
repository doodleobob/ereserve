@props(['id', 'label'])
<details class="reservation-table-actions" data-table-actions>
    <summary aria-label="{{ $label }}" aria-expanded="false" aria-controls="{{ $id }}">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
    </summary>
    <div class="reservation-table-menu" id="{{ $id }}">{{ $slot }}</div>
</details>
