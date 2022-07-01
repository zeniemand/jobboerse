<?php

use App\Http\Controllers\ListingController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/', [ListingController::class, 'index'])->name('listings.index');

Route::get('/new', [ListingController::class, 'create'])->name('listings.create');

Route::post('/new', [ListingController::class, 'store'])->name('listings.store');

Route::get('/dashboard', function (Request $request) {
    return view('dashboard', [
        'listings' => $request->user()->listings
    ]);
})->middleware(['auth'])->name('dashboard');

require __DIR__.'/auth.php';


Route::get('/{listing}', [ListingController::class, 'show'])
    ->name('listing.show');

Route::get('/{listing}/apply', [ListingController::class, 'apply'])
    ->name('listings.apply');
