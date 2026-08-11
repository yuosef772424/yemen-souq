<?php

use App\Modules\Admin\Http\Controllers\DashboardController;
use App\Modules\Admin\Http\Controllers\ListingModerationController;
use App\Modules\Admin\Http\Controllers\QuestionModerationController;
use App\Modules\Admin\Http\Controllers\ReportController;
use App\Modules\Admin\Http\Controllers\UserController;
use App\Modules\Admin\Http\Middleware\EnsureIsAdmin;
use App\Modules\Admin\Http\Middleware\EnsureIsStaff;
use Illuminate\Support\Facades\Route;

/*
| مسارات وحدة التحكم — تُحمَّل بـrequire من routes/web.php.
|
| الوسيط بالاسم الكامل للصنف لا باسم مختصر: الاسم المختصر يحتاج تسجيلاً
| في bootstrap/app.php، فيصير نسخ مجلد الوحدة غير كافٍ لتشغيلها.
|
| كل تغيير حالة بـPOST لا GET. ليس اصطلاحاً فقط: رابط GET يحذف إعلاناً
| يُنفَّذه أي زاحف يزور اللوحة، وينفّذه المتصفح مسبقاً عند التحويم.
|
| وwithTrashed على مسارَي الاستعادة والحذف ضرورية: الربط التلقائي يقصي
| المحذوف منطقياً، فمسار استعادة بلا withTrashed يعطي 404 دائماً — وهو
| عيب لا يظهر إلا عند أول محاولة استعادة حقيقية.
*/

Route::middleware(['auth', EnsureIsStaff::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {

        Route::get('/', DashboardController::class)->name('dashboard');

        // ─── الإعلانات ───
        Route::get('/listings', [ListingModerationController::class, 'index'])->name('listings');
        Route::post('/listings/{listing}/approve', [ListingModerationController::class, 'approve'])->name('listings.approve');
        Route::post('/listings/{listing}/reject', [ListingModerationController::class, 'reject'])->name('listings.reject');
        Route::post('/listings/{listing}/pause', [ListingModerationController::class, 'pause'])->name('listings.pause');
        Route::post('/listings/{listing}/feature', [ListingModerationController::class, 'feature'])->name('listings.feature');
        Route::post('/listings/{listing}/unfeature', [ListingModerationController::class, 'unfeature'])->name('listings.unfeature');
        Route::delete('/listings/{listing}', [ListingModerationController::class, 'destroy'])->name('listings.destroy');
        Route::post('/listings/{listing}/restore', [ListingModerationController::class, 'restore'])
            ->withTrashed()->name('listings.restore');

        // ─── البلاغات ───
        Route::get('/reports', [ReportController::class, 'index'])->name('reports');
        Route::post('/reports/{report}/dismiss', [ReportController::class, 'dismiss'])->name('reports.dismiss');
        Route::post('/reports/{report}/action', [ReportController::class, 'action'])->name('reports.action');

        // ─── الأسئلة ───
        Route::get('/questions', [QuestionModerationController::class, 'index'])->name('questions');
        Route::post('/questions/{question}/hide', [QuestionModerationController::class, 'hide'])->name('questions.hide');
        Route::post('/questions/{question}/unhide', [QuestionModerationController::class, 'unhide'])->name('questions.unhide');

        // ─── الحسابات: للمدير وحده ───
        Route::middleware(EnsureIsAdmin::class)->group(function (): void {
            Route::get('/users', [UserController::class, 'index'])->name('users');
            Route::post('/users/{user}/status', [UserController::class, 'status'])->name('users.status');
            Route::post('/users/{user}/role', [UserController::class, 'role'])->name('users.role');
        });
    });
