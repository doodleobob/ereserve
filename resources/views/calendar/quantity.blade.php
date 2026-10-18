@if (array_key_exists('quantity', $event))
    <small>
        @if($event['whole_resource'])
            Whole-resource hold (legacy booking)
        @else
            {{ $event['quantity'] }} of {{ $event['total_quantity'] }} units booked
        @endif
    </small>
@endif
