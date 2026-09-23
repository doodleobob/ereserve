<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FacilityCatalog
{
    public static function allForUser(User $user): Collection
    {
        $query = Facility::query()->orderBy('name');

        if ($user->role !== 'super_admin') {
            $query->where('barangay', $user->barangay);
        }

        return $query->get()->map(fn (Facility $facility) => self::toArray($facility));
    }

    public static function create(array $data, User $user): array
    {
        $slug = Str::slug($data['name']);
        $baseSlug = $slug;
        $counter = 2;

        while (Facility::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $facility = Facility::create([
            ...$data,
            'barangay' => $user->barangay,
            'slug' => $slug,
        ]);

        return self::toArray($facility);
    }

    public static function update(string $slug, array $data, User $user): ?array
    {
        $facility = self::queryForUser($user)->where('slug', $slug)->first();

        if ($facility === null) {
            return null;
        }

        $facility->update($data);

        return self::toArray($facility->refresh());
    }

    public static function delete(string $slug, User $user): void
    {
        self::queryForUser($user)->where('slug', $slug)->delete();
    }

    public static function findForUser(string $slug, User $user): ?array
    {
        $facility = self::queryForUser($user)->where('slug', $slug)->first();

        return $facility ? self::toArray($facility) : null;
    }

    private static function queryForUser(User $user)
    {
        $query = Facility::query();

        if ($user->role !== 'super_admin') {
            $query->where('barangay', $user->barangay);
        }

        return $query;
    }

    private static function toArray(Facility $facility): array
    {
        $currentReservation = self::currentReservation($facility);

        return [
            'id' => $facility->id,
            'barangay' => $facility->barangay,
            'slug' => $facility->slug,
            'name' => $facility->name,
            'description' => $facility->description,
            'list_description' => Str::limit($facility->description, 95),
            'category' => $facility->category,
            'capacity' => (int) $facility->capacity,
            'location' => $facility->location,
            'status' => $facility->status,
            'current_reservation' => $currentReservation ? [
                'start_time' => Carbon::parse($currentReservation->start_time)->format('g:i A'),
                'end_time' => Carbon::parse($currentReservation->end_time)->format('g:i A'),
            ] : null,
        ];
    }

    private static function currentReservation(Facility $facility): ?Reservation
    {
        $now = now();

        return Reservation::query()
            ->where('facility_id', $facility->id)
            ->where('barangay', $facility->barangay)
            ->where('status', 'accepted')
            ->whereDate('reservation_date', $now->toDateString())
            ->where('start_time', '<=', $now->format('H:i:s'))
            ->where('end_time', '>', $now->format('H:i:s'))
            ->orderBy('start_time')
            ->first();
    }
}
