<x-modal id="payment-view-{{ $payment->id }}" title="Payment Details" size="medium">
    <div class="facility-modal-body">@include('payments.details', ['showStatus'=>true])</div>
    <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Close</button></div>
</x-modal>
<x-modal id="payment-edit-{{ $payment->id }}" title="Edit Payment" size="medium">
    <form method="POST" action="{{ route('payments.update', $payment) }}" data-reservation-action>@csrf @method('PATCH')
        <div class="facility-modal-body">@include('payments.details', ['showStatus'=>false])
            <label>Payment Status<select name="payment_status" required>@foreach($payment->allowedStatuses() as $status)<option value="{{ $status }}" @selected($payment->payment_status===$status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
            @if(count($payment->allowedStatuses())>1)
                <label class="payment-receipt"><input name="receipt_confirmed" type="checkbox" value="1"> I confirm any payment receipt or refund selected above has actually been processed.</label>
            @else<p>This payment is closed. Its status is retained for financial history.</p>@endif
            <p data-action-error role="alert" hidden></p>
        </div>
        <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Cancel</button><button type="submit" class="facility-modal-primary">Save Changes</button></div>
    </form>
</x-modal>
