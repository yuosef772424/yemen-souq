<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * العقد الوحيد للوحدات: مجلد تحت app/Modules يعمل بمجرد وجوده.
 *
 * يُكتشف تلقائياً أربعة أشياء — الموفّرات، الهجرات، القوالب، المسارات.
 * إضافة وحدة جديدة لا تتطلب تعديل أي ملف خارجها: لا bootstrap/providers.php
 * ولا routes/web.php ولا هذا الملف.
 *
 * القوالب تُستدعى بمساحة اسم الوحدة بحروف صغيرة:
 *   app/Modules/Search/Resources/Views/index.blade.php  ->  view('search::index')
 *
 * وموفّر الوحدة يوجد فقط حين يقوم بعمل لا يغطّيه الاكتشاف: ربط في الحاوية،
 * مراقب موديل، صلاحية، أمر artisan. موفّر لا يفعل غير loadViewsFrom تكرارٌ
 * لما يجري هنا، ومصدرُ حقيقةٍ ثانٍ لموضع ملفات الوحدة.
 */
class ModuleServiceProvider extends ServiceProvider
{
    /** ملف المسارات الذي يُسجَّل بلا جلسة — انظر registerRoutes */
    private const API_ROUTES_FILE = 'api.php';

    public function register(): void
    {
        // في register لا boot: موفّرات الوحدات تحمل روابط حاوية يجب أن
        // تكون جاهزة قبل أن يبدأ أي boot في التنفيذ.
        $this->registerModuleProviders();
    }

    public function boot(): void
    {
        $this->registerMigrations();
        $this->registerViews();
        $this->registerRoutes();
    }

    private function registerModuleProviders(): void
    {
        foreach ($this->modulePaths('Providers/*ServiceProvider.php') as $file) {
            $class = $this->classFromPath($file);

            if (class_exists($class)) {
                $this->app->register($class);
            }
        }
    }

    private function registerMigrations(): void
    {
        $paths = glob(app_path('Modules/*/Database/Migrations'), GLOB_ONLYDIR) ?: [];

        if ($paths !== []) {
            $this->loadMigrationsFrom($paths);
        }
    }

    private function registerViews(): void
    {
        foreach (glob(app_path('Modules/*/Resources/Views'), GLOB_ONLYDIR) ?: [] as $path) {
            $module = basename(dirname($path, 2));

            $this->loadViewsFrom($path, Str::lower($module));
        }
    }

    /**
     * مجموعة web صريحة هنا: الملفات كانت تُحمَّل بـrequire من داخل
     * routes/web.php فترث المجموعة ضمناً. تسجيلها من موفّر يخرجها من ذلك
     * السياق — وبلا الوسيط تفقد الجلسة وحماية CSRF بصمت.
     *
     * ═══ واستثناء واحد: Routes/api.php ═══
     *
     * الاسم يحمل معناه هنا لا في الوحدة. ملفٌ بهذا الاسم يُسجَّل تحت
     * مجموعة `api` وبادئة `/api`، وما عداه تحت `web`.
     *
     * ولماذا القاعدة عامة لا خاصة بوحدة Api: العقد هو «مجلد يعمل بمجرد
     * وجوده»، وقاعدةٌ تعرف اسم وحدةٍ بعينها تنقضه. أيّ وحدة تحتاج غداً
     * نقطةَ نهاية بلا جلسة تضع الملف باسمه وتحصل عليها.
     *
     * والفرق ليس بادئةً في الرابط: مجموعة `web` تعني جلسةً وكوكيز
     * وتحقّق CSRF. مسارٌ بلا حالة يحمل رمزاً في الترويسة لا يملك رمز
     * CSRF ولا يريده — فيُرفض كل POST منه بـ419 بلا سبب ظاهر.
     */
    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        foreach ($this->modulePaths('Routes/*.php') as $file) {
            if (basename($file) === self::API_ROUTES_FILE) {
                Route::middleware('api')->prefix('api')->group($file);

                continue;
            }

            Route::middleware('web')->group($file);
        }
    }

    /**
     * @return list<string>
     */
    private function modulePaths(string $pattern): array
    {
        $matches = glob(app_path('Modules/*/'.$pattern)) ?: [];

        // ترتيب ثابت: glob لا يضمن ترتيباً عبر أنظمة الملفات، وترتيب
        // تسجيل المسارات يحسم أي تعارض بين مسارين متطابقين.
        sort($matches);

        return $matches;
    }

    private function classFromPath(string $file): string
    {
        $relative = Str::after($file, app_path());
        $relative = str_replace(['/', '\\'], '\\', trim($relative, '/\\'));

        return 'App\\'.Str::beforeLast($relative, '.php');
    }
}
