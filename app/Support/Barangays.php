<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\User;

class Barangays
{
    public const DEFAULT = 'Washington';

    public const ALL = [
        'Alang-alang',
        'Alegria',
        'Anomar',
        'Aurora',
        'Balibayon',
        'Baybay',
        'Bilabid',
        'Bitaugan',
        'Bonifacio',
        'Buenavista',
        'Cabongbongan',
        'Cagniog',
        'Cagutsan',
        'Canlanipa',
        'Cantiasay',
        'Capalayan',
        'Catadman',
        'Danao',
        'Danawan',
        'Day-asan',
        'Ipil',
        'Libuac',
        'Lipata',
        'Lisondra',
        'Luna',
        'Mabini',
        'Mabua',
        'Manyagao',
        'Mapawa',
        'Mat-i',
        'Nabago',
        'Nonoc',
        'Orok',
        'Poctoy',
        'Punta Bilar',
        'Quezon',
        'Rizal',
        'Sabang',
        'San Isidro',
        'San Jose',
        'San Juan',
        'San Pedro',
        'San Roque',
        'Serna',
        'Sidlakan',
        'Silop',
        'Sugbay',
        'Sukailang',
        'Taft',
        'Talisay',
        'Togbongon',
        'Trinidad',
        'Washington',
        'Zaragoza',
    ];

    /** Return supported barangay names represented by persisted accounts or facilities. */
    public static function persisted(): array
    {
        return User::query()->whereNotNull('barangay')->distinct()->pluck('barangay')
            ->merge(Facility::query()->whereNotNull('barangay')->distinct()->pluck('barangay'))
            ->filter(fn ($barangay) => in_array($barangay, self::ALL, true))
            ->unique()->sort()->values()->all();
    }
}
