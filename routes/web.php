<?php

use App\Modules\Catalog\Http\Controllers\AccountListingController;
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
*/

Route::middleware(['auth', EnsurePhoneIsVerified::class])->group(function (): void {
    Route::get('/add', [ListingCreateController::class, 'create'])->name('listing.create');
    Route::post('/add', [ListingCreateController::class, 'store'])->name('listing.store');
});

/*
|--------------------------------------------------------------------------
| لوحة المعلن
|--------------------------------------------------------------------------
|
| auth وحده — بلا EnsurePhoneIsVerified، بقصد.
|
| السبب: بوابة الهاتف تخصّ إنشاء محتوى جديد. أما من نشر قبل تشغيل
| البوابة، أو تغيّر رقمه، أو شُغِّلت البوابة بعد نشره — فمنعه من إدارة
| إعلاناته القائمة يتركه بإعلان حيّ لا يستطيع حذفه ولا تصحيح سعره،
| والمتضرّر هو المشتري الذي يتصل برقم قديم. حظر الإنشاء إجراء وقائي؛
| حظر الحذف عقوبة على المشتري.
|
| withTrashed على مسار الاستعادة فقط: الاستعادة تعمل على صف محذوف
| منطقياً بالتعريف، وبقية المسارات يجب أن تعيد 404 لإعلان محذوف.
|
| scopeBindings على مسار الصورة: يُلزم لارافل بحلّ {image} عبر علاقة
| images الخاصة بـ{listing}. بلا هذا القيد يقبل المسار معرّف صورة من
| إعلان آخر، فيحذف المعلن صور غيره بتغيير رقم في الرابط — والصلاحية
| على {listing} وحدها لا تكشف ذلك.
|
*/

Route::middleware('auth')->prefix('account')->name('account.')->group(function (): void {
    Route::get('/listings', [AccountListingController::class, 'index'])->name('listings');

    Route::get('/listings/{listing}/edit', [AccountListingController::class, 'edit'])->name('listings.edit');
    Route::put('/listings/{listing}', [AccountListingController::class, 'update'])->name('listings.update');

    Route::delete('/listings/{listing}', [AccountListingController::class, 'destroy'])->name('listings.destroy');
    Route::patch('/listings/{listing}/restore', [AccountListingController::class, 'restore'])
        ->name('listings.restore')
        ->withTrashed();

    Route::post('/listings/{listing}/renew', [AccountListingController::class, 'renew'])->name('listings.renew');

    Route::get('/listings/{listing}/stats', [AccountListingController::class, 'stats'])->name('listings.stats');

    Route::delete('/listings/{listing}/images/{image}', [AccountListingController::class, 'destroyImage'])
        ->name('listings.images.destroy')
        ->scopeBindings();
});

/*
|--------------------------------------------------------------------------
| الهوية
|--------------------------------------------------------------------------
*/

require base_path('app/Modules/Identity/Routes/web.php');