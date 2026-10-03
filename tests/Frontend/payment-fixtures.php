<?php

use App\Http\Controllers\PaymentController;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array']);
DB::purge('sqlite');
Artisan::call('migrate', ['--force' => true]);
URL::forceRootUrl('https://ereserve.test');
$admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
$resident = User::factory()->create(['name' => 'Preview Resident', 'barangay' => 'Taft']);
$resource = Facility::create(['barangay' => 'Taft', 'slug' => 'preview-court', 'name' => 'Preview Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 50, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100]);
$directory = __DIR__.'/../../storage/app/payment-ui-check';
if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}
Auth::setUser($admin);
$requestFor = function (array $query = []) use ($app, $admin) {
    $request = Request::create('https://ereserve.test/payments', 'GET', $query);
    $request->setUserResolver(fn () => $admin);
    $app->instance('request', $request);

    return $request;
};
$render = fn (string $name) => file_put_contents($directory.'/'.$name.'.html', (new PaymentController)->index($requestFor())->render());
$render('empty');
$r = Reservation::create(['user_id' => $resident->id, 'barangay' => 'Taft', 'facility_id' => $resource->id, 'facility_slug' => $resource->slug, 'facility_name' => $resource->name, 'category' => 'Facility', 'location' => 'Taft', 'reservation_date' => today()->addDays(3)->toDateString(), 'start_time' => '13:00', 'end_time' => '17:00', 'purpose' => 'Workshop', 'attendees' => 5, 'status' => 'cancelled', 'total_payment' => 500, 'hourly_rate_snapshot' => 100]);
$payment = $r->payment()->create(['amount' => 75, 'payment_status' => 'paid']);
$render('paid');
$request = $requestFor(['payment_status' => 'refunded', 'receipt_confirmed' => 1]);
(new PaymentController)->update($request, $payment);
$render('refunded');
foreach (['pdf', 'xlsx', 'csv'] as $format) {
    $response = (new PaymentController)->export($requestFor(['format' => $format]));
    ob_start();
    $response->sendContent();
    $bytes = ob_get_clean();
    file_put_contents($directory.'/report.'.$format, $bytes);
}
echo "Payment fixtures and three reports generated from in-memory SQLite.\n";
