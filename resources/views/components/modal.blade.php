@props(['id', 'title', 'size' => 'medium'])
<dialog id="{{ $id }}" {{ $attributes->class(['facility-modal-panel', 'ereserve-modal', 'reservation-table-dialog']) }} data-modal-size="{{ $size }}" aria-labelledby="{{ $id }}-title">
    <div class="facility-modal-header">
        <h3 id="{{ $id }}-title">{{ $title }}</h3>
        <button type="button" class="facility-modal-close" data-modal-close aria-label="Close">&times;</button>
    </div>
    {{ $slot }}
</dialog>
