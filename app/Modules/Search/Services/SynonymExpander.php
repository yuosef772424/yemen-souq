<?php

namespace App\Modules\Search\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع كلمة الاستعلام إلى مجموعة مكافئاتها.
 *
 * هذا هو «المستوى الثاني» من الترتيب المطلوب: المطابقة التامة أولاً، ثم
 * المكافئ. جدول search_synonyms كان في المخطط من أول هجرة ولا سطر في
 * المشروع يقرأه — وعد في البنية بلا تنفيذ.
 *
 * لماذا يهمّ في اليمن تحديداً: الناس يكتبون «موتر» و«عربية» و«سيارة»
 * للشيء نفسه، و«دباب» للدراجة النارية، و«تلفون» و«جوال» و«موبايل».
 * بلا مكافئات، البحث يعاقب من كتب بلهجته.
 *
 * ═══ قرارات ═══
 *
 * 1. المجموعات تُبنى بالإغلاق الترابطي لا بالأزواج المباشرة.
 *
 *    الجدول يخزّن أزواجاً: (جوال، موبايل) و(جوال، تلفون) و(جوال، هاتف).
 *    القراءة الزوجية المباشرة تجعل «جوال» يجد الثلاثة، بينما «تلفون»
 *    يجد «جوال» فقط ولا يجد «موبايل» — فيعمل البحث لمن كتب الكلمة
 *    المركزية ويفشل لمن كتب طرفاً. والأسوأ أن العيب غير متناظر، فيبدو
 *    عشوائياً ويستحيل تفسيره من قراءة الجدول.
 *
 *    الإغلاق يجمع كل كلمات السلسلة في مجموعة واحدة، فتتكافأ الأطراف.
 *    والثمن معروف: سلسلة أ↔ب وب↔ج تجعل «أ» و«ج» مكافئين ولو لم يقصد
 *    من أدخلهما ذلك. لهذا سقف حجم المجموعة أدناه، ولهذا تبقى قاعدة
 *    البذرة صريحة: يُدخَل الزوج إن كان الطرفان الشيء نفسه، لا إن
 *    كانا يظهران معاً.
 *
 * 2. الحفظ في الذاكرة لطلب واحد، لا في Cache دائم.
 *    الجدول عشرات الصفوف؛ استعلام واحد لكل طلب لا يُقاس. أما Cache
 *    بعمر ساعة فيعني أن إضافة مكافئ لا تظهر — فيظن من أضافه أن الميزة
 *    معطوبة ويقضي وقته في المكان الخطأ. الكاش يُضاف حين يُقاس أثره.
 *
 * 3. سقفان: على عدد المكافئات لكل كلمة، وعلى حجم المجموعة كلها.
 *    ثماني كلمات × خمسة مكافئات = أربعون شرط LIKE على مسح كامل للجدول.
 */
final class SynonymExpander
{
    public const MAX_PER_TOKEN = 3;

    /** سلسلة أطول من هذا تعني على الأرجح خطأ إدخال، لا عائلة كلمات */
    public const MAX_GROUP = 6;

    /** @var array<string, array<int, string>>|null */
    private ?array $groups = null;

    /**
     * كل كلمة تصبح مجموعة: الكلمة نفسها أولاً ثم مكافئاتها.
     *
     * @param  array<int, string>  $tokens
     * @return array<int, array<int, string>>
     */
    public function expand(array $tokens): array
    {
        $result = [];

        foreach ($tokens as $token) {
            $family = $this->groups()[$token] ?? [];

            $synonyms = array_slice(
                array_values(array_filter($family, fn (string $word): bool => $word !== $token)),
                0,
                self::MAX_PER_TOKEN
            );

            $result[] = array_values(array_unique([$token, ...$synonyms]));
        }

        return $result;
    }

    /**
     * لكل كلمة: عائلتها كاملة.
     *
     * @return array<string, array<int, string>>
     */
    private function groups(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        // الجدول قد يغيب في قاعدة لم تُهجَّر بعد — والبحث يجب أن يعمل بلا
        // مكافئات لا أن يسقط. المكافئات تحسين، لا شرط تشغيل.
        if (! Schema::hasTable('search_synonyms')) {
            return $this->groups = [];
        }

        /** @var array<string, array<int, string>> $families كل كلمة ← مرجع عائلتها */
        $families = [];

        foreach (DB::table('search_synonyms')->where('is_active', true)->get() as $row) {
            $this->merge($families, (string) $row->term, (string) $row->synonym);
        }

        return $this->groups = array_map(
            fn (array $family): array => array_slice($family, 0, self::MAX_GROUP),
            $families
        );
    }

    /**
     * دمج عائلتي كلمتين في واحدة، وتحديث كل أعضائها.
     *
     * الصيغة بسيطة عن قصد: البديل هو بنية union-find كاملة، وهي الحل
     * الصحيح لملايين الأزواج ومبالغة واضحة لعشرات. حين يكبر الجدول
     * تُستبدل هذه الدالة وحدها.
     *
     * @param  array<string, array<int, string>>  $families
     */
    private function merge(array &$families, string $a, string $b): void
    {
        if ($a === '' || $b === '' || $a === $b) {
            return;
        }

        $merged = array_values(array_unique([
            ...($families[$a] ?? [$a]),
            ...($families[$b] ?? [$b]),
        ]));

        foreach ($merged as $word) {
            $families[$word] = $merged;
        }
    }
}