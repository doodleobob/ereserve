<?php

namespace App\Http\Controllers;

use App\Models\EquipmentInventoryAdjustment;
use App\Models\Facility;
use App\Models\Reservation;
use App\Support\FacilityCatalog;
use App\Support\HourlyBooking;
use App\Support\Money;
use App\Support\ReservationAvailability;
use App\Support\ReservationCalendar;
use App\Support\ReservationPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class FacilityController extends Controller
{
    public function availability(Request $request, string $slug): JsonResponse
    {
        $catalog = FacilityCatalog::findForUser($slug, $request->user());
        abort_if($catalog === null, 404);
        $facility = Facility::findOrFail($catalog['id']);
        $data = $request->validate([
            'reservation_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'], 'end_time' => ['nullable', 'date_format:H:i'],
            'end_next_day' => ['sometimes', 'boolean'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'reservation_id' => ['nullable', 'integer'],
        ]);
        $existing = isset($data['reservation_id']) ? Reservation::findOrFail($data['reservation_id']) : null;
        if ($existing) {
            Gate::authorize('editOwnRequest', $existing);
            abort_unless($existing->facility_id === $facility->id, 403);
            if ($facility->isEquipment() && $existing->quantity === null && ! isset($data['quantity'])) {
                $data['quantity'] = $facility->total_quantity;
            }
        }
        $options = ReservationAvailability::hourlyOptions($facility, $data);
        $summary = [];
        if (! empty($data['start_time']) && ! empty($data['end_time'])) {
            $period = HourlyBooking::period($data, existing: $existing?->period());
            $estimate = new Reservation([
                'reservation_date' => $data['reservation_date'], 'start_time' => $period->start->format('H:i:s'), 'end_time' => $period->end->format('H:i:s'),
                'hourly_rate_snapshot' => $existing ? $existing->hourly_rate_snapshot : $facility->hourly_rate,
            ]);
            $available = $facility->isEquipment() ? ReservationAvailability::availableQuantity($facility, $period) : null;
            $conflict = null;
            $validationErrors = [];
            try {
                ReservationAvailability::validateBooking($facility, $period, quantity: (int) ($data['quantity'] ?? 1));
            } catch (ValidationException $exception) {
                $validationErrors = $exception->errors();
                $conflict = collect($exception->errors())->flatten()->first();
            }
            if ($facility->status !== 'Available') {
                $conflict = 'This resource is currently unavailable for reservations.';
                $validationErrors = ['reservation' => [$conflict]];
            } elseif ($facility->isEquipment() && $facility->total_quantity === null) {
                $conflict = 'Equipment stock has not been verified. Please contact the barangay administrator.';
                $validationErrors = ['quantity' => [$conflict]];
            }
            $summary = [
                'available_quantity' => $facility->status === 'Available' ? $available : 0,
                'available' => $conflict === null, 'message' => $conflict, 'errors' => $validationErrors,
                'duration_minutes' => $period->durationMinutes(),
                'hourly_rate' => $estimate->hourly_rate_snapshot,
                'estimated_total' => $estimate->calculatedAmount(),
                'end_date' => $period->end->toDateString(),
                'buffer_minutes' => $facility->isEquipment() ? ReservationPeriod::EQUIPMENT_BUFFER_MINUTES : 0,
                'effective_end' => $period->allocationEnd($facility->isEquipment())->toIso8601String(),
            ];
        }

        return response()->json([
            'total_quantity' => $facility->total_quantity,
            ...$options, ...$summary,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): View
    {
        // The page route owns the resource type; query parameters cannot override it.
        $resourceCategory = $request->routeIs('equipment') ? 'Equipment' : 'Facility';
        $resourceRoute = $resourceCategory === 'Equipment' ? 'equipment' : 'facilities';
        $barangays = Facility::query()->distinct()->pluck('barangay')
            ->push($request->user()->barangay)->filter()->unique()->sort()->values();
        $validated = $request->validate(['barangay' => ['sometimes', 'nullable', 'string', Rule::in($barangays->all())]]);
        $selectedBarangay = $validated['barangay'] ?? $request->user()->barangay;
        $isAdmin = $this->isAdminOrSuperAdmin($request);
        $tab = $request->validate(['tab' => ['nullable', 'in:equipment,inventory']])['tab'] ?? 'equipment';
        $inventoryTab = $resourceCategory === 'Equipment' && $tab === 'inventory';
        if ($inventoryTab) {
            Gate::authorize('viewAny', EquipmentInventoryAdjustment::class);
        }
        $filters = $isAdmin ? [] : $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(['all', 'facility', 'equipment'])],
            'status' => ['nullable', Rule::in(['all', 'available', 'unavailable'])],
        ]);

        return view('shared.facilities.index', [
            'barangays' => $barangays,
            'selectedBarangay' => $selectedBarangay,
            'items' => $inventoryTab ? collect() : FacilityCatalog::allForUser($request->user(), $selectedBarangay, $resourceCategory),
            'inventoryTab' => $inventoryTab,
            ...($inventoryTab ? EquipmentInventoryController::viewData($request) : []),
            'resourceCategory' => $resourceCategory,
            'resourceRoute' => $resourceRoute,
            'resourcePlural' => $resourceCategory === 'Equipment' ? 'Equipment' : 'Facilities',
            'isAdmin' => $isAdmin,
            'resourceSearch' => $filters['search'] ?? '',
            'selectedCategory' => ($filters['category'] ?? 'all') === 'all' ? 'all' : strtolower($resourceCategory),
            'selectedStatus' => $filters['status'] ?? 'all',
        ]);
    }

    public function show(Request $request, string $slug): View|RedirectResponse
    {
        $facility = FacilityCatalog::findForUser($slug, $request->user());

        abort_if($facility === null, 404);

        $detailRoute = FacilityCatalog::detailRoute($facility['category']);
        if (! $request->routeIs($detailRoute)) {
            // Slugs are unique. Resolve visibility before redirecting, and preserve all
            // query parameters separately so a query named "slug" cannot replace the path.
            $query = $request->getQueryString();
            $request->session()->reflash();

            return redirect()->to(route($detailRoute, $facility['slug']).($query ? '?'.$query : ''), 301)
                ->header('Cache-Control', 'private, no-store');
        }

        return view('shared.facilities.show', [
            'facility' => $facility,
        ] + ($request->user()->role === 'user' ? (new ReservationCalendar)->forRequest($request, $facility) : []));
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $this->validatedFacility($request);
        $photoPaths = $this->storePhotos($request);

        try {
            FacilityCatalog::create($data, $request->user(), $photoPaths);
        } catch (Throwable $exception) {
            $this->deletePhotos($photoPaths);

            throw $exception;
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $data['category'].' added successfully.'], 201);
        }

        return redirect()
            ->route($data['category'] === 'Equipment' ? 'equipment' : 'facilities')
            ->with('facility_status', $data['category'].' added successfully.');
    }

    public function update(Request $request, string $slug): JsonResponse|RedirectResponse
    {
        $this->authorizeAdmin($request);

        $facility = FacilityCatalog::findForUser($slug, $request->user());

        abort_if($facility === null, 404);

        $data = $this->validatedFacility($request, $facility);
        $removePhotoIds = array_values(array_unique(array_map(
            'intval',
            $request->input('remove_photo_ids', [])
        )));
        $facilityPhotoIds = collect($facility['photos'])->pluck('id')->filter()->all();

        if (count(array_diff($removePhotoIds, $facilityPhotoIds)) > 0) {
            throw ValidationException::withMessages([
                'remove_photo_ids' => 'One or more selected photos do not belong to this facility.',
            ]);
        }

        $newPhotoCount = count($this->uploadedPhotos($request));

        if (count($facility['photos']) - count($removePhotoIds) + $newPhotoCount > 4) {
            throw ValidationException::withMessages([
                'photos' => 'A facility can have a maximum of 4 photos.',
            ]);
        }

        $photoPaths = $this->storePhotos($request);

        try {
            abort_if(
                FacilityCatalog::update(
                    $slug,
                    $data,
                    $request->user(),
                    $photoPaths,
                    $removePhotoIds
                ) === null,
                404
            );
        } catch (Throwable $exception) {
            $this->deletePhotos($photoPaths);

            throw $exception;
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $data['category'].' updated successfully.']);
        }

        return redirect()
            ->route($data['category'] === 'Equipment' ? 'equipment' : 'facilities')
            ->with('facility_status', $data['category'].' updated successfully.');
    }

    public function destroy(Request $request, string $slug): JsonResponse|RedirectResponse
    {
        $this->authorizeAdmin($request);

        $facility = FacilityCatalog::findForUser($slug, $request->user());
        abort_if($facility === null, 404);

        FacilityCatalog::delete($slug, $request->user());

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $facility['category'].' deleted successfully.']);
        }

        return redirect()
            ->route($facility['category'] === 'Equipment' ? 'equipment' : 'facilities')
            ->with('facility_status', $facility['category'].' deleted successfully.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($this->isAdminOrSuperAdmin($request), 403);
    }

    private function isAdminOrSuperAdmin(Request $request): bool
    {
        return in_array($request->user()->role, ['admin', 'super_admin'], true);
    }

    private function validatedFacility(Request $request, ?array $existing = null): array
    {
        $unverifiedEquipment = $existing !== null && $existing['category'] === 'Equipment' && $existing['total_quantity'] === null;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'reservation_access' => ['sometimes', 'required', 'in:residents_only,all_registered_users'],
            'hourly_rate' => ['sometimes', ...Money::rules('99999999.99')],
            'category' => ['required', 'in:Facility,Equipment'],
            'description' => ['required', 'string', 'max:500'],
            'location' => ['required', 'string', 'max:160'],
            'capacity' => ['exclude_unless:category,Facility', 'required', 'integer', 'min:1', 'max:10000'],
            'total_quantity' => ['exclude_unless:category,Equipment', $unverifiedEquipment ? 'nullable' : 'required', 'integer', 'min:0', 'max:4294967295'],
            'inventory_reason' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:Available,Unavailable'],
            'photos' => ['nullable', 'array', 'max:4'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_photo_ids' => ['nullable', 'array'],
            'remove_photo_ids.*' => ['integer', 'distinct'],
        ]);

        if (count($this->uploadedPhotos($request)) > 4) {
            throw ValidationException::withMessages([
                'photos' => 'A facility can have a maximum of 4 photos.',
            ]);
        }

        unset($validated['photo'], $validated['photos'], $validated['remove_photo_ids']);
        if ($unverifiedEquipment && ($validated['total_quantity'] ?? null) === null) {
            unset($validated['total_quantity']);
        }

        return $validated;
    }

    private function uploadedPhotos(Request $request): array
    {
        $photos = $request->file('photos', []);
        $photos = is_array($photos) ? $photos : [$photos];

        if ($request->hasFile('photo')) {
            $photos[] = $request->file('photo');
        }

        return array_values(array_filter($photos));
    }

    private function storePhotos(Request $request): array
    {
        $paths = [];

        try {
            foreach ($this->uploadedPhotos($request) as $photo) {
                $path = $photo->store('facilities', 'public');

                if (! is_string($path)) {
                    throw new RuntimeException('The facility photo could not be stored.');
                }

                $paths[] = $path;
            }
        } catch (Throwable $exception) {
            $this->deletePhotos($paths);

            throw $exception;
        }

        return $paths;
    }

    private function deletePhotos(array $photoPaths): void
    {
        if ($photoPaths !== []) {
            Storage::disk('public')->delete($photoPaths);
        }
    }
}
