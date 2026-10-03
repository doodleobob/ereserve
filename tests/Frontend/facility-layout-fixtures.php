<?php

use App\Http\Controllers\FacilityController;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array']);
DB::purge('sqlite');
Artisan::call('migrate', ['--force' => true]);
URL::forceRootUrl('https://ereserve.test');
View::share('errors', new ViewErrorBag);
$admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
$resident = User::factory()->create(['role' => 'user', 'barangay' => 'Taft']);
$superAdmin = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Washington']);
$directory = __DIR__.'/../../storage/app/facility-layout-check';
if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}
config(['filesystems.disks.public.root' => $directory.'/uploads']);
Carbon::setTestNow('2026-10-03 10:00:00');

// Different real records exercise titles, descriptions, resource types and badges.
$names = ['Barangay Hall Main Function Room', 'Chairs', str_repeat('LongResourceName', 10), 'Court', 'Sound Equipment', 'Meeting Room'];
foreach ($names as $index => $name) {
    $facility = Facility::create([
        'barangay' => 'Taft', 'slug' => 'layout-resource-'.$index, 'name' => $name,
        'category' => $index % 2 ? 'Equipment' : 'Facility',
        'description' => $index === 0 ? str_repeat('Full description for community events. ', 13) : ($index === 2 ? str_repeat('UnbrokenDescription', 25) : 'Community resource.'),
        'capacity' => 15 + $index, 'location' => $index === 2 ? str_repeat('LongLocation', 12) : 'Barangay Taft',
        'status' => $index === 1 ? 'Unavailable' : 'Available', 'hourly_rate' => 100,
        'reservation_access' => $index % 2 ? 'residents_only' : 'all_registered_users',
    ]);
    if ($index === 0) {
        // A tiny existing-style local photo avoids external assets during rendering.
        file_put_contents($directory.'/photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="300"><rect width="600" height="300" fill="#dcecff"/><path d="M100 250V100h400v150" fill="#dcfae8" stroke="#155dfc" stroke-width="8"/></svg>');
        Storage::disk('public')->put('facilities/layout-photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));
        $facility->update(['photo_path' => 'facilities/layout-photo.png']);
        $facility->photos()->create(['path' => 'facilities/layout-photo.png', 'sort_order' => 0]);
        Storage::disk('public')->put('facilities/portrait.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="600"><rect width="300" height="600" fill="#dcfae8"/><rect x="20" y="20" width="260" height="560" fill="none" stroke="#155dfc" stroke-width="8"/></svg>');
        $facility->photos()->create(['path' => 'facilities/portrait.svg', 'sort_order' => 1]);
    }
    if ($index === 4) {
        Storage::disk('public')->put('facilities/single.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="400"><rect width="800" height="400" fill="#dcecff"/></svg>');
        $facility->update(['photo_path' => 'facilities/single.svg']);
    }
    if ($index === 3) {
        Reservation::create(['user_id' => $resident->id, 'barangay' => 'Taft', 'facility_id' => $facility->id, 'facility_slug' => $facility->slug, 'facility_name' => $facility->name, 'category' => $facility->category, 'location' => $facility->location, 'reservation_date' => today()->toDateString(), 'start_time' => now()->subHour()->format('H:i'), 'end_time' => now()->addHour()->format('H:i'), 'purpose' => 'Current use', 'attendees' => 5, 'status' => 'accepted', 'total_payment' => 100, 'hourly_rate_snapshot' => 100]);
    }
}
foreach (['admin' => $admin, 'super-admin' => $superAdmin, 'resident' => $resident] as $name => $viewer) {
    Auth::setUser($viewer);
    $request = Request::create('https://ereserve.test/facilities');
    $request->setUserResolver(fn () => $viewer);
    $app->instance('request', $request);
    file_put_contents($directory.'/'.$name.'.html', (new FacilityController)->index($request)->render());
    if ($name === 'resident') {
        file_put_contents($directory.'/resident-detail.html', (new FacilityController)->show($request, 'layout-resource-0')->render());
    }
}
$outsideResident = User::factory()->create(['role' => 'user', 'barangay' => 'Washington']);
Auth::setUser($outsideResident);
$request = Request::create('https://ereserve.test/facilities?barangay=Taft');
$request->setUserResolver(fn () => $outsideResident);
$app->instance('request', $request);
file_put_contents($directory.'/resident-restricted.html', (new FacilityController)->index($request)->render());
echo "Facility/Equipment card fixtures rendered from isolated in-memory SQLite.\n";
