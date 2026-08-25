<?php

namespace App\Http\Controllers;

use App\Support\FacilityCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FacilityController extends Controller
{
    public function index(): View
    {
        return view('facilities', [
            'items' => FacilityCatalog::all(),
        ]);
    }

    public function show(string $slug): View
    {
        $facility = FacilityCatalog::find($slug);

        abort_if($facility === null, 404);

        return view('facility-show', [
            'facility' => $facility,
        ]);
    }
}
