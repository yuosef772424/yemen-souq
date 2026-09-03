<?php

namespace Tests\Feature\Shared;

use App\Modules\Catalog\Models\ListingImage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * جذر القرص العام — العقد الذي يمنع فقدان صور الإعلانات عند النشر.
 *
 * ═══ لماذا يستحق اختباراً ═══
 *
 * نظام ملفات الحاوية مؤقّت. فإن كُتبت الصور في مسارٍ داخل الصورة، مُحيت
 * مع أول إعادة نشر — والصفوف تبقى في `listing_images` تشير إلى ملفات
 * ذهبت. لا استثناء، ولا سطر في أي سجلّ: بطاقات بمربّعات فارغة فقط.
 *
 * وأخطر من ذلك أن يفترق **الجذر** (حيث تُكتب) عن **هدف الرابط الرمزي**
 * (حيث تُطلب). حينها يعمل الرفع، ويعمل الحفظ، ولا تظهر الصورة — وهو
 * عطلٌ يبدو في الواجهة كأنه مشكلة رفع لا مشكلة مسار.
 */
class PublicDiskRootTest extends TestCase
{
    /** بلا متغيّر: السلوك المحلي كما كان تماماً */
    #[Test]
    public function it_falls_back_to_the_bundled_storage_path(): void
    {
        $this->assertSame(
            storage_path('app/public'),
            config('filesystems.disks.public.root'),
        );
    }

    /**
     * أهمّ اختبار هنا: الكتابة والقراءة على مسار واحد.
     *
     * قيمتان منفصلتان لمسار واحد تفترقان عند أول نشر — ولهذا يقرأ
     * الاثنان نفس المتغيّر.
     */
    #[Test]
    public function the_symlink_target_always_follows_the_disk_root(): void
    {
        $this->assertSame(
            config('filesystems.disks.public.root'),
            config('filesystems.links')[public_path('storage')] ?? null,
            'هدف الرابط يجب أن يطابق جذر القرص، وإلا كُتبت الصور في مكان وطُلبت من آخر'
        );
    }

    /** والمتغيّر يُقرأ فعلاً — لا قيمة ثابتة تتجاهله */
    #[Test]
    public function a_persistent_volume_path_overrides_both(): void
    {
        $volume = '/data/public';

        config([
            'filesystems.disks.public.root' => $volume,
            'filesystems.links' => [public_path('storage') => $volume],
        ]);

        $this->assertSame($volume, config('filesystems.disks.public.root'));
        $this->assertSame($volume, config('filesystems.links')[public_path('storage')]);

        // والقراءة من env هي ما يجعل ذلك ممكناً في الإنتاج
        $source = file_get_contents(config_path('filesystems.php'));
        $this->assertStringContainsString("env('FILESYSTEM_PUBLIC_ROOT'", $source);
        $this->assertSame(
            2,
            substr_count($source, "env('FILESYSTEM_PUBLIC_ROOT'"),
            'المتغيّر يُقرأ مرتين: للجذر وللرابط'
        );
    }

    /**
     * نقطة الدخول تهيّئ القرص قبل أن يكتب فيه شيء.
     *
     * المنصّة تركّبه مملوكاً لـroot وFrankenPHP يعمل بـwww-data — فبلا
     * chown ينجح الإقلاع ويفشل أول رفع صورة وحده.
     */
    #[Test]
    public function the_entrypoint_prepares_the_volume_and_relinks(): void
    {
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString('mkdir -p "$FILESYSTEM_PUBLIC_ROOT"', $entrypoint);
        $this->assertStringContainsString('chown www-data:www-data "$FILESYSTEM_PUBLIC_ROOT"', $entrypoint);

        // بلا --force يتخطّى الأمر رابطاً قديماً يشير إلى المسار المؤقّت
        $this->assertStringContainsString('storage:link --force', $entrypoint);
    }

    // ─── قرص الوسائط: تبديل التخزين بمتغيّر واحد ───

    /**
     * أهمّ اختبار للانتقال إلى تخزين كائني.
     *
     * كانت الروابط تُبنى بـ`asset('storage/'.$path)` في ثمانية قوالب —
     * صيغةٌ تفترض أن الملف على قرص هذا الخادم وأنه يُخدَم من رابط رمزي.
     * والافتراضان يسقطان مع S3 معاً، فتُكتب الصور هناك وتُطلب من نطاقنا.
     *
     * فصار الرابط يُبنى في موضع واحد يقرأ القرص من الإعداد.
     */
    #[Test]
    public function image_urls_follow_the_configured_media_disk(): void
    {
        $image = new ListingImage(['path' => 'listings/1/a.webp', 'thumb_path' => 'listings/1/a-thumb.webp']);

        config(['filesystems.media' => 'public']);
        $this->assertStringEndsWith('/storage/listings/1/a.webp', $image->url());

        // قرص آخر بجذر رابط مختلف يجب أن ينقل الرابط معه
        config([
            'filesystems.media' => 'cdn',
            'filesystems.disks.cdn' => [
                'driver' => 'local',
                'root' => storage_path('app/cdn'),
                'url' => 'https://cdn.example.com',
            ],
        ]);

        $this->assertSame('https://cdn.example.com/listings/1/a.webp', $image->url());
        $this->assertSame('https://cdn.example.com/listings/1/a-thumb.webp', $image->thumbUrl());
    }

    /** بلا مصغّرة يقع الرابط إلى الأصل — لا مربّع فارغ */
    #[Test]
    public function a_missing_thumbnail_falls_back_to_the_original(): void
    {
        $image = new ListingImage(['path' => 'listings/1/a.webp', 'thumb_path' => null]);

        $this->assertSame($image->url(), $image->thumbUrl());
    }

    /**
     * لا رابط مبنيّ بـasset('storage/...') في أي قالب.
     *
     * موضعٌ واحد منسيّ يعني صورةً مكسورة في صفحة لا تُفتح كثيراً بعد
     * الانتقال إلى S3 — والاختبار أرخص من اكتشافها من مستخدم.
     */
    #[Test]
    public function no_template_builds_a_storage_url_by_hand(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Modules'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // التعليقات تُنزع أولاً: هذا الملف نفسه يذكر الصيغة الممنوعة
            // في شرحه، وتعليقٌ يُسقط اختباره ليس عطلاً بل ضجيج
            $contents = preg_replace(
                ['#/\*.*?\*/#s', '#\{\{--.*?--\}\}#s', '#//[^
]*#'],
                '',
                (string) file_get_contents($file->getPathname())
            ) ?? '';

            if (str_contains($contents, "asset('storage/") || str_contains($contents, 'asset("storage/')) {
                $offenders[] = str_replace(app_path('Modules'), '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'استعمل $image->url() / thumbUrl() بدل بناء الرابط يدوياً');
    }

    /** الكتابة والقراءة على قرص واحد: القارئ يقرأ الإعداد، فليقرأه الكاتب */
    #[Test]
    public function the_image_service_writes_to_the_configured_media_disk(): void
    {
        $source = (string) file_get_contents(
            app_path('Modules/Catalog/Services/ListingImageService.php')
        );

        $this->assertStringContainsString("config('filesystems.media'", $source);
        $this->assertStringNotContainsString("const DISK = 'public'", $source);
    }
}
