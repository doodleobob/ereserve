<?php

namespace App\Support;

use App\Models\EquipmentCharge;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Authorization;
use App\Support\AdminPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CentralPaymentQuery
{
    public static function validate(Request $request): void
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::in(['all', 'reservation', 'equipment_charge'])],
            'status' => ['nullable', Rule::in(['all', ...Payment::STATUSES, ...array_keys(EquipmentCharge::STATUSES)])],
            'barangay' => ['nullable', Rule::in(Barangays::ALL)],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from_date') ? ['after_or_equal:from_date'] : [])],
            'sort' => ['nullable', Rule::in(['id', 'reservation', 'type', 'barangay', 'resident', 'resource', 'date', 'total', 'status'])],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    public static function forRequest(Request $request)
    {
        $user = $request->user();
        $canRecordChargePayments = app(Authorization::class)->allows($user, AdminPermission::EQUIPMENT_CHARGES_MANAGE);

        return self::buildQuery($request, $canRecordChargePayments);
    }

    private static function buildQuery(Request $request, bool $canRecordChargePayments)
    {
        $user = $request->user();
        $reservation = DB::table('payments')
            ->join('reservations', 'payments.reservation_id', '=', 'reservations.id')
            ->leftJoin('users', 'reservations.user_id', '=', 'users.id')
            ->leftJoin('facilities', 'reservations.facility_id', '=', 'facilities.id')
            ->selectRaw("'reservation' as payment_type, payments.id as row_id, payments.id as payment_id, reservations.id as reservation_id, NULL as charge_id, users.name as resident_name, COALESCE(facilities.name, reservations.facility_name) as resource_name, COALESCE(facilities.barangay, reservations.barangay) as barangay, reservations.reservation_date as scheduled_date, payments.created_at as payment_date, reservations.total_payment as total_amount, CASE WHEN payments.payment_status IN ('paid','refund_pending','refunded') THEN reservations.total_payment ELSE 0 END as paid_amount, NULL as balance_amount, payments.payment_status as status")
            ->where(function ($query) use ($user) {
                if ($user->role === 'admin' && app(Authorization::class)->allows($user, AdminPermission::PAYMENTS_VIEW)) {
                    $query->whereRaw('COALESCE(facilities.barangay, reservations.barangay) = ?', [$user->barangay]);
                } elseif ($user->role === 'admin') {
                    $query->whereRaw('1 = 0');
                } elseif ($user->role === 'user') {
                    $query->where('reservations.user_id', $user->id);
                }
            });
        if ($user->role === 'super_admin' && $request->filled('barangay')) {
            $reservation->whereRaw('COALESCE(facilities.barangay, reservations.barangay) = ?', [$request->query('barangay')]);
        }

        $collected = DB::table('equipment_charge_payments')
            ->select('equipment_charge_id')
            ->selectRaw('SUM(ROUND(amount * 100)) as paid_cents, MAX(payment_date) as last_payment_date')
            ->groupBy('equipment_charge_id');
        $charge = DB::table('equipment_charges as charges')
            ->join('resource_usages as usages', 'charges.resource_usage_id', '=', 'usages.id')
            ->join('reservations', 'usages.reservation_id', '=', 'reservations.id')
            ->join('facilities', 'usages.facility_id', '=', 'facilities.id')
            ->leftJoin('users', 'reservations.user_id', '=', 'users.id')
            ->leftJoinSub($collected, 'collected', fn ($join) => $join->on('collected.equipment_charge_id', '=', 'charges.id'))
            ->selectRaw("'equipment_charge' as payment_type, charges.id as row_id, NULL as payment_id, reservations.id as reservation_id, charges.id as charge_id, users.name as resident_name, facilities.name as resource_name, facilities.barangay as barangay, reservations.reservation_date as scheduled_date, COALESCE(collected.last_payment_date, DATE(charges.created_at)) as payment_date, charges.amount as total_amount, COALESCE(collected.paid_cents,0) / 100.0 as paid_amount, charges.amount - COALESCE(collected.paid_cents,0) / 100.0 as balance_amount, charges.status as status")
            ->where('usages.resource_type', 'Equipment')
            ->where('facilities.category', 'Equipment')
            ->whereColumn('reservations.facility_id', 'usages.facility_id')
            ->whereColumn('usages.barangay', 'facilities.barangay');
        if ($user->role === 'admin') {
            $charge->where('facilities.barangay', $user->barangay);
            if (! $canRecordChargePayments) {
                $charge->whereRaw('1 = 0');
            }
        } elseif ($user->role === 'user') {
            $charge->where('reservations.user_id', $user->id);
        }
        if ($user->role === 'super_admin' && $request->filled('barangay')) {
            $charge->where('facilities.barangay', $request->query('barangay'));
        }

        $type = $request->query('type', 'all');
        $query = DB::query()->fromSub($reservation->unionAll($charge), 'payment_rows');
        if ($type !== 'all') {
            $query->where('payment_type', $type);
        }
        $status = $request->query('status', 'all');
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($request->filled('from_date')) {
            $query->where('payment_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->where('payment_date', '<', Carbon::parse($request->query('to_date'))->addDay()->toDateString());
        }
        if ($user->role === 'super_admin' && $request->filled('barangay')) {
            $query->where('barangay', $request->query('barangay'));
        }
        $term = trim((string) $request->query('search', ''));
        if ($term !== '') {
            $query->where(function ($query) use ($term) {
                $query->where('resident_name', 'like', '%'.$term.'%')->orWhere('resource_name', 'like', '%'.$term.'%');
                if (preg_match('/^(?:(PAY|RES|CHG)-)?#?0*(\d+)$/i', $term, $matches)) {
                    $id = (int) $matches[2];
                    $prefix = strtoupper($matches[1] ?? '');
                    if (! in_array($prefix, ['RES', 'CHG'], true)) {
                        $query->orWhere(fn ($q) => $q->where('payment_type', 'reservation')->where('payment_id', $id));
                    }
                    if (! in_array($prefix, ['PAY', 'CHG'], true)) {
                        $query->orWhere(fn ($q) => $q->where('payment_type', 'reservation')->where('reservation_id', $id));
                    }
                    if (! in_array($prefix, ['PAY', 'RES'], true)) {
                        $query->orWhere(fn ($q) => $q->where('payment_type', 'equipment_charge')->where('charge_id', $id));
                    }
                }
            });
        }

        $sort = $request->query('sort', 'id');
        $column = ['id' => 'row_id', 'reservation' => 'reservation_id', 'type' => 'payment_type', 'barangay' => 'barangay',
            'resident' => 'resident_name', 'resource' => 'resource_name', 'date' => 'payment_date', 'total' => 'total_amount', 'status' => 'status'][$sort];
        $direction = $request->query('direction', 'desc');

        return $query->orderBy($column, $direction)->orderBy('payment_type')->orderBy('row_id', $direction);
    }

    public static function page(Request $request)
    {
        $canRecordChargePayments = app(Authorization::class)->allows($request->user(), AdminPermission::EQUIPMENT_CHARGES_MANAGE);
        $query = self::buildQuery($request, $canRecordChargePayments);
        $summaryQuery = clone $query;
        $summaryRow = (clone $summaryQuery)->reorder()->selectRaw('COUNT(*) as records, COALESCE(SUM(total_amount),0) as total_amount, SUM(CASE WHEN payment_type = ? AND total_amount IS NULL THEN 1 ELSE 0 END) as unrecorded', ['reservation'])->first();
        $perPage = $request->filled('per_page') ? $request->integer('per_page') : 10;
        $payments = $query->paginate($perPage)->withQueryString();
        if ($payments->isEmpty() && $payments->currentPage() > $payments->lastPage()) {
            $payments = $query->paginate($perPage, ['*'], 'page', $payments->lastPage())->withQueryString();
        }

        $rows = $payments->getCollection();
        $reservationIds = $rows->where('payment_type', 'reservation')->pluck('payment_id')->filter()->unique();
        $chargeIds = $rows->where('payment_type', 'equipment_charge')->pluck('charge_id')->filter()->unique();
        $reservationPayments = Payment::with('reservation.user', 'reservation.facility')->whereIn('id', $reservationIds)->get()->keyBy('id');
        $charges = EquipmentCharge::with(['usage.reservation.user', 'usage.facility', 'payments.recorder'])->whereIn('id', $chargeIds)->get()->keyBy('id');

        $rows->transform(function (object $row) use ($reservationPayments, $charges) {
            $row->id = (int) $row->row_id;
            $row->payment_date = $row->payment_date ? Carbon::parse($row->payment_date) : null;
            if ($row->payment_type === 'reservation') {
                $row->payment = $reservationPayments->get($row->payment_id);
                $row->reservation = $row->payment?->reservation;
            } else {
                $row->charge = $charges->get($row->charge_id);
                $row->reservation = $row->charge?->usage?->reservation;
            }

            return $row;
        });

        return ['payments' => $payments, 'summary' => [
            'count' => (int) $summaryRow->records,
            'total' => Money::decimal((int) round((float) $summaryRow->total_amount * 100)),
            'unrecorded' => (int) $summaryRow->unrecorded,
        ], 'canRecordChargePayments' => $canRecordChargePayments];
    }
}
