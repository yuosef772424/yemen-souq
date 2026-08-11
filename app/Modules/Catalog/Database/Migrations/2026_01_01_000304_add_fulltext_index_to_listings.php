<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فهرس FULLTEXT على النص المجمَّع.
 *
 * ═══ الرقم الذي فرض هذه الهجرة ═══
 *
 * المطابقة كانت LIKE '%كلمة%'، وحرف البدل البادئ يمنع أي فهرس. القياس
 * على 1.31 مليون صف في MariaDB:
 *
 *   صفحة واحدة بكلمة نادرة      3,580 ms  →  13.0 ms
 *   COUNT الذي يفرضه الترقيم    5,424 ms  →   0.5 ms
 *
 * وعدّ الترقيم هو الأسوأ: يُنفَّذ في كل بحث مهما كانت الكلمة، وكلفته لا
 * تتعلق بندرتها. على كلمة شائعة بلغ 16,691 ms.
 *
 * ═══ ما جعل هذا ممكناً ═══
 *
 * FULLTEXT يطابق كلمات كاملة، والعربية تُلصق سوابقها — فالبحث عن
 * «سياره» لا يجد «بالسياره». وهذا هو السبب المكتوب لاختيار LIKE أصلاً.
 * والحلّ أن ListingBlobBuilder صار يخزّن الصيغة المجرَّدة بجانب الأصلية،
 * وQueryExpander يجرّد كلمات الاستعلام كذلك.
 *
 * لذلك تُشغَّل catalog:rebuild-search-blob بعد هذه الهجرة: الإعلانات
 * القديمة لا تحمل الصيغ المجرَّدة، فتبدو الميزة عاملة للجديد وحده.
 *
 * ═══ لماذا الحراسة بالسائق ═══
 *
 * الاختبارات تعمل على sqlite في الذاكرة، وهو لا يعرف FULLTEXT.
 * والاستعلام نفسه محروس بنفس الشرط في SearchQueryBuilder — يستعمل MATCH
 * حيث يوجد الفهرس، وLIKE حيث لا يوجد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->supportsFulltext()) {
            return;
        }

        DB::statement('ALTER TABLE listings ADD FULLTEXT ft_search_blob (search_blob)');
    }

    public function down(): void
    {
        if (! $this->supportsFulltext()) {
            return;
        }

        DB::statement('ALTER TABLE listings DROP INDEX ft_search_blob');
    }

    private function supportsFulltext(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }
};
