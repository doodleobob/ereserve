<?php

use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationPageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// This renderer creates only in-memory SQLite fixtures; it never uses the working database.
require __DIR__.'/resource-usage-fixtures.php';

$equipment->update(['name' => 'Plastic Chairs '.str_repeat('LongEquipmentName', 8)]);
$pending = $booking($equipment, ['status' => 'pending', 'quantity' => 80, 'reservation_date' => today()->addDay()->toDateString(), 'hourly_rate_snapshot' => 100]);
$directory = __DIR__.'/../../storage/app/equipment-summary-check';
if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}
$responses = [];
foreach (['available' => 80, 'insufficient' => 140] as $label => $quantity) {
    $pending->update(['quantity' => $quantity]);
    Auth::setUser($users['admin']);
    $request = Request::create('https://ereserve.test/reservations/'.$pending->id.'/equipment-availability');
    $request->setUserResolver(fn () => $users['admin']);
    $app->instance('request', $request);
    $responses[$label] = app(ReservationController::class)->equipmentAvailability($request, $pending->fresh())->getData(true);
}
$pending->update(['quantity' => 80]);
file_put_contents($directory.'/responses.json', json_encode($responses, JSON_THROW_ON_ERROR));
foreach (['admin', 'super-admin'] as $role) {
    $user = $users[$role];
    Auth::setUser($user);
    $request = Request::create('https://ereserve.test/reservations', 'GET', ['per_page' => 100]);
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession($app['session']->driver());
    $app->instance('request', $request);
    file_put_contents($directory.'/'.$role.'.html', app(ReservationPageController::class)->index($request)->render());
}
echo "Acceptance summary fixtures rendered using isolated SQLite.\n";
