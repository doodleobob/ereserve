<x-modal id="payment-view-{{ $payment->id }}" title="Payment Details" size="medium">
    <div class="facility-modal-body">@include('shared.payments.partials.details', ['showStatus'=>true])</div>
    <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Close</button></div>
</x-modal>
@if($canManageReservationPayments && $payment->payment_status === 'pending')
<x-modal id="payment-record-{{ $payment->id }}" title="Record Reservation Payment" size="medium">
    <form method="POST" action="{{ route('payments.update', $payment) }}" data-reservation-action>@csrf @method('PATCH')
        <input type="hidden" name="payment_status" value="paid">
        <div class="facility-modal-body">
            @include('shared.payments.partials.details', ['showStatus'=>true])
            <label class="payment-receipt"><input name="receipt_confirmed" type="checkbox" value="1" required> I confirm that this payment has actually been received.</label>
            <p data-action-error role="alert" hidden></p>
        </div>
        <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Cancel</button><button type="submit" class="facility-modal-primary">Record Payment</button></div>
    </form>
</x-modal>
@endif
@if($canConfirmRefunds && $payment->payment_status === 'refund_pending')
<x-modal id="payment-refund-{{ $payment->id }}" title="Confirm Refund" size="medium">
    <form method="POST" action="{{ route('payments.update', $payment) }}" data-reservation-action>@csrf @method('PATCH')
        <input type="hidden" name="payment_status" value="refunded">
        <div class="facility-modal-body">@include('shared.payments.partials.details', ['showStatus'=>true])
            <label class="payment-receipt"><input name="receipt_confirmed" type="checkbox" value="1" required> I confirm that the payment has actually been returned to the resident.</label>
            <p data-action-error role="alert" hidden></p>
        </div>
        <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Cancel</button><button type="submit" class="facility-modal-primary">Confirm Refund</button></div>
    </form>
</x-modal>
@endif
@if($canManageReservationPayments)
<x-modal id="payment-correct-{{ $payment->id }}" title="Correct Reservation Total" size="medium">
    <form method="POST" action="{{ route('reservations.payment', $payment->reservation) }}" data-reservation-action>@csrf @method('PATCH')
        <div class="facility-modal-body">
            @include('shared.payments.partials.details', ['showStatus'=>true])
            <div class="modal-form-group"><label for="payment-correct-total-{{ $payment->id }}">Correct Total (&#8369;)</label><input id="payment-correct-total-{{ $payment->id }}" type="number" name="total_payment" min="0" max="9999999999.99" step="0.01" value="{{ $payment->reservation->total_payment }}" required></div>
            <div class="modal-form-group"><label for="payment-correct-reason-{{ $payment->id }}">Reason for correction</label><textarea id="payment-correct-reason-{{ $payment->id }}" name="reason" maxlength="1000" rows="3" required></textarea></div>
            <p data-action-error role="alert" hidden></p>
        </div>
        <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Cancel</button><button type="submit" class="facility-modal-primary">Save Correction</button></div>
    </form>
</x-modal>
@endif
