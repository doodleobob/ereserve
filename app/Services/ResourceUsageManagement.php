<?php

namespace App\Services;

use App\Models\EquipmentInventoryAdjustment;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\ResourceUsage;
use App\Models\ResourceUsageEvent;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Support\OfficialUseScheduling;
use App\Support\ReservationAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResourceUsageManagement
{
    public static function inventory(Facility $facility): array
    {
        $loans = ResourceUsage::where('facility_id', $facility->id)->where('barangay', $facility->barangay)
            ->whereColumn('released_quantity', '>', 'returned_quantity')->with('reservation')->get();
        $outstanding = (int) $loans->sum(fn ($loan) => $loan->outstanding());
        $usable = $facility->usableQuantity();

        return ['total' => $facility->total_quantity, 'outstanding' => $outstanding,
            'usable' => $usable, 'damaged' => (int) $facility->damaged_quantity,
            'overdue' => (int) $loans->filter(fn ($loan) => $loan->overdue())->sum(fn ($loan) => $loan->outstanding()),
            'available' => $usable === null ? null : max(0, $usable - $outstanding),
            'inconsistent' => $usable !== null && $outstanding > $usable];
    }

    public function adjustInventory(User $actor, Facility $facility, array $input): EquipmentInventoryAdjustment
    {
        Gate::forUser($actor)->authorize('adjust', [EquipmentInventoryAdjustment::class, $facility]);
        $data = Validator::make($input, [
            ...$this->inventoryAdjustmentRules(),
            'reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['required', 'accepted'],
            'request_key' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $facility, $data) {
            $facility = Facility::lockForUpdate()->findOrFail($facility->id);
            Gate::forUser($actor)->authorize('adjust', [EquipmentInventoryAdjustment::class, $facility]);
            $previous = EquipmentInventoryAdjustment::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($previous) {
                $this->require($previous->facility_id === $facility->id && $previous->performed_by === $actor->id
                    && $previous->adjustment_type === $data['adjustment_type'] && (int) $previous->quantity === (int) $data['quantity']
                    && $previous->reason === trim($data['reason']), 'This adjustment key has already been used for different details.');

                return $previous;
            }
            $quantity = (int) $data['quantity'];
            [$total, $damaged] = $this->inventoryAdjustmentStock($facility, $data['adjustment_type'], $quantity);

            return $this->writeInventoryAdjustment($actor, $facility, $total, $damaged, $data['adjustment_type'], $quantity, $data['reason'], $data['request_key']);
        }, 3);
    }

    /** Read-only preview; saving repeats these same checks under the resource lock. */
    public function previewInventoryAdjustment(User $actor, Facility $facility, array $input): array
    {
        Gate::forUser($actor)->authorize('adjust', [EquipmentInventoryAdjustment::class, $facility]);
        $data = Validator::make($input, $this->inventoryAdjustmentRules())->validate();

        return DB::transaction(function () use ($actor, $facility, $data) {
            $facility = Facility::lockForUpdate()->findOrFail($facility->id);
            Gate::forUser($actor)->authorize('adjust', [EquipmentInventoryAdjustment::class, $facility]);
            [$total, $damaged] = $this->inventoryAdjustmentStock($facility, $data['adjustment_type'], (int) $data['quantity']);
            $this->validateInventoryStock($facility, $total, $damaged);
            $after = clone $facility;
            $after->total_quantity = $total;
            $after->damaged_quantity = $damaged;

            return [
                'before' => ['total' => $facility->total_quantity, 'usable' => $facility->usableQuantity(), 'damaged' => (int) $facility->damaged_quantity],
                'after' => ['total' => $total, 'usable' => $after->usableQuantity(), 'damaged' => $damaged],
            ];
        }, 3);
    }

    private function inventoryAdjustmentRules(): array
    {
        return [
            'adjustment_type' => ['required', 'in:add,reduce,damage,repair,dispose'],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }

    private function inventoryAdjustmentStock(Facility $facility, string $type, int $quantity): array
    {
        $this->require($facility->total_quantity !== null, 'Verify the existing stock in the equipment form before recording an adjustment.');
        $total = $facility->total_quantity;
        $damaged = (int) $facility->damaged_quantity;
        match ($type) {
            'add' => $total += $quantity,
            'reduce' => $total -= $quantity,
            'damage' => $damaged += $quantity,
            'repair' => $damaged -= $quantity,
            'dispose' => [$total -= $quantity, $damaged -= $quantity],
        };

        return [$total, $damaged];
    }

    /** Equipment form edits use the same validation and history as the Inventory tab. Caller holds the resource lock. */
    public function setRecordedStock(User $actor, Facility $facility, int $total, string $reason): void
    {
        if ($total === $facility->total_quantity) {
            return;
        }
        Gate::forUser($actor)->authorize('adjust', [EquipmentInventoryAdjustment::class, $facility]);
        try {
            $this->writeInventoryAdjustment($actor, $facility, $total, (int) $facility->damaged_quantity,
                $facility->total_quantity === null ? 'verify' : ($total > $facility->total_quantity ? 'add' : 'reduce'),
                abs($total - ($facility->total_quantity ?? 0)), $reason);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                isset($exception->errors()['reason']) ? 'inventory_reason' : 'total_quantity' => collect($exception->errors())->flatten()->all(),
            ]);
        }
    }

    private function validateInventoryStock(Facility $facility, int $total, int $damaged): void
    {
        $this->require($total >= 0 && $total <= 4294967295 && $damaged >= 0 && $damaged <= $total, 'This adjustment would create negative or inconsistent stock. Choose removal of damaged units when disposing of damaged stock.', 'quantity');
        $usable = $total - $damaged;
        $outstanding = ResourceUsage::where('facility_id', $facility->id)->lockForUpdate()->get()->sum(fn ($loan) => $loan->outstanding());
        $this->require($usable >= $outstanding, 'Usable stock cannot be less than outstanding borrowed units. Record their returns first.', 'quantity');
        if ($facility->usableQuantity() === null || $usable < $facility->usableQuantity()) {
            $start = now('Asia/Manila');
            $reservations = Reservation::forFacility($facility)->where('status', 'accepted')->whereNotNull('quantity')
                ->where('reservation_date', '>=', $start->copy()->subDays(2)->toDateString())->get();
            $uses = $facility->officialUses()->whereIn('status', ['active', 'conflict'])->whereNotNull('quantity')
                ->where('date', '>=', $start->copy()->subDays(2)->toDateString())->get();
            $schedules = $reservations->concat($uses);
            $end = $schedules->map(fn ($schedule) => $schedule->period()->allocationEnd(true))->max() ?? $start;
            $this->require($usable >= ReservationAvailability::peakAllocation($facility, $schedules, $start, $end), 'Usable stock cannot be less than units allocated to upcoming schedules and buffers. Resolve those schedules first.', 'quantity');
        }
    }

    private function writeInventoryAdjustment(User $actor, Facility $facility, int $total, int $damaged, string $type, int $quantity, string $reason, ?string $key = null): EquipmentInventoryAdjustment
    {
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:1000']])->validate();
        $this->validateInventoryStock($facility, $total, $damaged);
        $adjustment = EquipmentInventoryAdjustment::create([
            'facility_id' => $facility->id, 'barangay' => $facility->barangay, 'adjustment_type' => $type, 'quantity' => $quantity,
            'total_before' => $facility->total_quantity, 'total_after' => $total,
            'damaged_before' => (int) $facility->damaged_quantity, 'damaged_after' => $damaged,
            'reason' => trim($reason), 'performed_by' => $actor->id, 'actor_name' => $actor->name,
            'request_key' => $key, 'created_at' => now(),
        ]);
        $facility->total_quantity = $total;
        $facility->damaged_quantity = $damaged;
        $facility->save();
        OfficialUseScheduling::refreshEquipmentConflicts($facility, $actor);

        return $adjustment;
    }

    public function record(User $actor, Reservation $reservation, array $input): ResourceUsage
    {
        Gate::forUser($actor)->authorize('operate', [ResourceUsage::class, $reservation]);
        $data = Validator::make($input, [
            'action' => ['required', 'in:check_in,check_out,release,return'],
            'quantity' => ['required_if:action,release,return', 'nullable', 'integer', 'min:1', 'max:4294967295', 'prohibited_if:action,check_in,check_out'],
            'request_key' => ['required', 'uuid'], 'notes' => ['nullable', 'string', 'max:1000'],
            'confirmed' => ['required', 'accepted'],
        ])->validate();
        $quantity = isset($data['quantity']) ? (int) $data['quantity'] : null;
        $notes = $data['notes'] ?? null;
        $hash = hash('sha256', json_encode([$reservation->id, $actor->id, $data['action'], $quantity, $notes], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $reservation, $data, $quantity, $notes, $hash) {
            // Same lock order as acceptance, rescheduling, inventory edits and Official Use.
            // The shared resource lock serializes loans across DIFFERENT reservations.
            $facility = Facility::query()->when($reservation->facility_id !== null,
                fn ($q) => $q->whereKey($reservation->facility_id),
                fn ($q) => $q->where('barangay', $reservation->barangay)->where('slug', $reservation->facility_slug)
            )->lockForUpdate()->first();
            $this->require($facility !== null, 'The resource must be linked to a verified inventory record.');
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);
            $this->require($reservation->facility_id === null || $reservation->facility_id === $facility->id, 'The resource changed. Refresh and retry.');
            $reservation->setRelation('facility', $facility);
            Gate::forUser($actor)->authorize('operate', [ResourceUsage::class, $reservation]);
            $usage = ResourceUsage::where('reservation_id', $reservation->id)->lockForUpdate()->first();
            $previous = ResourceUsageEvent::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($previous) {
                $this->require($usage && $previous->resource_usage_id === $usage->id && hash_equals($previous->request_hash, $hash), 'This confirmation key has already been used for a different transaction.');

                return $usage;
            }

            $action = $data['action'];
            $type = $usage?->resource_type ?? $facility->category;
            $this->require(in_array($action, ['release', 'return'], true) === ($type === 'Equipment'), 'This action does not match the resource type.');
            $period = $reservation->period();
            $this->require($period->isValid(), 'The reservation schedule is invalid.');
            $starting = in_array($action, ['check_in', 'release'], true);
            if ($starting) {
                $this->require($reservation->status === 'accepted', 'Only accepted reservations can start resource usage.');
                $this->require($facility->status === 'Available' && $facility->category === $type, 'This resource is unavailable or its classification has changed.');
                $this->require(! $reservation->officialUseConflicts()->unresolved()->whereHas('officialUse', fn ($q) => $q->whereIn('status', ['active', 'conflict']))->exists(), 'Resolve the Official Use conflict before handing over this resource.');
                if ($type === 'Equipment') {
                    $this->require($reservation->quantity !== null && $reservation->quantity > 0 && $facility->total_quantity !== null, 'Verified inventory and an explicit approved quantity are required. Legacy whole-resource holds cannot be released.');
                }
                ReservationAvailability::validateBooking($facility, $period, $reservation->id, $reservation->quantity);
            }

            if (! $usage) {
                $this->require($starting, 'This resource has not been checked in or released.');
                $usage = new ResourceUsage(['reservation_id' => $reservation->id, 'facility_id' => $facility->id,
                    'barangay' => $facility->barangay, 'resource_type' => $type,
                    'approved_quantity' => $type === 'Equipment' ? $reservation->quantity : null,
                    'released_quantity' => 0, 'returned_quantity' => 0]);
            }
            $this->require($usage->facility_id === $facility->id && $usage->barangay === $actor->barangay, 'This usage belongs to a different resource or barangay.');
            $now = now();
            $this->require(! $usage->actual_started_at || $now->gte($usage->actual_started_at), 'The server time precedes the recorded start.');

            if ($action === 'check_in') {
                $this->require($usage->actual_started_at === null, 'This facility has already been checked in.');
                $occupied = ResourceUsage::where('facility_id', $facility->id)->where('resource_type', 'Facility')->whereNotNull('actual_started_at')->whereNull('actual_ended_at')->lockForUpdate()->get();
                $this->require($occupied->isEmpty(), 'The facility is still checked in under another reservation.');
                $this->require(OfficialUseScheduling::schedules($facility->id, $now, $now->copy()->addSecond(), $facility->barangay)->isEmpty(), 'The facility is currently reserved for Official Use.');
                $usage->actual_started_at = $now;
            } elseif ($action === 'check_out') {
                $this->require($usage->actual_started_at !== null && $usage->actual_ended_at === null, 'Only a checked-in facility can be checked out.');
                $usage->actual_ended_at = $now;
            } elseif ($action === 'release') {
                $this->require($usage->released_quantity + $quantity <= min($usage->approved_quantity, $reservation->quantity), 'The release exceeds the remaining approved quantity.');
                // Locking reads remain current under MySQL REPEATABLE READ as well as READ COMMITTED.
                $outstanding = ResourceUsage::where('facility_id', $facility->id)->lockForUpdate()->get()->sum(fn ($loan) => $loan->outstanding());
                $this->require($outstanding + $quantity <= $facility->usableQuantity(), 'Insufficient physically available equipment. Outstanding loans must be returned first.');
                $official = OfficialUseScheduling::schedules($facility->id, $now, $now->copy()->addSecond(), $facility->barangay);
                $held = ReservationAvailability::peakAllocation($facility, $official, $now, $now->copy()->addSecond());
                $this->require($outstanding + $quantity + $held <= $facility->usableQuantity(), 'Current Official Use commitments block this equipment handover.');
                $usage->released_quantity += $quantity;
                $usage->actual_started_at ??= $now;
            } else {
                $this->require($quantity <= $usage->outstanding(), 'The return exceeds the outstanding borrowed quantity.');
                $usage->returned_quantity += $quantity;
                // All approved units must have been released and returned; pending releases stay open.
                if ($usage->returned_quantity === $usage->approved_quantity) {
                    $usage->actual_ended_at = $now;
                }
            }
            $usage->save();
            $usage->events()->create(['event_type' => $action, 'quantity' => $quantity, 'performed_by' => $actor->id,
                'actor_name' => $actor->name, 'request_key' => $data['request_key'], 'request_hash' => $hash,
                'occurred_at' => $now, 'scheduled_start' => $period->start, 'scheduled_end' => $period->end, 'notes' => $notes]);

            if ($action !== 'return' || $usage->status() === 'returned') {
                $notification = new ReservationActivity($reservation, 'usage_'.$action);
                $resident = $reservation->user;
                DB::afterCommit(function () use ($resident, $notification, $usage) {
                    try {
                        $resident?->notify($notification);
                    } catch (Throwable $exception) {
                        // Inventory is already committed. Do not turn a successful handover into a retry.
                        Log::warning('Usage notification failed', ['usage_id' => $usage->id, 'exception_type' => $exception::class]);
                    }
                });
            }

            return $usage;
        }, 3);
    }

    private function require(bool $condition, string $message, string $field = 'usage'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
