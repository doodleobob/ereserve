<?php

namespace App\Support;

use Illuminate\Support\Collection;

class FacilityCatalog
{
    public static function all(): Collection
    {
        return collect([
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
        ]);
    }

    public static function find(string $slug): ?array
    {
        return self::all()->firstWhere('slug', $slug);
    }
}
