<?php

namespace Tests\Feature\Search;

use App\Modules\Catalog\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Support\SeedsCatalog;

/**
 * هيكل النتائج أثناء انتظار الخادم.
 *
 * ═══ ما يحرسه فعلاً ═══
 *
 * الهيكل يُستنسخ بـJS من `<template>` في الصفحة، ويُوضع مكان `[data-results]`.
 * فالعقد بين القالب وJS هو هاتان السمتان — وحذف إحداهما أو إعادة تسميتها
 * لا يُنتج خطأً في أي مكان: يتوقّف الهيكل عن الظهور بصمت، ويعود المستخدم
 * إلى شاشةٍ لا تستجيب. ولهذا يُثبَّتان هنا.
 */
class ResultsSkeletonTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        Listing::create([
            'user_id' => User::factory()->create()->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'سيارة للاختبار',
            'price_amount' => 100,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ]);
    }

    #[Test]
    public function the_results_list_is_marked_so_the_skeleton_can_replace_it(): void
    {
        $this->get(route('search'))
            ->assertOk()
            ->assertSee('data-results', false)
            ->assertSee('data-results-skeleton', false);
    }

    /**
     * القالب لا يُرسَم ولا يُقرأ حتى يُستنسخ.
     *
     * `<template>` هو ما يجعل ذلك صحيحاً: محتواه خارج شجرة العرض تماماً.
     * ولو وُضع في `<div class="hidden">` لقرأه القارئ الشاشي كأربع بطاقات
     * فارغة في كل صفحة نتائج.
     */
    #[Test]
    public function the_skeleton_lives_in_a_template_element(): void
    {
        $html = $this->get(route('search'))->assertOk()->getContent();

        $this->assertStringContainsString('<template data-results-skeleton>', $html);
        $this->assertMatchesRegularExpression(
            '/<template data-results-skeleton>.*aria-hidden="true".*<\/template>/s',
            $html,
            'بطاقات الهيكل تُخفى عن القارئ الشاشي'
        );
    }

    /** لا هيكل حيث لا نتائج: صفحة «لا نتائج» ليست انتظاراً */
    #[Test]
    public function no_results_page_carries_no_skeleton(): void
    {
        $this->get(route('search', ['q' => 'لاشيءيطابقهذا']))
            ->assertOk()
            ->assertDontSee('data-results-skeleton', false);
    }
}
