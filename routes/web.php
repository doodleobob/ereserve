<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacilityController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReservationPageController;
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::view('/offline', 'offline')->name('offline');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/facilities', [FacilityController::class, 'index'])->name('facilities');
    Route::post('/facilities', [FacilityController::class, 'store'])->name('facilities.store');
    Route::get('/facilities/{slug}', [FacilityController::class, 'show'])->name('facilities.show');
    Route::patch('/facilities/{slug}', [FacilityController::class, 'update'])->name('facilities.update');
    Route::delete('/facilities/{slug}', [FacilityController::class, 'destroy'])->name('facilities.destroy');
    Route::post('/facilities/{slug}/reservations', [ReservationController::class, 'store'])->name('reservations.store');
    Route::get('/reservations', [ReservationPageController::class, 'index'])->name('reservations.index');
    Route::post('/reservations/{reservation}/accept', [ReservationController::class, 'accept'])->name('reservations.accept');
    Route::post('/reservations/{reservation}/reject', [ReservationController::class, 'reject'])->name('reservations.reject');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
