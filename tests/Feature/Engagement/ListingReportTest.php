<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Engagement\Models\ListingReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Concerns\SeedsMarketplace;
use Tests\TestCase;

/**
 * الإبلاغ — الطرف المفقود من دورة الإشراف.
 *
 * ═══ مصيدة كلّفتني اختباراً فاشلاً، وتُشرَح هنا لأنها ستتكرّر ═══
 *
 * ListingFactory يختار المالك بـUser::inRandomOrder()->first(). فإن
 * أُنشئ المُبلِّغ قبل الإعلان، جاز أن يكون هو نفسه مالكه — والمالك لا
 * يبلّغ على إعلانه، فيُرفض كل بلاغ ويعطي الاختبار صفراً بلا رسالة تدلّ
 * على السبب. الفشل كان في الاختبار لا في الكود، وهو أخطر النوعين: يوهم
 * بعيب غير موجود.
 *
 * ولهذا كل إعلان هنا يُنشأ بمالك صريح. عشوائية المصنع مفيدة في البذور
 * ومُهلكة في اختبار يعتمد على «من يملك ماذا».
 */
class ListingReportTest extends TestCase
{
    use RefreshDatabase, SeedsMarketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();
    }

    /** إعلان يملكه شخص آخر بيقين */
    private function foreignListing(): \App\Modules\Catalog\Models\Listing
    {
        return $this->listing(['user_id' => User::factory()->create()->id]);
    }

    private function reporter(): User
    {
        $user = User::factory()->create();

        RateLimiter::clear('report:' . $user->id);

        return $user;
    }

    public function test_guest_cannot_report(): void
    {
        $listing = $this->foreignListing();

        $this->post(route('listing.report', $listing), ['reason' => 'fraud'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('listing_reports', 0);
    }

    public function test_a_member_can_report_a_listing(): void
    {
        $listing = $this->foreignListing();
        $reporter = $this->reporter();

        $this->actingAs($reporter)
            ->post(route('listing.report', $listing), [
                'reason' => 'fraud',
                'details' => 'يطلب تحويلاً مقدماً قبل رؤية السلعة.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('listing_reports', [
            'listing_id' => $listing->id,
            'reporter_id' => $reporter->id,
            'reason' => 'fraud',
            'status' => 'open',
        ]);
    }

    /** المالك لا يبلّغ على نفسه — وإلا صار الإبلاغ طريقاً لسحب إعلانه بالإشراف */
    public function test_owner_cannot_report_their_own_listing(): void
    {
        $owner = $this->reporter();
        $listing = $this->listing(['user_id' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('listing.report', $listing), ['reason' => 'spam'])
            ->assertRedirect();

        $this->assertDatabaseCount('listing_reports', 0);
    }

    public function test_a_second_open_report_on_the_same_listing_is_refused(): void
    {
        $listing = $this->foreignListing();
        $reporter = $this->reporter();

        foreach ([1, 2] as $ignored) {
            $this->actingAs($reporter)
                ->post(route('listing.report', $listing), ['reason' => 'spam']);
        }

        $this->assertDatabaseCount('listing_reports', 1);
    }

    /**
     * وبعد الفصل في الأول يجوز بلاغ جديد.
     *
     * وهذا سبب رفض الفهرس الفريد على (listing_id, reporter_id): الحالة
     * مشروعة — أعاد المعلن نشر نفس المخالفة بعد أن صُرِف البلاغ.
     */
    public function test_a_new_report_is_allowed_after_the_first_was_closed(): void
    {
        $listing = $this->foreignListing();
        $reporter = $this->reporter();

        $this->actingAs($reporter)->post(route('listing.report', $listing), ['reason' => 'spam']);

        ListingReport::query()->update(['status' => 'dismissed']);

        $this->actingAs($reporter)->post(route('listing.report', $listing), ['reason' => 'fraud']);

        $this->assertDatabaseCount('listing_reports', 2);
    }

    public function test_other_requires_an_explanation(): void
    {
        $listing = $this->foreignListing();

        $this->actingAs($this->reporter())
            ->from(route('listing.show', $listing))
            ->post(route('listing.report', $listing), ['reason' => 'other'])
            ->assertSessionHasErrors('details');

        $this->assertDatabaseCount('listing_reports', 0);
    }

    /**
     * الحدّ الساعي: الهجوم الحقيقي ليس تكرار البلاغ على إعلان واحد، بل
     * حساب واحد يبلّغ على خمسين إعلاناً لمنافس فيُغرق الطابور.
     */
    public function test_a_burst_of_reports_is_rate_limited(): void
    {
        $reporter = $this->reporter();

        $listings = collect(range(1, 6))->map(fn (): object => $this->foreignListing());

        foreach ($listings as $listing) {
            $this->actingAs($reporter)->post(route('listing.report', $listing), ['reason' => 'spam']);
        }

        $this->assertSame(5, ListingReport::query()->count());
    }
}
