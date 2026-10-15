@props(['label', 'compact' => false])
<div {{ $attributes->class(['management-table-scroll', 'reservation-datatable-scroll']) }} tabindex="0" aria-label="{{ $label }}; scroll horizontally on small screens">
    <table @class(['management-table', 'reservation-datatable', 'management-table-compact' => $compact]) aria-label="{{ $label }}">
        {{ $slot }}
    </table>
</div>
