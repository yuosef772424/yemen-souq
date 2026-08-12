<?php

use App\Modules\Api\Http\Controllers\AccountController;
use App\Modules\Api\Http\Controllers\AuthController;
use App\Modules\Api\Http\Controllers\ConversationController;
use App\Modules\Api\Http\Controllers\DeviceController;
use App\Modules\Api\Http\Controllers\FavoriteController;
use App\Modules\Api\Http\Controllers\HealthController;
use App\Modules\Api\Http\Controllers\ListingController;
use App\Modules\Api\Http\Controllers\MyListingController;
use App\Modules\Api\Http\Controllers\NotificationController;
use App\Modules\Api\Http\Controllers\PasswordResetController;
use App\Modules\Api\Http\Controllers\PhoneVerificationController;
use App\Modules\Api\Http\Controllers\QuestionController;
use App\Modules\Api\Http\Controllers\ReportController;
use App\Modules\Api\Http\Controllers\TaxonomyController;
use App\Modules\Api\Http\Middleware\ForceJsonResponse;
use App\Modules\Identity\Http\Middleware\EnsurePhoneIsVerified;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| واجهة برمجة التطبيقات — v1
|--------------------------------------------------------------------------
|
| الملف اسمه api.php، وهذا وحده ما يجعله يُسجَّل تحت مجموعة `api`
| وبادئة `/api` بدل `web` — انظر ModuleServiceProvider::registerRoutes.
| القاعدة عامة لكل وحدة، لا خاصة بهذه.
|
| ═══ الإصدار في البادئة لا في ترويسة ═══
|
| /api/v1/… لا Accept: application/vnd.souq.v1+json. التطبيق المنشور
| على أجهزة الناس لا يُحدَّث دفعةً واحدة، والإصدار في المسار يُقرأ في
| السجلّات وفي أدوات المراقبة وفي أي عميل بلا تهيئة — بينما الترويسة
| تختفي من كل واحدة منها.
|
| ═══ ثلاث طبقات من حدود المعدّل ═══
|
| api-auth  على الدخول والتسجيل والاستعادة — بالعنوان، لأن الطالب ليس
|           مسجَّلاً بعد.
| api-read  على القراءة — سخيّ، فالتطبيق يجلب أثناء التمرير.
| api-write على الكتابة — أضيق.
|
| وفوقها حدودٌ على الفعل نفسه في المتحكّمات (الرسائل، الأسئلة، رموز
| التوثيق). الطبقتان مختلفتان: هذه تحمي الخادم، وتلك تحمي المنصة من
| إساءة الاستعمال — ورسالتها عربية موجَّهة للمستخدم.
|
| ═══ وترتيب المسارات مقصود ═══
|
| المقاطع الثابتة قبل المتغيّرة في كل مجموعة. بلا ذلك يلتقط
| {listing} كلمة mine أو similar كمعرّف، فيعود 404 على مسار سليم —
| نفس فخّ read-all في مسارات الإشعارات.
|
*/

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(ForceJsonResponse::class)
    ->group(function (): void {

        Route::get('/health', HealthController::class)->name('health');

        /*
        |----------------------------------------------------------------
        | الحساب — بلا رمز
        |----------------------------------------------------------------
        */

        Route::middleware('throttle:api-auth')->group(function (): void {
            Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
            Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

            Route::post('/auth/password/forgot', [PasswordResetController::class, 'send'])
                ->name('auth.password.forgot');
            Route::post('/auth/password/reset', [PasswordResetController::class, 'reset'])
                ->name('auth.password.reset');
        });

        /*
        |----------------------------------------------------------------
        | بنية السوق والتصفّح — عامّة
        |----------------------------------------------------------------
        |
        | عامة بقصد: التطبيق يعرض السوق قبل أن يسجّل أحد. واشتراط الحساب
        | على التصفّح يطرد الزائر الذي جاء ليرى ما يُباع — وهو من نريد
        | تحويله إلى مستخدم.
        |
        */

        Route::middleware('throttle:api-read')->group(function (): void {
            Route::get('/bootstrap', [TaxonomyController::class, 'bootstrap'])->name('bootstrap');
            Route::get('/categories', [TaxonomyController::class, 'categories'])->name('categories');
            Route::get('/categories/{category}/attributes', [TaxonomyController::class, 'attributes'])
                ->whereNumber('category')
                ->name('categories.attributes');
            Route::get('/locations', [TaxonomyController::class, 'locations'])->name('locations');
            Route::get('/currencies', [TaxonomyController::class, 'currencies'])->name('currencies');
            Route::get('/report-reasons', [ReportController::class, 'reasons'])->name('report-reasons');

            Route::get('/listings', [ListingController::class, 'index'])->name('listings.index');
            Route::get('/listings/{listing}', [ListingController::class, 'show'])->name('listings.show');
            Route::get('/listings/{listing}/similar', [ListingController::class, 'similar'])->name('listings.similar');
            Route::get('/listings/{listing}/questions', [QuestionController::class, 'index'])->name('listings.questions');

            // POST لا GET: يزيد عدّاداً ويسجّل حدثاً — وأي جالبٍ مسبق
            // يمرّ على GET يُفسد أقوى إشارة نية شراء في المنصة
            Route::post('/listings/{listing}/contact', [ListingController::class, 'contact'])
                ->name('listings.contact');
        });

        /*
        |----------------------------------------------------------------
        | ما يتطلب رمزاً
        |----------------------------------------------------------------
        */

        Route::middleware('auth:sanctum')->group(function (): void {

            // ─── الحساب ───

            Route::middleware('throttle:api-read')->group(function (): void {
                Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
                Route::get('/auth/phone', [PhoneVerificationController::class, 'status'])
                    ->name('auth.phone.status');

                Route::get('/me/notification-preferences', [AccountController::class, 'notificationPreferences'])
                    ->name('me.notification-preferences');
                Route::get('/me/privacy', [AccountController::class, 'privacy'])->name('me.privacy');
            });

            Route::middleware('throttle:api-write')->group(function (): void {
                Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
                Route::post('/auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');

                /*
                | تسجيل الجهاز على حدّ الكتابة لا القراءة.
                |
                | التطبيق يناديه عند كل إقلاع وعند كل تدوير للرمز — يبدو
                | قراءةً في الاستعمال وهو كتابة في الأثر: كل نداء يُحدِّث
                | صفّاً ويفصل الرمز عن أجهزة أخرى.
                */
                Route::put('/devices', [DeviceController::class, 'store'])->name('devices.store');
                Route::delete('/devices/{deviceUuid}', [DeviceController::class, 'destroy'])
                    ->name('devices.destroy');
            });

            /*
            | رمز التوثيق على حدّ المصادقة الأضيق.
            |
            | إصدار رمز يرسل رسالة نصية بكلفة حقيقية، والتحقق منه محاولةُ
            | تخمينٍ لستّ خانات. كلاهما أقرب إلى الدخول منه إلى الكتابة.
            */
            Route::middleware('throttle:api-auth')->group(function (): void {
                Route::post('/auth/phone/send', [PhoneVerificationController::class, 'send'])
                    ->name('auth.phone.send');
                Route::post('/auth/phone/verify', [PhoneVerificationController::class, 'verify'])
                    ->name('auth.phone.verify');

                /*
                | كلمة المرور والرقم على حدّ المصادقة لا الكتابة.
                |
                | كلاهما يمسّ مفتاح الحساب: الأول يُبطل رموز الأجهزة
                | الأخرى، والثاني يرسل رسالة بكلفة حقيقية ويُسقط التوثيق.
                | وحدُّ الكتابة السخيّ يجعلهما هدفاً لمن سرق رمزاً.
                */
                Route::put('/me/password', [AccountController::class, 'updatePassword'])
                    ->name('me.password');
                Route::put('/me/phone', [AccountController::class, 'updatePhone'])
                    ->name('me.phone');
            });

            // ─── القراءة الخاصة ───

            Route::middleware('throttle:api-read')->group(function (): void {
                Route::get('/me/listings', [MyListingController::class, 'index'])->name('me.listings');
                Route::get('/me/listings/{listing}', [MyListingController::class, 'show'])
                    ->withTrashed()
                    ->name('me.listings.show');
                Route::get('/me/listings/{listing}/stats', [MyListingController::class, 'stats'])
                    ->name('me.listings.stats');

                Route::get('/me/favorites', [FavoriteController::class, 'index'])->name('me.favorites');
                Route::get('/me/questions', [QuestionController::class, 'inbox'])->name('me.questions');

                Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
                Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])
                    ->name('conversations.show');
                Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages'])
                    ->name('conversations.messages');

                // unread-count قبل {notification}: بلا هذا الترتيب يلتقط
                // المسار المتغيّر الكلمة كمعرّف
                Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
                Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
                    ->name('notifications.unread-count');
            });

            // ─── الكتابة ───

            Route::middleware('throttle:api-write')->group(function (): void {

                // ─── الحساب ───

                Route::put('/me/profile', [AccountController::class, 'updateProfile'])
                    ->name('me.profile');

                // POST لا PUT: PHP لا يحلّل جسد multipart إلا على POST
                Route::post('/me/avatar', [AccountController::class, 'updateAvatar'])->name('me.avatar');
                Route::delete('/me/avatar', [AccountController::class, 'destroyAvatar'])
                    ->name('me.avatar.destroy');

                Route::put('/me/notification-preferences', [AccountController::class, 'updateNotificationPreferences'])
                    ->name('me.notification-preferences.update');
                Route::put('/me/privacy', [AccountController::class, 'updatePrivacy'])
                    ->name('me.privacy.update');

                // إغلاق الحساب — DELETE على الجذر، ويشترط كلمة المرور
                Route::delete('/me', [AccountController::class, 'destroy'])->name('me.destroy');

                /*
                | النشر والتعديل خلف بوابة الهاتف — وحدهما.
                |
                | نفس قسمة الويب: البوابة تخصّ *إنشاء* محتوى معروض للسوق.
                | أما السؤال والمفضلة والرسالة فسلوك مشترٍ، وحظره يحرم
                | المنصة من الطرف الذي تحتاجه أكثر.
                */
                Route::middleware(EnsurePhoneIsVerified::class)->group(function (): void {
                    Route::post('/me/listings', [MyListingController::class, 'store'])->name('me.listings.store');
                    Route::post('/me/listings/{listing}', [MyListingController::class, 'update'])
                        ->name('me.listings.update');
                });

                Route::delete('/me/listings/{listing}', [MyListingController::class, 'destroy'])
                    ->name('me.listings.destroy');
                Route::post('/me/listings/{listing}/restore', [MyListingController::class, 'restore'])
                    ->withTrashed()
                    ->name('me.listings.restore');
                Route::post('/me/listings/{listing}/renew', [MyListingController::class, 'renew'])
                    ->name('me.listings.renew');
                Route::post('/me/listings/{listing}/sold', [MyListingController::class, 'toggleSold'])
                    ->name('me.listings.sold');

                // scopeBindings: بلاه يقبل المسار معرّف صورة من إعلان آخر
                Route::delete('/me/listings/{listing}/images/{image}', [MyListingController::class, 'destroyImage'])
                    ->scopeBindings()
                    ->name('me.listings.images.destroy');

                // المفضلة بفعلين صريحين لا بتبديل — انظر FavoriteController
                Route::put('/listings/{listing}/favorite', [FavoriteController::class, 'store'])
                    ->name('listings.favorite.store');
                Route::delete('/listings/{listing}/favorite', [FavoriteController::class, 'destroy'])
                    ->name('listings.favorite.destroy');

                Route::post('/listings/{listing}/questions', [QuestionController::class, 'store'])
                    ->name('listings.questions.store');
                Route::post('/questions/{question}/answer', [QuestionController::class, 'answer'])
                    ->name('questions.answer');
                Route::delete('/questions/{question}', [QuestionController::class, 'destroy'])
                    ->name('questions.destroy');

                Route::post('/listings/{listing}/reports', [ReportController::class, 'store'])
                    ->name('listings.reports.store');

                Route::post('/listings/{listing}/conversation', [ConversationController::class, 'store'])
                    ->name('listings.conversation.store');
                Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'reply'])
                    ->name('conversations.reply');

                // الوسم فعلٌ صريح لا أثر جانبي لـGET — انظر المتحكّم
                Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markRead'])
                    ->name('conversations.read');

                Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
                    ->name('notifications.read-all');
                Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
                    ->name('notifications.read');
            });
        });
    });
