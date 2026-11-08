<?php

namespace App\Http\Controllers;

use App\Services\Authorization;
use App\Support\AdminPermission;
use App\Support\SuperAdminAnalytics;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SuperAdminAnalyticsController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless($request->user()->role === 'super_admin' && app(Authorization::class)->allows($request->user(), AdminPermission::ANALYTICS_VIEW), 403);

        return view('super_admin.analytics.super-admin', ['analytics' => SuperAdminAnalytics::forRequest($request)]);
    }
}
