<?php

namespace App\Http\Controllers;

use App\Support\FacilityCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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

        FacilityCatalog::create($this->validatedFacility($request), $request->user());

        return redirect()
            ->route('facilities')
            ->with('facility_status', 'Facility added successfully.');
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        $this->authorizeAdmin($request);

        abort_if(FacilityCatalog::update($slug, $this->validatedFacility($request), $request->user()) === null, 404);

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
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'in:Facility,Equipment'],
            'description' => ['required', 'string', 'max:500'],
            'location' => ['required', 'string', 'max:160'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'status' => ['required', 'in:Available,Unavailable'],
        ]);
    }
}
