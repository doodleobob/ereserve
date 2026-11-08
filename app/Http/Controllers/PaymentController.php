<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Authorization;
use App\Support\AdminPermission;
use App\Support\CentralPaymentQuery;
use App\Support\CentralPaymentReport;
use App\Support\PaymentQuery;
use App\Support\PaymentRecords;
use App\Support\PaymentReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin', 'user'], true), 403);
        $canManageReservationPayments = app(Authorization::class)->allows($request->user(), AdminPermission::PAYMENTS_MANAGE);
        CentralPaymentQuery::validate($request);
        ['payments' => $payments, 'summary' => $summary, 'canRecordChargePayments' => $canRecordChargePayments] = CentralPaymentQuery::page($request);

        $canConfirmRefunds = $canManageReservationPayments;

        return view('shared.payments.index', compact('payments', 'summary', 'canConfirmRefunds', 'canRecordChargePayments', 'canManageReservationPayments'));
    }

    public function update(Request $request, Payment $payment): JsonResponse
    {
        Gate::authorize('update', $payment);
        // Authorize the actual payment and reservation before validating the
        // requested transition, preventing unauthorized state probing.
        $data = $request->validate(['payment_status' => ['required', Rule::in(Payment::STATUSES)], 'receipt_confirmed' => ['nullable', 'boolean']]);
        DB::transaction(function () use ($request, $payment, $data) {
            // Acceptance locks Reservation first; use that order without saving it.
            Reservation::lockForUpdate()->findOrFail($payment->reservation_id);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            Gate::authorize('update', $payment);
            if ($payment->payment_status !== $data['payment_status'] && in_array($data['payment_status'], ['paid', 'refunded'], true)) {
                $request->validate(['receipt_confirmed' => ['required', 'accepted']], [
                    'receipt_confirmed.required' => 'Confirm that the payment or refund has actually been processed.',
                    'receipt_confirmed.accepted' => 'Confirm that the payment or refund has actually been processed.',
                ]);
            }
            PaymentRecords::changeStatusByAdministrator($payment, $data['payment_status'], $request->user());
        }, 3);

        return response()->json(['success' => true, 'message' => 'Payment updated successfully.']);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(app(Authorization::class)->allows($request->user(), AdminPermission::PAYMENTS_VIEW), 403);
        PaymentQuery::validate($request);
        $data = $request->validate(['format' => ['required', Rule::in(['pdf', 'xlsx', 'csv'])]]);

        return PaymentReport::download($request, $data['format']);
    }

    public function exportCentralized(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);
        CentralPaymentQuery::validate($request);
        $data = $request->validate(['format' => ['required', Rule::in(['pdf', 'xlsx'])]]);

        return CentralPaymentReport::download($request, $data['format']);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);
    }

}
