<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;

class DashboardOverview
{
    public static function forUser(User $user): array
    {
        abort_unless(in_array($user->role, ['admin', 'super_admin'], true), 403);
        $systemWide = $user->role === 'super_admin';
        $reservations = Reservation::query()->inBarangayFor($user);
        $data = [
            'systemWide' => $systemWide,
            'recentReservations' => (clone $reservations)->with('user:id,name')
                ->latest()->orderByDesc('id')->limit(4)->get(),
        ];

        if ($systemWide) {
            // Same represented-barangay definition used by Super Admin Analytics.
            $barangays = User::query()->whereIn('role', ['admin', 'user'])->select('barangay')
                ->union(Facility::query()->select('barangay'))
                ->union(Reservation::query()->select('barangay'))
                ->pluck('barangay')->filter(fn ($name) => filled($name));

            return $data + [
                'totalBarangays' => $barangays->count(),
                'totalAdmins' => User::where('role', 'admin')->count(),
                'totalFacilities' => Facility::count(),
                'totalReservations' => (clone $reservations)->count(),
                'recentActivity' => self::recentActivity(),
            ];
        }

        $counts = (clone $reservations)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $today = today();
        $now = now();
        // Include overnight reservations overlapping today using the existing period rules.
        $todaysReservations = (clone $reservations)->with('user:id,name')
            ->whereBetween('reservation_date', [$today->copy()->subDay()->toDateString(), $today->toDateString()])
            ->get()->filter(fn ($reservation) => $reservation->period()->overlaps($today, $today->copy()->addDay()))
            ->sortBy(fn ($reservation) => [
                $reservation->status === 'rejected' ? 3 : ($reservation->period()->contains($now) ? 0 : ($reservation->period()->start->gt($now) ? 1 : 2)),
                $reservation->period()->start->timestamp,
                $reservation->id,
            ])->take(6)->values();

        return $data + [
            // Reuse catalog availability, including equipment and the logged-in barangay scope.
            'facilityCount' => FacilityCatalog::allForUser($user)->where('is_available', true)->count(),
            'pendingCount' => (int) $counts->get('pending', 0),
            'acceptedCount' => (int) $counts->get('accepted', 0),
            'rejectedCount' => (int) $counts->get('rejected', 0),
            'todaysReservations' => $todaysReservations,
        ];
    }

    private static function recentActivity()
    {
        // A bounded overview of existing records, not an audit history of status changes.
        $admins = User::where('role', 'admin')->latest()->orderByDesc('id')->limit(6)->get()
            ->map(fn ($admin) => [
                'title' => 'Admin account created', 'detail' => $admin->name, 'barangay' => $admin->barangay,
                'at' => $admin->created_at, 'url' => route('admins.show', $admin),
            ]);
        $facilities = Facility::latest()->orderByDesc('id')->limit(6)->get()
            ->map(fn ($facility) => [
                'title' => 'Facility / equipment created', 'detail' => $facility->name, 'barangay' => $facility->barangay,
                'at' => $facility->created_at, 'url' => route('facilities.show', $facility->slug),
            ]);
        $reservations = Reservation::with('user:id,name')->orderByDesc('updated_at')->orderByDesc('id')->limit(6)->get()
            ->map(fn ($reservation) => [
                'title' => 'Reservation updated',
                'detail' => $reservation->facility_name.' · '.($reservation->user?->name ?? 'Unknown user').' · '.ucfirst($reservation->status),
                'barangay' => $reservation->barangay, 'at' => $reservation->updated_at,
                'url' => route('reservations.index', ['reservation' => $reservation->id]),
            ]);

        return $admins->concat($facilities)->concat($reservations)->sortByDesc('at')->take(6)->values();
    }
}
