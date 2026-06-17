<?php

use App\Http\Middleware\ContractorAuthenticated;
use App\Livewire\Cabinet\Catalog;
use App\Livewire\Cabinet\Dashboard;
use App\Livewire\Cabinet\Login;
use App\Livewire\Cabinet\ProductDetail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('cabinet.catalog'));

// Cabinet authentication routes
Route::middleware('guest:contractor')->group(function () {
    Route::get('/login', Login::class)->name('cabinet.login');
});

Route::post('/logout', function () {
    Auth::guard('contractor')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('cabinet.login');
})->name('cabinet.logout');

// Protected cabinet routes
Route::middleware(ContractorAuthenticated::class)->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('cabinet.dashboard');
    Route::get('/catalog', Catalog::class)->name('cabinet.catalog');
    Route::get('/catalog/{product}', ProductDetail::class)->name('cabinet.catalog.show');
});
