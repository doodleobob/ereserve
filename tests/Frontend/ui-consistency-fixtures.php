<?php

// Render all major UI surfaces without touching the configured application DB.
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacilityController;
use App\Http\Controllers\OfficialUseController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReservationPageController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\SuperAdminAnalyticsController;
use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
DB::purge('sqlite');
Artisan::call('migrate', ['--force' => true]);
URL::forceRootUrl('https://ereserve.test');
View::share('errors', new ViewErrorBag);
$directory = __DIR__.'/../../storage/app/ui-consistency-check';
if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}
$users = [];
foreach (['resident' => 'user', 'admin' => 'admin', 'super-admin' => 'super_admin'] as $label => $role) {
    $users[$label] = User::factory()->create(['name' => ucfirst($label).' Preview', 'role' => $role, 'barangay' => 'Taft']);
}
$facility = Facility::create(['barangay' => 'Taft', 'slug' => 'preview-court', 'name' => 'Community Court', 'category' => 'Facility', 'description' => 'Community events and activities.', 'capacity' => 50, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100, 'reservation_access' => 'all_registered_users']);
foreach (['pending', 'accepted', 'rejected', 'cancelled'] as $index => $status) {
    $reservation = Reservation::create(['user_id' => $users['resident']->id, 'barangay' => 'Taft', 'facility_id' => $facility->id, 'facility_slug' => $facility->slug, 'facility_name' => $facility->name, 'category' => 'Facility', 'location' => 'Taft', 'reservation_date' => today()->addDays($index + 3)->toDateString(), 'start_time' => '13:00', 'end_time' => '17:00', 'purpose' => 'Community event', 'attendees' => 5, 'status' => $status, 'total_payment' => 400, 'hourly_rate_snapshot' => 100]);
    $reservation->payment()->create(['amount' => 400, 'payment_status' => 'paid']);
}
foreach (['active', 'conflict', 'cancelled'] as $index => $status) {
    OfficialUse::create(['barangay' => 'Taft', 'facility_id' => $facility->id, 'date' => today()->addDays($index + 10)->toDateString(), 'start_time' => '09:00', 'end_time' => '12:00', 'purpose' => 'Barangay meeting', 'status' => $status, 'created_by' => $users['admin']->id]);
}
$write = function (string $name, $response) use ($directory): void {
    $html = $response instanceof \Illuminate\Contracts\View\View ? $response->render() : $response->getContent();
    file_put_contents($directory.'/'.$name.'.html', $html);
};
foreach ($users as $role => $user) {
    Auth::setUser($user);
    $pages = ['dashboard' => [DashboardController::class, '__invoke'], 'calendar' => [DashboardController::class, 'calendar'], 'facilities' => [FacilityController::class, 'index'], 'facility-details' => [FacilityController::class, 'show'], 'reservations' => [ReservationPageController::class, 'index'], 'profile' => [ProfileController::class, 'edit']];
    if ($role !== 'resident') {
        $pages += ['official-use' => [OfficialUseController::class, 'index'], 'payments' => [PaymentController::class, 'index'], 'analytics' => [$role === 'admin' ? AnalyticsController::class : SuperAdminAnalyticsController::class, '__invoke']];
        $pages += $role === 'admin' ? ['accounts' => [ResidentController::class, 'index']] : ['accounts' => [AdminController::class, 'index'], 'create-admin' => [AdminController::class, 'create']];
    }
    foreach ($pages as $page => [$class, $method]) {
        $query = $page === 'calendar' ? ['barangay' => 'Taft', 'type' => 'facility', 'facility' => $facility->slug] : [];
        $request = Request::create('https://ereserve.test/'.$page, 'GET', $query);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($app['session']->driver());
        $app->instance('request', $request);
        $write($role.'-'.$page, $page === 'facility-details' ? (new $class)->$method($request, $facility->slug) : (new $class)->$method($request));
    }
    if ($role === 'admin') {
        $request = Request::create('https://ereserve.test/admin/residents', 'GET', ['search' => 'no-matching-account']);
        $request->setUserResolver(fn () => $user);
        $app->instance('request', $request);
        $write('admin-accounts-empty', (new ResidentController)->index($request));
    }
}
Auth::forgetGuards();
foreach (['login', 'register', 'forgot-password', 'reset-password', 'two-factor-challenge', 'verify-email'] as $page) {
    Auth::setUser($users['resident']);
    $request = Request::create('https://ereserve.test/'.$page);
    $request->setLaravelSession($app['session']->driver());
    $app->instance('request', $request);
    $data = match ($page) {
        'register' => ['barangays' => \App\Support\Barangays::ALL],
        'reset-password' => ['email' => 'preview@example.test', 'token' => 'preview-token'],
        'two-factor-challenge' => ['user' => $users['resident']],
        default => [],
    };
    $write('auth-'.$page, view('auth.'.$page, $data));
}
echo "Rendered role and authentication pages with isolated in-memory SQLite.\n";
