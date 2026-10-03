<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Reservation;
use App\Support\PaymentQuery;
use App\Support\PaymentRecords;
use App\Support\PaymentReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);
        PaymentQuery::validate($request);
        $query = PaymentQuery::forRequest($request);
        $summary = PaymentQuery::summary($query);
        $perPage = $request->filled('per_page') ? $request->integer('per_page') : 10;
        $payments = $query->paginate($perPage)->withQueryString();
        if ($payments->isEmpty() && $payments->currentPage() > $payments->lastPage()) {
            $payments = $query->paginate($perPage, ['*'], 'page', $payments->lastPage())->withQueryString();
        }

        return view('payments.index', compact('payments', 'summary'));
    }

    public function update(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizePayment($request, $payment);
        $data = $request->validate(['payment_status' => ['required', Rule::in(Payment::STATUSES)], 'receipt_confirmed' => ['nullable', 'boolean']]);
        DB::transaction(function () use ($request, $payment, $data) {
            // Acceptance locks Reservation first; use that order without saving it.
            Reservation::lockForUpdate()->findOrFail($payment->reservation_id);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $this->authorizePayment($request, $payment);
            if ($payment->payment_status !== $data['payment_status'] && in_array($data['payment_status'], ['paid', 'refunded'], true)) {
                $request->validate(['receipt_confirmed' => ['required', 'accepted']], [
                    'receipt_confirmed.required' => 'Confirm that the payment or refund has actually been processed.',
                    'receipt_confirmed.accepted' => 'Confirm that the payment or refund has actually been processed.',
                ]);
            }
            PaymentRecords::changeStatus($payment, $data['payment_status'], $request->user());
        }, 3);

        return response()->json(['success' => true, 'message' => 'Payment updated successfully.']);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);
        PaymentQuery::validate($request);
        $data = $request->validate(['format' => ['required', Rule::in(['pdf', 'xlsx', 'csv'])]]);

        return PaymentReport::download($request, $data['format']);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);
    }

    private function authorizePayment(Request $request, Payment $payment): void
    {
        $this->authorizeAdmin($request);
        abort_unless(Payment::inBarangayFor($request->user())->whereKey($payment->id)->exists(), 403);
    }
}
