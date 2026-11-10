<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
use App\Services\Authorization;
use App\Support\AdminPermission;

class ReservationPolicy
{
    public function accept(User $user, Reservation $reservation): bool
    {
        return app(Authorization::class)->canManageReservation($user, $reservation, AdminPermission::RESERVATIONS_ACCEPT);
    }

    public function viewEquipmentAvailability(User $user, Reservation $reservation): bool
    {
        return $user->is_active && $this->manage($user, $reservation);
    }

    public function editOwnRequest(User $user, Reservation $reservation): bool
    {
        return $user->is_active && $user->role === 'user' && $reservation->user_id === $user->id;
    }

    public function cancelOwnPending(User $user, Reservation $reservation): bool
    {
        return $this->editOwnRequest($user, $reservation);
    }

    public function manage(User $user, Reservation $reservation): bool
    {
        return app(Authorization::class)->canManageReservation($user, $reservation, AdminPermission::RESERVATIONS_MANAGE);
    }

    public function correctPayment(User $user, Reservation $reservation): bool
    {
        return app(Authorization::class)->canManageReservation($user, $reservation, AdminPermission::PAYMENTS_MANAGE);
    }
}
