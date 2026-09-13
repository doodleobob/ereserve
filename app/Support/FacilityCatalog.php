<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FacilityCatalog
{
    public static function all(): Collection
    {
        $baseItems = collect(self::baseItems());
        $deletedSlugs = collect(session('facility_deleted_slugs', []));
        $updatedItems = collect(session('facility_updated_items', []));
        $customItems = collect(session('facility_custom_items', []));

        return $baseItems
            ->reject(fn (array $item) => $deletedSlugs->contains($item['slug']))
            ->map(fn (array $item) => array_merge($item, $updatedItems->get($item['slug'], [])))
            ->merge($customItems)
            ->values();
    }

    public static function create(array $data): array
    {
        $item = self::formatItem($data);
        $existingSlugs = self::all()->pluck('slug')->all();
        $baseSlug = $item['slug'];
        $counter = 2;

        while (in_array($item['slug'], $existingSlugs, true)) {
            $item['slug'] = $baseSlug.'-'.$counter;
            $counter++;
        }

        session(['facility_custom_items' => [
            ...session('facility_custom_items', []),
            $item,
        ]]);

        return $item;
    }

    public static function update(string $slug, array $data): ?array
    {
        $current = self::find($slug);

        if ($current === null) {
            return null;
        }

        $item = array_merge($current, self::formatItem($data, $slug));
        $customItems = collect(session('facility_custom_items', []));
        $customIndex = $customItems->search(fn (array $item) => $item['slug'] === $slug);

        if ($customIndex !== false) {
            $customItems = $customItems->values();
            $customItems[$customIndex] = $item;
            session(['facility_custom_items' => $customItems->all()]);

            return $item;
        }

        $updatedItems = session('facility_updated_items', []);
        $updatedItems[$slug] = $item;
        session(['facility_updated_items' => $updatedItems]);

        return $item;
    }

    public static function delete(string $slug): void
    {
        $customItems = collect(session('facility_custom_items', []))
            ->reject(fn (array $item) => $item['slug'] === $slug)
            ->values()
            ->all();
        $deletedSlugs = collect(session('facility_deleted_slugs', []))
            ->push($slug)
            ->unique()
            ->values()
            ->all();

        session([
            'facility_custom_items' => $customItems,
            'facility_deleted_slugs' => $deletedSlugs,
        ]);
    }

    public static function find(string $slug): ?array
    {
        return self::all()->firstWhere('slug', $slug);
    }

    private static function formatItem(array $data, ?string $slug = null): array
    {
        return [
            'slug' => $slug ?? Str::slug($data['name']),
            'name' => $data['name'],
            'description' => $data['description'],
            'list_description' => Str::limit($data['description'], 95),
            'category' => $data['category'],
            'capacity' => (int) $data['capacity'],
            'location' => $data['location'],
            'status' => $data['status'],
        ];
    }

    private static function baseItems(): array
    {
        return [
            [
                'slug' => 'barangay-hall-main-function-room',
                'name' => 'Barangay Hall Main Function Room',
                'description' => 'Large function room suitable for community meetings, events, and gatherings. Equipped with air conditioning and basic audio system.',
                'list_description' => 'Large function room suitable for community meetings, events, and gatherings. Equipped with air conditioning...',
                'category' => 'Facility',
                'capacity' => 100,
                'location' => 'Barangay Washington',
                'status' => 'Available',
            ],
            [
                'slug' => 'multi-purpose-court',
                'name' => 'Multi-Purpose Court',
                'description' => 'Outdoor covered court for sports and recreational activities. Suitable for basketball, volleyball, and community events.',
                'list_description' => 'Outdoor covered court for sports and recreational activities. Suitable for basketball, volleyball, and...',
                'category' => 'Facility',
                'capacity' => 200,
                'location' => 'Barangay Washington',
                'status' => 'Available',
            ],
            [
                'slug' => 'conference-room-a',
                'name' => 'Conference Room A',
                'description' => 'Small conference room ideal for meetings and training sessions. Equipped with projector and whiteboard.',
                'list_description' => 'Small conference room ideal for meetings and training sessions. Equipped with projector and whiteboard.',
                'category' => 'Facility',
                'capacity' => 30,
                'location' => 'Barangay Washington',
                'status' => 'Available',
            ],
            [
                'slug' => 'sound-system',
                'name' => 'Sound System',
                'description' => 'Professional sound system with microphones and speakers for events and programs.',
                'list_description' => 'Professional sound system with microphones and speakers for events and programs.',
                'category' => 'Equipment',
                'capacity' => 1,
                'location' => 'Barangay Washington',
                'status' => 'Available',
            ],
            [
                'slug' => 'projector-and-screen',
                'name' => 'Projector and Screen',
                'description' => 'LCD projector with portable screen for presentations and seminars.',
                'list_description' => 'LCD projector with portable screen for presentations and seminars.',
                'category' => 'Equipment',
                'capacity' => 1,
                'location' => 'Barangay Washington',
                'status' => 'Available',
            ],
            [
                'slug' => 'tables-and-chairs-set',
                'name' => 'Tables and Chairs Set',
                'description' => 'Set of 20 tables and 100 monobloc chairs for events and gatherings.',
                'list_description' => 'Set of 20 tables and 100 monobloc chairs for events and gatherings.',
                'category' => 'Equipment',
                'capacity' => 100,
                'location' => 'Barangay Washington',
                'status' => 'Available',
            ],
        ];
    }
}
