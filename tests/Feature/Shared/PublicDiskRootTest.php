<?php

namespace Tests\Feature\Shared;

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
}
