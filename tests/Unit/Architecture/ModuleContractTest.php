<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * عقد الوحدات محروساً باختبار لا بانضباط.
 *
 * القواعد هنا كانت تعليقات متفرقة في هجرات وملفات مسارات — والتعليق
 * يُخرَق بلا أن يلاحظ أحد. ما يلي يفشل عند الخرق.
 */
class ModuleContractTest extends TestCase
{
    private const MODULES_PATH = __DIR__.'/../../../app/Modules';

    /**
     * الاستثناء الوحيد المعروف لقاعدة «Shared لا تعتمد على أحد».
     *
     * HealthCheck يقرأ EventName من Analytics ليتحقق أن كل حدث معلَن
     * مغطّى. الفحص يخصّ Analytics أكثر مما يخصّ Shared، وموضعه الصحيح
     * داخل Analytics — يُنقل حين تُمسّ الوحدة، لا قبل ذلك.
     */
    private const SHARED_EXCEPTIONS = ['Support/HealthCheck.php'];

    #[Test]
    public function shared_does_not_depend_on_other_modules(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn('Shared') as $relative => $source) {
            if (in_array($relative, self::SHARED_EXCEPTIONS, true)) {
                continue;
            }

            preg_match_all('/App\\\\Modules\\\\(\w+)/', $source, $matches);

            foreach (array_unique($matches[1]) as $module) {
                if ($module !== 'Shared') {
                    $violations[] = "Shared/{$relative} -> {$module}";
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['Shared هي القاعدة: كل وحدة تعتمد عليها، وهي لا تعتمد على أحد.'],
            $violations
        )));
    }

    /**
     * القاعدة موثّقة في هجرة search_blob: وحدة Search تقرأ جداول Catalog
     * ولا تكتب فيها. الكاتب الوحيد هو ListingObserver داخل Catalog.
     */
    #[Test]
    public function search_never_writes_to_catalog(): void
    {
        $writes = '/(Listing::(create|insert|update|destroy)|DB::table\([\'"]listings[\'"]\)\s*(->[^;]*)?->(insert|update|delete|truncate|upsert))/';

        $violations = [];

        foreach ($this->phpFilesIn('Search') as $relative => $source) {
            if (preg_match($writes, $source) === 1) {
                $violations[] = "Search/{$relative}";
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['Search تقرأ Catalog ولا تكتب فيها — الكتابة تخصّ ListingObserver.'],
            $violations
        )));
    }

    /**
     * يثبّت مكسب الاكتشاف التلقائي: عودة أي تسجيل يدوي تُفشل الاختبار.
     * بلا هذا، أول وحدة جديدة تُضاف بـrequire تعيد التفاوت من حيث انتهى.
     */
    #[Test]
    public function modules_are_never_registered_by_hand(): void
    {
        $providers = file_get_contents(__DIR__.'/../../../bootstrap/providers.php');

        $this->assertStringNotContainsString(
            'App\Modules',
            $providers,
            'موفّرات الوحدات تُكتشف تلقائياً — لا تُسجَّل في bootstrap/providers.php.'
        );

        $web = file_get_contents(__DIR__.'/../../../routes/web.php');

        // مرسى على بداية السطر: بلا ترسية يطابق التعبير كلمة require
        // داخل تعليق يشرح غيابها — وهو ما حدث فعلاً في أول تشغيل.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*require(_once)?\b[^;]*Modules/m',
            $web,
            'مسارات الوحدات تُكتشف تلقائياً — لا تُستدعى بـrequire من routes/web.php.'
        );
    }

    /**
     * الموفّر يوجد حين يقوم بعمل لا يغطّيه الاكتشاف. موفّر لا يفعل غير
     * تسجيل القوالب أو الهجرات مصدرُ حقيقةٍ ثانٍ لموضع ملفات الوحدة.
     */
    #[Test]
    public function module_providers_do_not_re_register_discovered_paths(): void
    {
        $violations = [];

        foreach (glob(self::MODULES_PATH.'/*/Providers/*ServiceProvider.php') ?: [] as $file) {
            $source = file_get_contents($file);

            foreach (['loadViewsFrom', 'loadMigrationsFrom', 'loadRoutesFrom'] as $call) {
                if (str_contains($source, $call)) {
                    $violations[] = basename($file)." -> {$call}";
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['القوالب والهجرات والمسارات يكتشفها ModuleServiceProvider.'],
            $violations
        )));
    }

    /**
     * @return array<string, string> المسار النسبي => محتوى الملف
     */
    private function phpFilesIn(string $module): array
    {
        $root = self::MODULES_PATH.'/'.$module;

        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            // الهجرات مستثناة: تعريف سكيمة لا اعتماد كود.
            if (str_starts_with($relative, 'Database/Migrations/')) {
                continue;
            }

            $files[$relative] = file_get_contents($file->getPathname());
        }

        return $files;
    }
}
