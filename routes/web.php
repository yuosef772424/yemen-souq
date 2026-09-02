<?php

use App\Modules\Catalog\Http\Controllers\AccountListingController;
use App\Modules\Catalog\Http\Controllers\ContactRevealController;
use App\Modules\Catalog\Http\Controllers\ListingController;
use App\Modules\Catalog\Http\Controllers\ListingCreateController;
use App\Modules\Engagement\Http\Controllers\ConversationController;
use App\Modules\Engagement\Http\Controllers\FavoriteController;
use App\Modules\Engagement\Http\Controllers\ListingQuestionController;
use App\Modules\Identity\Http\Middleware\EnsurePhoneIsVerified;
use App\Modules\Search\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| عام
|--------------------------------------------------------------------------
*/

Route::get('/', SearchController::class)->name('search');

/*
| صفحة الانقطاع — يقدّمها عامل الخدمة حين تسقط الشبكة أثناء تنقّل.
|
| مسارٌ حقيقي لا ملف ثابت في public: تُبنى بنفس القالب والهوية اللونية،
| فلا تظهر صفحة غريبة عن الموقع في أسوأ لحظة يمرّ بها المستخدم. وهي
| السبب الوحيد الذي يجعل التطبيق المثبَّت يبدو حيّاً بلا شبكة.
*/
Route::view('/offline', 'shared::offline')->name('offline');

Route::get('/listing/{listing}', [ListingController::class, 'show'])->name('listing.show');

Route::post('/listing/{listing}/contact', ContactRevealController::class)->name('listing.contact');

/*
|--------------------------------------------------------------------------
| النشر — يتطلب حساباً ورقماً موثَّقاً
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', EnsurePhoneIsVerified::class])->group(function (): void {
    Route::get('/add', [ListingCreateController::class, 'create'])->name('listing.create');
    Route::post('/add', [ListingCreateController::class, 'store'])->name('listing.store');
});

/*
|--------------------------------------------------------------------------
| التفاعل — يتطلب حساباً فقط
|--------------------------------------------------------------------------
|
| بلا EnsurePhoneIsVerified بقصد، وبنفس منطق لوحة المعلن: بوابة الهاتف
| تخصّ *إنشاء* محتوى معروض للسوق. أما سؤال البائع أو حفظ إعلان في
| المفضلة فسلوك مشترٍ — وحظره يحرم المنصة من الطرف الذي تحتاجه أكثر.
|
| والسبام يُحسب له هنا بحدّ زمني على الإرسال لا ببوابة توثيق: البوابة
| تمنع المشتري الجدّي مثل ما تمنع المُزعِج.
|
*/

Route::middleware('auth')->group(function (): void {
    Route::post('/listing/{listing}/favorite', [FavoriteController::class, 'toggle'])
        ->name('listing.favorite');

    Route::post('/listing/{listing}/questions', [ListingQuestionController::class, 'store'])
        ->name('listing.questions.store');

    Route::post('/questions/{question}/answer', [ListingQuestionController::class, 'answer'])
        ->name('questions.answer');

    Route::delete('/questions/{question}', [ListingQuestionController::class, 'destroy'])
        ->name('questions.destroy');

    // بدء المحادثة من صفحة الإعلان
    Route::post('/listing/{listing}/messages', [ConversationController::class, 'store'])
        ->name('listing.messages.store');
});

/*
|--------------------------------------------------------------------------
| لوحة الحساب
|--------------------------------------------------------------------------
|
| withTrashed على مسار الاستعادة فقط، وscopeBindings على مسار الصورة —
| بلا الثاني يقبل المسار معرّف صورة من إعلان آخر.
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

    // زرّ واحد يبدّل الحالة — القرار من قاعدة البيانات لا من المتصفح
    Route::post('/listings/{listing}/sold', [AccountListingController::class, 'toggleSold'])
        ->name('listings.sold');

    Route::get('/listings/{listing}/stats', [AccountListingController::class, 'stats'])->name('listings.stats');

    Route::delete('/listings/{listing}/images/{image}', [AccountListingController::class, 'destroyImage'])
        ->name('listings.images.destroy')
        ->scopeBindings();

    // التفاعل
    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites');
    Route::get('/messages', [ConversationController::class, 'index'])->name('messages');
    Route::get('/messages/{conversation}', [ConversationController::class, 'show'])->name('messages.show');
    Route::post('/messages/{conversation}', [ConversationController::class, 'reply'])->name('messages.reply');
    Route::get('/questions', [ListingQuestionController::class, 'inbox'])->name('questions');
});

/*
|--------------------------------------------------------------------------
| مسارات الوحدات
|--------------------------------------------------------------------------
|
| لا require هنا: ModuleServiceProvider يمسح app/Modules ويسجّل ملفات
| مجلد Routes في كل وحدة ضمن مجموعة web. الهوية والتحكم والتجار
| والإبلاغ تُحمَّل من هناك.
|
*/
