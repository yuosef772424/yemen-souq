<?php

use App\Modules\Catalog\Http\Controllers\ListingController;
use App\Modules\Search\Http\Controllers\SearchController;
use App\Modules\Catalog\Http\Controllers\ListingCreateController;
use App\Modules\Catalog\Http\Controllers\ContactRevealController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/', SearchController::class)->name('search');

Route::get('/listing/{listing}', [ListingController::class, 'show'])->name('listing.show');

Route::get('/add', [ListingCreateController::class, 'create'])->name('listing.create');
Route::post('/add', [ListingCreateController::class, 'store'])->name('listing.store');

Route::post('/listing/{listing}/contact', ContactRevealController::class)->name('listing.contact');
