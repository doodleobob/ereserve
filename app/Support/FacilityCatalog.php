<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FacilityCatalog
{
    public static function allForUser(User $user, ?string $barangay = null): Collection
    {
        $query = Facility::query()->with('photos')->orderBy('name');

        if ($user->role !== 'super_admin') {
            $query->where('barangay', $user->role === 'user' ? ($barangay ?? $user->barangay) : $user->barangay);
        }

        return $query->get()->map(fn (Facility $facility) => self::toArray($facility, $user));
    }

    /** Shared read-only catalog; management methods retain their barangay scope. */
    public static function calendarResources(User $user, string $barangay, string $category): Collection
    {
        return Facility::query()->with('photos')
            ->where('barangay', $barangay)->where('category', $category)
            ->orderBy('barangay')->orderBy('name')->get()
            ->map(fn (Facility $facility) => self::toArray($facility, $user));
    }

    public static function create(array $data, User $user, array $photoPaths = []): array
    {
        $slug = Str::slug($data['name']);
        $baseSlug = $slug;
        $counter = 2;

        while (Facility::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $facility = DB::transaction(function () use ($data, $user, $slug, $photoPaths): Facility {
            $facility = Facility::create([
                ...$data,
                'photo_path' => $photoPaths[0] ?? null,
                'barangay' => $user->barangay,
                'slug' => $slug,
            ]);

            self::appendPhotos($facility, $photoPaths, 0);

            return $facility;
        });

        return self::toArray($facility->load('photos'), $user);
    }

    public static function update(
        string $slug,
        array $data,
        User $user,
        array $newPhotoPaths = [],
        array $removePhotoIds = []
    ): ?array {
        $removedPaths = [];

        $facility = DB::transaction(function () use (
            $slug,
            $data,
            $user,
            $newPhotoPaths,
            $removePhotoIds,
            &$removedPaths
        ): ?Facility {
            $facility = self::queryForUser($user)
                ->where('slug', $slug)
                ->lockForUpdate()
                ->first();

            if ($facility === null) {
                return null;
            }

            if ($facility->photos->isEmpty() && $facility->photo_path) {
                $facility->photos()->create([
                    'path' => $facility->photo_path,
                    'sort_order' => 0,
                ]);
                $facility->load('photos');
            }

            $removePhotoIds = array_values(array_unique(array_map('intval', $removePhotoIds)));
            $photosToRemove = $facility->photos->whereIn('id', $removePhotoIds);

            if ($photosToRemove->count() !== count($removePhotoIds)) {
                throw ValidationException::withMessages([
                    'remove_photo_ids' => 'One or more selected photos do not belong to this facility.',
                ]);
            }

            $retainedPhotos = $facility->photos->whereNotIn('id', $removePhotoIds)->values();

            if ($retainedPhotos->count() + count($newPhotoPaths) > 4) {
                throw ValidationException::withMessages([
                    'photos' => 'A facility can have a maximum of 4 photos.',
                ]);
            }

            $removedPaths = $photosToRemove->pluck('path')->all();
            $facility->photos()->whereIn('id', $removePhotoIds)->delete();

            foreach ($retainedPhotos as $sortOrder => $photo) {
                $photo->update(['sort_order' => $sortOrder]);
            }

            self::appendPhotos($facility, $newPhotoPaths, $retainedPhotos->count());

            $primaryPath = $retainedPhotos->first()?->path ?? $newPhotoPaths[0] ?? null;
            $facility->update([...$data, 'photo_path' => $primaryPath]);

            return $facility->refresh()->load('photos');
        });

        if ($facility === null) {
            return null;
        }

        self::deleteFiles($removedPaths);

        return self::toArray($facility, $user);
    }

    public static function delete(string $slug, User $user): void
    {
        $facility = self::queryForUser($user)->where('slug', $slug)->first();

        if ($facility === null) {
            return;
        }

        if ($facility->officialUses()->exists()) {
            throw ValidationException::withMessages(['facility' => 'This resource has Official Use history and cannot be deleted. Mark it unavailable instead.']);
        }

        $photoPaths = $facility->photos->pluck('path')
            ->push($facility->photo_path)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $facility->photos()->delete();
        $facility->delete();
        self::deleteFiles($photoPaths);
    }

    public static function findForUser(string $slug, User $user): ?array
    {
        $query = $user->role === 'user' ? Facility::query()->with('photos') : self::queryForUser($user);
        $facility = $query->where('slug', $slug)->first();

        return $facility ? self::toArray($facility, $user) : null;
    }

    private static function queryForUser(User $user)
    {
        $query = Facility::query()->with('photos');

        if ($user->role !== 'super_admin') {
            $query->where('barangay', $user->barangay);
        }

        return $query;
    }

    private static function toArray(Facility $facility, User $user): array
    {
        $isAvailable = $facility->status === 'Available';
        $currentReservation = $isAvailable ? self::currentReservation($facility) : null;
        $photos = $facility->photos
            ->map(fn ($photo) => [
                'id' => $photo->id,
                'path' => $photo->path,
                'url' => self::photoUrl($photo->path),
            ])
            ->filter(fn (array $photo) => $photo['url'] !== null)
            ->values();

        if (
            $facility->photo_path
            && ! $photos->contains('path', $facility->photo_path)
            && ($legacyUrl = self::photoUrl($facility->photo_path)) !== null
        ) {
            $photos->prepend([
                'id' => null,
                'path' => $facility->photo_path,
                'url' => $legacyUrl,
            ]);
        }

        $primaryPhoto = $photos->first();

        return [
            'id' => $facility->id,
            'reservation_access' => $facility->reservation_access,
            'can_reserve' => $facility->allowsReservationsBy($user),
            'hourly_rate' => $facility->hourly_rate,
            'barangay' => $facility->barangay,
            'slug' => $facility->slug,
            'name' => $facility->name,
            'description' => $facility->description,
            'list_description' => Str::limit($facility->description, 95),
            'photo_path' => $primaryPhoto['path'] ?? null,
            'photo_url' => $primaryPhoto['url'] ?? null,
            'photos' => $photos->all(),
            'category' => $facility->category,
            'capacity' => (int) $facility->capacity,
            'location' => $facility->location,
            'status' => $facility->status,
            'is_available' => $isAvailable,
            'display_status' => match (true) {
                ! $isAvailable => 'Unavailable',
                $currentReservation !== null => 'Currently in Use',
                default => 'Available',
            },
            'current_reservation' => $currentReservation ? [
                'start_time' => Carbon::parse($currentReservation->start_time)->format('g:i A'),
                'end_time' => Carbon::parse($currentReservation->end_time)->format('g:i A'),
                'end_date_label' => $currentReservation->period()->endDateLabel(),
            ] : null,
        ];
    }

    private static function appendPhotos(Facility $facility, array $photoPaths, int $startingOrder): void
    {
        foreach (array_values($photoPaths) as $offset => $path) {
            $facility->photos()->create([
                'path' => $path,
                'sort_order' => $startingOrder + $offset,
            ]);
        }
    }

    private static function photoUrl(?string $path): ?string
    {
        return $path && Storage::disk('public')->exists($path)
            ? '/storage/'.ltrim($path, '/')
            : null;
    }

    private static function deleteFiles(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }
    }

    private static function currentReservation(Facility $facility): ?Reservation
    {
        $now = now();

        return ReservationAvailability::acceptedReservations($facility->id, $now, $now)
            ->sortBy('start_time')->first(fn (Reservation $reservation) => $reservation->period()->contains($now));
    }
}
