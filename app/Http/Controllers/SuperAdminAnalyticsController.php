<?php

namespace App\Http\Controllers;

use App\Support\SuperAdminAnalytics;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SuperAdminAnalyticsController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless($request->user()->role === 'super_admin', 403);

        return view('super-admin-analytics', ['analytics' => SuperAdminAnalytics::forRequest($request)]);
    }
}
