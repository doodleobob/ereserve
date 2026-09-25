<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Support\AdminAnalytics;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        return view('analytics', [
            'analytics' => AdminAnalytics::forRequest(
                $request,
                Reservation::query()->inBarangayFor($request->user())
            ),
        ]);
    }
}
