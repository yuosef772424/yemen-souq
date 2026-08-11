<?php

use App\Modules\Identity\Http\Controllers\LoginController;
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
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/verify-phone', [PhoneVerificationController::class, 'show'])->name('phone.verify');
    Route::post('/verify-phone', [PhoneVerificationController::class, 'verify']);
    Route::post('/verify-phone/resend', [PhoneVerificationController::class, 'resend'])->name('phone.resend');
});