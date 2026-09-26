<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\SuperAdminAnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\FacilityController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationPageController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\TwoFactorLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::view('/offline', 'offline')->name('offline');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');
    Route::get('/two-factor-challenge', [TwoFactorLoginController::class, 'show'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorLoginController::class, 'verify'])->middleware('throttle:10,1')->name('two-factor.verify');
    Route::post('/two-factor-challenge/resend', [TwoFactorLoginController::class, 'resend'])->middleware('throttle:5,15')->name('two-factor.resend');
    Route::post('/two-factor-challenge/cancel', [TwoFactorLoginController::class, 'cancel'])->name('two-factor.cancel');
});

Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:10,1')->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')->name('verification.send');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('/settings/security', '/profile')->name('settings.security');
    Route::post('/settings/security/two-factor', [SecurityController::class, 'setup'])->middleware('throttle:5,1')->name('two-factor.setup');
    Route::post('/settings/security/two-factor/confirm', [SecurityController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::post('/settings/security/two-factor/resend', [SecurityController::class, 'resend'])->middleware('throttle:5,15')->name('two-factor.setup.resend');
    Route::post('/settings/security/two-factor/cancel', [SecurityController::class, 'cancelSetup'])->name('two-factor.setup.cancel');
    Route::delete('/settings/security/two-factor', [SecurityController::class, 'disable'])->middleware('throttle:5,1')->name('two-factor.disable');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/analytics', AnalyticsController::class)->name('analytics');
    Route::get('/super-admin/analytics', SuperAdminAnalyticsController::class)->name('super-admin.analytics');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::patch('/reservations/{reservation}/payment', [ReservationController::class, 'payment'])->name('reservations.payment');

    Route::get('/facilities', [FacilityController::class, 'index'])->name('facilities');
    Route::post('/facilities', [FacilityController::class, 'store'])->name('facilities.store');
    Route::get('/facilities/{slug}', [FacilityController::class, 'show'])->name('facilities.show');
    Route::patch('/facilities/{slug}', [FacilityController::class, 'update'])->name('facilities.update');
    Route::delete('/facilities/{slug}', [FacilityController::class, 'destroy'])->name('facilities.destroy');
    Route::post('/facilities/{slug}/reservations', [ReservationController::class, 'store'])->name('reservations.store');
    Route::get('/reservations', [ReservationPageController::class, 'index'])->name('reservations.index');
    Route::post('/reservations/{reservation}/accept', [ReservationController::class, 'accept'])->name('reservations.accept');
    Route::post('/reservations/{reservation}/reject', [ReservationController::class, 'reject'])->name('reservations.reject');
    Route::get('/admins/create', [AdminController::class, 'create'])->name('admins.create');
    Route::post('/admins', [AdminController::class, 'store'])->name('admins.store');
    Route::get('/admins', [AdminController::class, 'index'])->name('admins.index');
    Route::get('/admins/{account}', [AdminController::class, 'show'])->name('admins.show');
    Route::patch('/admins/{account}/status', [AdminController::class, 'updateStatus'])->name('admins.status');
    Route::get('/admin/residents', [ResidentController::class, 'index'])->name('residents.index');
    Route::get('/admin/residents/{account}', [ResidentController::class, 'show'])->name('residents.show');
    Route::patch('/admin/residents/{account}/status', [ResidentController::class, 'updateStatus'])->name('residents.status');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

});
