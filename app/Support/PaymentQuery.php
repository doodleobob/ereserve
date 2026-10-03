<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PaymentQuery
{
    public static function validate(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['all', ...Payment::STATUSES])],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from_date') ? ['after_or_equal:from_date'] : [])],
            'sort' => ['nullable', Rule::in(['id', 'reservation', 'resident', 'resource', 'date', 'total', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    public static function forRequest(Request $request): Builder
    {
        $query = Payment::query()->inBarangayFor($request->user())
            ->join('reservations', 'payments.reservation_id', '=', 'reservations.id')
            ->leftJoin('users', 'reservations.user_id', '=', 'users.id')
            ->leftJoin('facilities', 'reservations.facility_id', '=', 'facilities.id')
            ->select('payments.*')->with(['reservation.user', 'reservation.facility']);
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search) {
                $query->where('users.name', 'like', '%'.$search.'%')
                    ->orWhereRaw('COALESCE(facilities.name, reservations.facility_name) LIKE ?', ['%'.$search.'%']);
                if (preg_match('/^(?:(PAY|RES)-)?#?0*(\d+)$/i', $search, $matches)) {
                    $id = (int) $matches[2];
                    if (strtoupper($matches[1] ?? '') !== 'RES') {
                        $query->orWhere('payments.id', $id);
                    }
                    if (strtoupper($matches[1] ?? '') !== 'PAY') {
                        $query->orWhere('reservations.id', $id);
                    }
                }
            });
        }
        $query->when(in_array($request->query('status'), Payment::STATUSES, true), fn ($q) => $q->where('payments.payment_status', $request->query('status')))
            ->when($request->filled('from_date'), fn ($q) => $q->where('payments.created_at', '>=', $request->query('from_date').' 00:00:00'))
            ->when($request->filled('to_date'), fn ($q) => $q->where('payments.created_at', '<', Carbon::parse($request->query('to_date'))->addDay()->startOfDay()));
        $column = [
            'id' => 'payments.id', 'reservation' => 'reservations.id', 'resident' => 'users.name',
            'resource' => 'resource', 'date' => 'reservations.reservation_date', 'total' => 'reservations.total_payment', 'status' => 'payments.payment_status',
        ][$request->query('sort') ?: 'id'];
        $direction = $request->query('direction') ?: 'desc';
        if ($column === 'resource') {
            $query->orderByRaw('COALESCE(facilities.name, reservations.facility_name) '.$direction);
        } else {
            $query->orderBy($column, $direction);
        }

        return $query->orderBy('payments.id', $direction);
    }

    public static function summary(Builder $query): array
    {
        $result = (clone $query)->reorder()->toBase()->select([])->selectRaw('COUNT(*) as records, COALESCE(SUM(ROUND(reservations.total_payment * 100)), 0) as total_cents, COUNT(*) - COUNT(reservations.total_payment) as unrecorded')->first();

        return ['count' => (int) $result->records, 'total' => Money::decimal((int) $result->total_cents), 'unrecorded' => (int) $result->unrecorded];
    }
}
