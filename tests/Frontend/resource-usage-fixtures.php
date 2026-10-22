<?php

use App\Http\Controllers\ResourceUsageController;
use App\Models\Facility;
use App\Models\Reservation;
use App\Services\ResourceUsageManagement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// Reuse the existing renderer, which explicitly switches to in-memory SQLite before migrating.
require __DIR__.'/ui-consistency-fixtures.php';

$equipment = Facility::create(['barangay' => 'Taft', 'slug' => 'usage-chairs', 'name' => 'Plastic Chairs', 'category' => 'Equipment', 'description' => 'Serviceable chairs', 'total_quantity' => 160, 'location' => 'Taft', 'status' => 'Available']);
$booking = function ($resource, array $overrides = []) use ($users) {
    return Reservation::create(array_replace(['user_id' => $users['resident']->id, 'facility_id' => $resource->id, 'barangay' => 'Taft', 'facility_slug' => $resource->slug, 'facility_name' => $resource->name, 'category' => $resource->category, 'location' => 'Taft', 'reservation_date' => today()->subDay()->toDateString(), 'start_time' => '13:00', 'end_time' => '15:00', 'status' => 'accepted', 'purpose' => 'Usage preview', 'quantity' => $resource->isEquipment() ? 60 : null, 'attendees' => $resource->isEquipment() ? null : 20], $overrides));
};
Notification::fake();
$loan = $booking($equipment);
$service = app(ResourceUsageManagement::class);
foreach (['release' => 40, 'return' => 10] as $action => $quantity) {
    $service->record($users['admin'], $loan, ['action' => $action, 'quantity' => $quantity, 'confirmed' => 1, 'request_key' => (string) Str::uuid()]);
}
$visit = $booking($facility);
$service->record($users['admin'], $visit, ['action' => 'check_in', 'confirmed' => 1, 'request_key' => (string) Str::uuid()]);
$booking($equipment, ['reservation_date' => today()->addDay()->toDateString()]);
foreach (['admin', 'super-admin'] as $role) {
    $user = $users[$role];
    Auth::setUser($user);
    $request = Request::create('https://ereserve.test/resource-usage');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession($app['session']->driver());
    $app->instance('request', $request);
    $write($role.'-resource-usage', app(ResourceUsageController::class)->index($request));
}
echo "Resource Usage fixtures rendered using in-memory SQLite.\n";
