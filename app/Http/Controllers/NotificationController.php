<?php

namespace App\Http\Controllers;

use App\Notifications\ReservationActivity;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private function scoped(Request $request)
    {
        $user = $request->user();
        $query = $user->notifications()->where('type', ReservationActivity::class);
        if ($user->role !== 'super_admin') {
            $query->where('data->barangay', $user->barangay);
        }
        if (! in_array($user->role, ['admin', 'super_admin'], true)) {
            $query->where('data->event', '!=', 'submitted');
        }

        return $query;
    }

    public function index(Request $request)
    {
        return response()->json([
            'unread_count' => $this->scoped($request)->whereNull('read_at')->count(),
            'notifications' => $this->scoped($request)->paginate(20)->through(fn ($notification) => [
                'id' => $notification->id,
                'data' => ReservationActivity::forDisplay($notification->data),
                'read' => $notification->read_at !== null,
                'time' => $notification->created_at->diffForHumans(),
                'open_url' => route('notifications.open', $notification->id),
            ]),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $request, string $notification)
    {
        $notification = $this->scoped($request)->findOrFail($notification);
        $notification->markAsRead();

        return redirect()->route('reservations.index', ['reservation' => $notification->data['reservation_id']]);
    }

    public function readAll(Request $request)
    {
        $this->scoped($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }
}
