<x-modal id="payment-view-{{ $payment->id }}" title="Payment Details" size="medium">
    <div class="facility-modal-body">@include('payments.details', ['showStatus'=>true])</div>
    <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Close</button></div>
</x-modal>
@if($canConfirmRefunds && $payment->payment_status === 'refund_pending')
<x-modal id="payment-refund-{{ $payment->id }}" title="Confirm Refund" size="medium">
    <form method="POST" action="{{ route('payments.update', $payment) }}" data-reservation-action>@csrf @method('PATCH')
        <input type="hidden" name="payment_status" value="refunded">
        <div class="facility-modal-body">@include('payments.details', ['showStatus'=>true])
            <label class="payment-receipt"><input name="receipt_confirmed" type="checkbox" value="1" required> I confirm that the payment has actually been returned to the resident.</label>
            <p data-action-error role="alert" hidden></p>
        </div>
        <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Cancel</button><button type="submit" class="facility-modal-primary">Confirm Refund</button></div>
    </form>
</x-modal>
@endif
