<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Services\SearchQueryBuilder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * تكافؤ المسارين: MATCH يجب أن يجد ما يجده LIKE.
 *
 * ═══ لماذا هذا الاختبار موجود ═══
 *
 * البحث كان LIKE '%كلمة%' — بطيء لكنه يطابق داخل الكلمة، فيجد
 * «بالسياره» عند البحث عن «سياره». وMATCH على فهرس FULLTEXT أسرع بمئات
 * المرات لكنه يطابق **كلمات كاملة**، فيفوّته.
 *
 * والحلّ كان تخزين الصيغة المجرَّدة بجانب الأصلية في النص المجمَّع،
 * وتجريد كلمات الاستعلام كذلك. وهذا الاختبار هو ما يثبت أن الحلّ يعمل:
 * بلا الشقّين معاً تسقط حالة من الحالات أدناه.
 *
 * ═══ لماذا يُتخطّى على sqlite ═══
 *
 * FULLTEXT لا وجود له في sqlite، والحُزمة تعمل عليه. فالاختبار يفحص ما
 * يفحصه فعلاً — سلوك MATCH — أو يُعلن أنه لم يفحصه. والبديل المرفوض هو
 * محاكاة MATCH بـLIKE: يجعل الاختبار يمرّ دائماً ويثبت لا شيء.
 *
 * ═══ لماذا DatabaseMigrations لا RefreshDatabase ═══
 *
 * فهرس FULLTEXT في InnoDB يُحدَّث عند **إتمام المعاملة** لا عند الإدراج.
 * وRefreshDatabase يلفّ كل اختبار في معاملة مفتوحة، فالصفوف المُدرجة
 * داخلها موجودة في الجدول وغير مرئية لـMATCH إطلاقاً — حتى باستعلام خام.
 *
 * كلّف هذا نصف ساعة من التشخيص: النص المجمَّع صحيح، والفهرس موجود،
 * والتعبير سليم، والنتيجة صفر. والسبب أن الفهرس لم يكن قد بُني بعد.
 */
class FulltextParityTest extends TestCase
{
    use DatabaseMigrations, SeedsCatalog;

    /** عناوين تحمل السوابق العربية الشائعة */
    private const TITLES = [
        'سياره تويوتا كورولا للبيع في صنعاء',
        'اعلان عن بالسياره المستعمله بحاله ممتازه',
        'والسياره الثانيه موديل حديث بسعر مناسب',
        'شقه للايجار بصنعاء حي الجامعه الجديد',
        'كالسيارات الاخرى لكنها افضل حالا بكثير',
    ];

    /**
     * قرار التخطي **قبل** parent::setUp، لا بعده.
     *
     * DatabaseMigrations ينفّذ migrate:fresh داخل parent::setUp عبر
     * setUpTraits — أي قبل أن يصل التنفيذ إلى أي سطر هنا. وعلى sqlite
     * يفشل التراجع عن هجرة تحذف أعمدة من users، فيسقط الاختبار بخطأ
     * لا علاقة له بما يفحصه.
     *
     * ولهذا تُقرأ البيئة الخام لا config(): التطبيق لم يُنشأ بعد.
     */
    protected function setUp(): void
    {
        $driver = $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: 'sqlite';

        if ($driver !== 'mysql') {
            $this->markTestSkipped('FULLTEXT يحتاج MySQL/MariaDB — الحُزمة تعمل على sqlite.');
        }

        parent::setUp();

        $this->seedReferenceData();

        $owner = User::factory()->create();

        foreach (self::TITLES as $title) {
            $this->listingFor($owner, ['title' => $title]);
        }
    }

    /** @return list<int> */
    private function idsFor(string $term): array
    {
        $criteria = SearchCriteria::fromRequest(Request::create('/', 'GET', ['q' => $term]));

        return app(SearchQueryBuilder::class)
            ->build($criteria)
            ->pluck('listings.id')
            ->all();
    }

    /** @return list<int> المسار القديم، للمقارنة */
    private function likeIdsFor(string $term): array
    {
        return Listing::query()
            ->published()
            ->where('search_blob', 'like', '%'.$term.'%')
            ->pluck('id')
            ->all();
    }

    #[Test]
    public function a_bare_word_finds_listings_that_carry_a_prefix(): void
    {
        $ids = $this->idsFor('سياره');

        $this->assertGreaterThanOrEqual(
            3,
            count($ids),
            'البحث عن «سياره» يجب أن يجد «بالسياره» و«والسياره» أيضاً.'
        );
    }

    #[Test]
    public function a_prefixed_word_finds_the_bare_listing(): void
    {
        $ids = $this->idsFor('بالسياره');

        $bare = Listing::where('title', 'like', 'سياره تويوتا%')->firstOrFail();

        $this->assertContains(
            $bare->id,
            $ids,
            'من كتب «بالسياره» يجب أن يجد إعلاناً عنوانه «سياره».'
        );
    }

    /**
     * التكافؤ نفسه: ما يجده LIKE يجده MATCH.
     *
     * وهو الادعاء الذي تقوم عليه الترقية كلها — بلا هذا الاختبار تصير
     * السرعة مكسوبة على حساب نتائج تختفي بصمت.
     */
    #[Test]
    public function match_finds_at_least_what_like_finds(): void
    {
        foreach (['سياره', 'صنعاء', 'شقه', 'موديل'] as $term) {
            $missing = array_diff($this->likeIdsFor($term), $this->idsFor($term));

            $this->assertSame(
                [],
                array_values($missing),
                "«{$term}»: MATCH فوّت نتائج يجدها LIKE."
            );
        }
    }

    #[Test]
    public function an_unrelated_word_returns_nothing(): void
    {
        $this->assertSame([], $this->idsFor('زعفراني'));
    }

    /** معاملات BOOLEAN MODE لا تُفشل الاستعلام ولا تغيّر معناه */
    #[Test]
    public function boolean_mode_operators_in_the_query_are_neutralised(): void
    {
        foreach (['+سياره', 'سياره*', '-سياره', '"سياره"', 'سياره)('] as $hostile) {
            $this->get('/?q='.urlencode($hostile))->assertOk();
        }
    }
}
