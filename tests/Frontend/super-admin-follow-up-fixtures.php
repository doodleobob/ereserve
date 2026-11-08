<?php

// Render only the follow-up review pages using isolated in-memory SQLite.
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ResidentController;
use App\Models\AuditLogEntry;
use App\Models\User;
use App\Support\AdminPermission;
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
config([
    'app.env' => 'testing', 'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
    'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array',
]);
DB::purge('sqlite');
Artisan::call('migrate', ['--force' => true]);
URL::forceRootUrl('https://ereserve.test');
View::share('errors', new ViewErrorBag);

$super = User::factory()->create(['name' => 'Browser Review Super Admin', 'role' => 'super_admin', 'barangay' => 'Taft']);
$admin = User::factory()->create(['name' => 'Browser Review Admin', 'role' => 'admin', 'barangay' => 'Washington']);
$resident = User::factory()->create(['name' => 'Browser Review Resident', 'role' => 'user', 'barangay' => 'Washington']);
User::factory()->create(['name' => 'Cross Barangay Resident', 'role' => 'user', 'barangay' => 'Taft']);
$admin->restrictedPermissions()->create(['permission' => AdminPermission::RESERVATIONS_VIEW->value]);
foreach (range(1, 30) as $index) {
    AuditLogEntry::create([
        'actor_id' => $admin->id, 'actor_name' => $admin->name, 'actor_role' => 'admin',
        'action' => 'admin.status_changed', 'target_type' => 'User', 'target_id' => (string) $index,
        'barangay' => $admin->barangay, 'description' => 'Changed a Barangay Admin account status',
        'before_values' => ['is_active' => true], 'after_values' => ['is_active' => false], 'occurred_at' => now()->subMinutes($index),
    ]);
}

$directory = __DIR__.'/../../storage/app/final-super-admin-browser-check';
if (! is_dir($directory)) mkdir($directory, 0777, true);
$render = static function (string $name, object $response) use ($directory): void {
    $html = $response instanceof Illuminate\Contracts\View\View ? $response->render() : $response->getContent();
    file_put_contents($directory.'/'.$name.'.html', $html);
};
$request = static function (string $uri, User $user) use ($app): Request {
    $request = Request::create('https://ereserve.test'.$uri);
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession($app['session']->driver());
    $app->instance('request', $request);
    Auth::setUser($user);
    return $request;
};

$render('admin-edit', (new AdminController)->edit($request('/admins/'.$admin->id.'/edit', $super), (string) $admin->id));
$render('permission-editor', (new AdminController)->permissions($request('/admins/'.$admin->id.'/permissions', $super), (string) $admin->id));
$render('admin-management', (new AdminController)->index($request('/admins', $super)));
$render('resident-management', (new ResidentController)->index($request('/admin/residents', $super)));
$render('audit-index', (new AuditLogController)->index($request('/super-admin/audit-logs', $super)));
$render('audit-show', (new AuditLogController)->show(AuditLogEntry::firstOrFail()));
Auth::forgetGuards();
echo "Rendered Super Admin follow-up pages using isolated in-memory SQLite.\n";
