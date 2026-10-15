@props(['status', 'type' => 'reservation', 'label' => null])
<span {{ $attributes->class(['reservation-status', $type.'-status-'.$status]) }}>{{ $label ?? ucwords(str_replace('_', ' ', $status)) }}</span>
