<?php

use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\PhoneVerificationController;
use App\Modules\Identity\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

/*
| مسارات وحدة الهوية — تُحمَّل بـrequire من routes/web.php.
|
| لماذا لا موفّر خدمة: تسجيل موفّر يفرض تعديلاً يدوياً في
| bootstrap/providers.php، والوحدة بهذا الشكل مكتفية بذاتها.
| المقايضة: الربط في موفّر أكثر اصطلاحية.
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