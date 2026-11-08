<?php

namespace App\Http\Controllers;

use App\Models\EquipmentCharge;
use App\Services\EquipmentChargePayments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EquipmentChargePaymentController extends Controller
{
    public function store(Request $request, EquipmentCharge $charge, EquipmentChargePayments $payments)
    {
        Gate::authorize('recordPayment', $charge);
        $payments->record($request->user(), $charge, $request->all());

        return redirect()->route('payments.index')->with('charge_payment_status', 'Equipment charge payment recorded.');
    }
}
