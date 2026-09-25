<?php

namespace App\Http\Controllers;

use App\Support\FacilityCatalog;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class FacilityController extends Controller
{
    public function index(Request $request): View
    {
        return view('facilities', [
            'items' => FacilityCatalog::allForUser($request->user()),
            'isAdmin' => $this->isAdminOrSuperAdmin($request),
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $facility = FacilityCatalog::findForUser($slug, $request->user());

        abort_if($facility === null, 404);

        return view('facility-show', [
            'facility' => $facility,
        ]);
    }

    public function store(Request $request): RedirectResponse
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

        return redirect()
            ->route('facilities')
            ->with('facility_status', 'Facility added successfully.');
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $facility = FacilityCatalog::findForUser($slug, $request->user());

        abort_if($facility === null, 404);

        $data = $this->validatedFacility($request);
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

        return redirect()
            ->route('facilities')
            ->with('facility_status', 'Facility updated successfully.');
    }

    public function destroy(Request $request, string $slug): RedirectResponse
    {
        $this->authorizeAdmin($request);

        abort_if(FacilityCatalog::findForUser($slug, $request->user()) === null, 404);

        FacilityCatalog::delete($slug, $request->user());

        return redirect()
            ->route('facilities')
            ->with('facility_status', 'Facility deleted successfully.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($this->isAdminOrSuperAdmin($request), 403);
    }

    private function isAdminOrSuperAdmin(Request $request): bool
    {
        return in_array($request->user()->role, ['admin', 'super_admin'], true);
    }

    private function validatedFacility(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'hourly_rate' => ['sometimes', ...Money::rules('99999999.99')],
            'category' => ['required', 'in:Facility,Equipment'],
            'description' => ['required', 'string', 'max:500'],
            'location' => ['required', 'string', 'max:160'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
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
