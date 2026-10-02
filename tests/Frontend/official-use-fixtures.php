<?php

use App\Http\Controllers\OfficialUseController;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Support\OfficialUseScheduling;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

// Render browser fixtures only against an isolated, temporary SQLite database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array']);
DB::purge('sqlite');
Artisan::call('migrate', ['--force' => true]);
URL::forceRootUrl('https://ereserve.test');

$admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
$resident = User::factory()->create(['barangay' => 'Taft']);
$resource = Facility::create(['barangay' => 'Taft', 'slug' => 'preview-court', 'name' => 'Preview Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 50, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100]);
$equipment = Facility::create(['barangay' => 'Taft', 'slug' => 'preview-equipment', 'name' => 'Preview Equipment', 'category' => 'Equipment', 'description' => 'Equipment', 'capacity' => 50, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100]);
$date = today()->addDays(3)->toDateString();
$reservation = Reservation::create(['user_id' => $resident->id, 'barangay' => 'Taft', 'facility_id' => $resource->id, 'facility_slug' => $resource->slug, 'facility_name' => $resource->name, 'category' => 'Facility', 'location' => 'Taft', 'reservation_date' => $date, 'start_time' => '14:00', 'end_time' => '16:00', 'purpose' => 'Workshop', 'attendees' => 5, 'status' => 'accepted', 'total_payment' => 500, 'hourly_rate_snapshot' => 100]);

$directory = __DIR__.'/../../storage/app/official-use-ui-check';
if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}
$render = function (string $name, User $viewer) use ($app, $directory) {
    Auth::setUser($viewer);
    $request = Request::create('https://ereserve.test/official-uses');
    $request->setUserResolver(fn () => $viewer);
    $app->instance('request', $request);
    $view = (new OfficialUseController)->index($request);
    file_put_contents($directory.'/'.$name.'.html', $view->render());
};
$render('empty', $admin);
$use = DB::transaction(fn () => OfficialUseScheduling::create($resource, ['date' => $date, 'start_time' => '13:00', 'end_time' => '17:00', 'purpose' => 'Barangay Assembly'], $admin));
$render('created', $admin);
DB::transaction(fn () => OfficialUseScheduling::update($use, $equipment, ['date' => today()->addDays(4)->toDateString(), 'start_time' => '14:00', 'end_time' => '18:00', 'purpose' => 'Updated Government Program'], $admin));
$render('updated', $admin);
echo "Official Use browser fixtures rendered from in-memory SQLite.\n";
