<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * الاختبارات لا تعتمد على أصول مبنيّة.
     *
     * القوالب تستدعي ‎@vite، وبلا هذا السطر يبحث لارافل عن
     * public/build/manifest.json فيفشل كل اختبار يعرض صفحة — تسعة وثلاثون
     * اختباراً.
     *
     * وكانت الحُزمة تمرّ محلياً لسبب عابر لا لصحّة: ملف public/hot المتخلّف
     * عن جلسة npm run dev يجعل Vite يستعمل خادم التطوير ويتخطّى الـmanifest.
     * أي أن الخُضرة كانت تعتمد على أثر غير مُتتبَّع في git — يختفي في أي
     * استنساخ نظيف، على جهاز المطوّر قبل CI.
     *
     * ولماذا withoutVite لا بناء الأصول في CI: ما يُختبر هنا سلوك خادم
     * لا مظهر صفحة. ربط الحُزمة ببناء الواجهة يضيف دقائق لكل تشغيل ويجعل
     * عطلاً في vite يبدو عطلاً في الاختبارات.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
