<?php

use App\Modules\Identity\Http\Controllers\AccountController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\PhoneVerificationController;
use App\Modules\Identity\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

/*
| مسارات وحدة الهوية — يكتشفها ModuleServiceProvider تلقائياً.
|
| ولا موفّر خدمة للوحدة لأنها لا تحتاج واحداً: سائق التوثيق يُحلّ في
| PhoneVerificationService::sender() من config، لا من ربط في الحاوية.
| الاعتراض القديم — أن الموفّر يفرض تعديلاً يدوياً في
| bootstrap/providers.php — لم يعد قائماً بعد الاكتشاف التلقائي.
*/

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    /*
    | استعادة كلمة المرور — خطوتان.
    |
    | تحت guest بقصد: المسجَّل دخوله يغيّر كلمته من حسابه لا من هنا،
    | ومسارُ استعادةٍ مفتوحٌ لمن هو داخل الحساب أصلاً سطحُ هجومٍ بلا
    | حاجة — يكفي جهازٌ تُرك مفتوحاً.
    */
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send']);

    Route::get('/reset-password', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/verify-phone', [PhoneVerificationController::class, 'show'])->name('phone.verify');
    Route::post('/verify-phone', [PhoneVerificationController::class, 'verify']);
    Route::post('/verify-phone/resend', [PhoneVerificationController::class, 'resend'])->name('phone.resend');
});

/*
| إعدادات الحساب.
|
| تحت `account/` لتجاور «إعلاناتي» و«رسائلي» و«الإشعارات» — لوحة المستخدم
| مكان واحد، وصفحة إعداداتٍ في جذر الموقع تبدو صفحة نظام لا صفحة حساب.
|
| وبلا `EnsurePhoneIsVerified` بقصد وبمنطق مقلوب عن بقية المسارات: من
| كتب رقمه خطأً عند التسجيل **لا يستطيع** توثيقه، وحجب صفحة تصحيح الرقم
| خلف التوثيق يقفل عليه الباب من الداخل.
|
| وكل تغيير حالة بفعله الصريح: PUT للتعديل وDELETE للإغلاق. الإغلاق
| خصوصاً لا يقبل GET — رابطٌ يُغلق حساباً ينفّذه أي جالبٍ مسبق في
| المتصفح.
*/

Route::middleware('auth')->prefix('account')->name('account.')->group(function (): void {
    Route::get('/settings', [AccountController::class, 'edit'])->name('settings');

    Route::put('/settings/profile', [AccountController::class, 'updateProfile'])->name('profile.update');
    Route::put('/settings/password', [AccountController::class, 'updatePassword'])->name('password.update');
    Route::put('/settings/phone', [AccountController::class, 'updatePhone'])->name('phone.update');

    Route::delete('/settings', [AccountController::class, 'destroy'])->name('destroy');
});