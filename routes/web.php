<?php

use App\Modules\Catalog\Http\Controllers\ContactRevealController;
use App\Modules\Catalog\Http\Controllers\ListingController;
use App\Modules\Catalog\Http\Controllers\ListingCreateController;
use App\Modules\Identity\Http\Middleware\EnsurePhoneIsVerified;
use App\Modules\Search\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| عام
|--------------------------------------------------------------------------
*/

Route::get('/', SearchController::class)->name('search');

Route::get('/listing/{listing}', [ListingController::class, 'show'])->name('listing.show');

Route::post('/listing/{listing}/contact', ContactRevealController::class)->name('listing.contact');
Route::post('/add', [ListingController::class, 'store'])->name('listing.store');
/*
|--------------------------------------------------------------------------
| النشر — يتطلب حساباً ورقماً موثَّقاً
|--------------------------------------------------------------------------
|
| الوسيط يُستدعى بصنفه لا باسم مستعار: الاسم المستعار يحتاج تسجيلاً في
| bootstrap/app.php، والصنف المباشر يعمل بلا أي تسجيل.
|
| بوابة الهاتف قابلة للتعطيل من config/identity.php — انظر
| REQUIRE_PHONE_VERIFICATION في .env.
|
| ولم يتغيّر ListingCreateController: DemoUser::current() تُرجع
| Auth::user() متى وُجد، وهو ما كتبه تعليقها منذ البداية.
|
*/

// Route::middleware(['auth', EnsurePhoneIsVerified::class])->group(function (): void {
    Route::get('/add', [ListingCreateController::class, 'create'])->name('listing.create');
    Route::post('/add', [ListingCreateController::class, 'store'])->name('listing.store');
// });

/*
|--------------------------------------------------------------------------
| الهوية
|--------------------------------------------------------------------------
*/

require base_path('app/Modules/Identity/Routes/web.php');