<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\OfficialUseConflict;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Queries\OfficialUses\OfficialUseQuery;
use App\Support\OfficialUseScheduling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OfficialUseController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);
        $request->validate([
            'search' => ['nullable', 'string', 'max:200'], 'facility_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:all,active,conflict'],
            'from_date' => ['nullable', 'date_format:Y-m-d'], 'to_date' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'], 'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'in:id,resource,schedule,purpose,status'], 'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $query = OfficialUseQuery::forRequest($request);
        $uses = $query->paginate($request->integer('per_page', 10))->withQueryString();
        $resources = Facility::query()->when($request->user()->role !== 'super_admin', fn ($q) => $q->where('barangay', $request->user()->barangay))->orderBy('name')->get();

        return view('official-uses.index', compact('uses', 'resources'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validatedSchedule($request);
        $use = DB::transaction(function () use ($request, $data) {
            $facility = Facility::lockForUpdate()->findOrFail($data['facility_id']);
            abort_unless($request->user()->role === 'super_admin' || $facility->barangay === $request->user()->barangay, 403);

            return OfficialUseScheduling::create($facility, $data, $request->user());
        });

        return response()->json(['success' => true, 'message' => 'Official Use saved successfully.', 'id' => $use->id, 'status' => $use->status], 201);
    }

    public function update(Request $request, OfficialUse $officialUse): JsonResponse
    {
        $this->authorizeUse($request, $officialUse);
        $data = $this->validatedSchedule($request);
        $use = DB::transaction(function () use ($request, $officialUse, $data) {
            // Use the same facility locks as booking creation/editing. Stable ordering permits resource changes.
            $resources = Facility::whereIn('id', [$officialUse->facility_id, $data['facility_id']])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $use = OfficialUse::lockForUpdate()->findOrFail($officialUse->id);
            $this->authorizeUse($request, $use);
            if (! $resources->has($use->facility_id)) {
                throw ValidationException::withMessages(['date' => 'This Official Use changed while editing. Refresh the table and try again.']);
            }
            if (! in_array($use->status, ['active', 'conflict'], true)) {
                throw ValidationException::withMessages(['status' => 'Only Active or Conflict Official Use schedules can be edited.']);
            }
            $facility = $resources->get($data['facility_id']);
            abort_if($facility === null, 404);
            abort_unless($request->user()->role === 'super_admin' || $facility->barangay === $request->user()->barangay, 403);

            return OfficialUseScheduling::update($use, $facility, $data, $request->user());
        }, 3);

        return response()->json(['success' => true, 'message' => 'Official Use updated successfully.', 'id' => $use->id, 'status' => $use->status]);
    }

    public function decision(Request $request, OfficialUseConflict $conflict): JsonResponse|RedirectResponse
    {
        abort_unless($conflict->reservation->user_id === $request->user()->id, 403);
        $data = $request->validate(['decision' => ['required', 'in:reschedule,cancel']]);
        DB::transaction(function () use ($request, $conflict, $data) {
            // Keep lock order consistent with Official Use creation and Accepted -> Edit.
            Facility::lockForUpdate()->findOrFail($conflict->officialUse->facility_id);
            $reservation = Reservation::lockForUpdate()->findOrFail($conflict->reservation_id);
            $conflict = OfficialUseConflict::lockForUpdate()->findOrFail($conflict->id);
            abort_unless($reservation->user_id === $request->user()->id, 403);
            if ($reservation->status !== 'accepted' || $conflict->resolution === 'resolved') {
                throw ValidationException::withMessages(['decision' => 'This conflict no longer requires a decision.']);
            }
            $resolution = $data['decision'] === 'reschedule' ? 'reschedule_requested' : 'cancellation_requested';
            if ($conflict->resolution !== $resolution) {
                $conflict->transitionTo($resolution, ['decided_at' => now()]);
                User::where('role', 'admin')->where('barangay', $conflict->officialUse->barangay)
                    ->each(fn (User $admin) => $admin->notify(new ReservationActivity($reservation, 'official_use_decision', resolution: $resolution)));
            }
        });
        $message = 'Your preference has been recorded. The barangay administrator will process your request.';

        return $request->expectsJson() ? response()->json(['success' => true, 'message' => $message])
            : redirect()->route('reservations.index', ['reservation' => $conflict->reservation_id])->with('reservation_status', $message);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);
    }

    private function authorizeUse(Request $request, OfficialUse $use): void
    {
        $this->authorizeAdmin($request);
        abort_unless($request->user()->role === 'super_admin' || $use->barangay === $request->user()->barangay, 403);
    }

    private function validatedSchedule(Request $request): array
    {
        return $request->validate([
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'],
            'purpose' => ['required', 'string', 'max:500'],
        ]);
    }
}
