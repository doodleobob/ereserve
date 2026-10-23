@if (array_key_exists('quantity', $event))
    <small>
        @if($event['whole_resource'])
            Whole-resource hold (legacy booking)
        @else
            {{ $event['quantity'] }} of {{ $event['total_quantity'] }} units booked
        @endif
    </small>
    @if(isset($event['effective_end']))<small>60-minute buffer · Allocation ends {{ $event['effective_end'] }}</small>@endif
@endif
