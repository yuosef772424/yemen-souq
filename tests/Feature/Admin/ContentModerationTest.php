<?php

namespace Tests\Feature\Admin;

use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Engagement\Models\ListingQuestion;
use App\Modules\Engagement\Models\ListingReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\Concerns\MakesAdminUsers;
use Tests\TestCase;

class ContentModerationTest extends TestCase
{
    use RefreshDatabase, MakesAdminUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();
    }

    /**
     * أهم اختبار في البلاغات: عشرة بلاغات على إعلان واحد مشكلة واحدة.
     * ترك البقية مفتوحة يعرض على المشرف عملاً منتهياً، فيفقد ثقته
     * بالطابور — وهذا أسرع طريق إلى لوحة مهجورة.
     */
    public function test_actioning_one_report_closes_the_rest_and_rejects_the_listing(): void
    {
        $listing = $this->listing();
        $reporters = [$this->member(), $this->member(), $this->member()];

        foreach ($reporters as $reporter) {
            ListingReport::create([
                'listing_id' => $listing->id,
                'reporter_id' => $reporter->id,
                'reason' => 'fraud',
                'status' => 'open',
                'created_at' => now(),
            ]);
        }

        $first = ListingReport::query()->where('listing_id', $listing->id)->firstOrFail();

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.action', $first), ['reason' => 'إعلان احتيالي مؤكَّد بعد المراجعة.'])
            ->assertRedirect();

        $this->assertSame('rejected', $listing->fresh()->status);
        $this->assertSame(0, ListingReport::query()->where('listing_id', $listing->id)->open()->count());
        $this->assertDatabaseHas('admin_actions', ['action' => AdminAction::ReportActioned->value]);
    }

    public function test_dismissing_keeps_the_report_and_the_listing(): void
    {
        $listing = $this->listing();

        $report = ListingReport::create([
            'listing_id' => $listing->id,
            'reporter_id' => $this->member()->id,
            'reason' => 'spam',
            'status' => 'open',
            'created_at' => now(),
        ]);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.dismiss', $report))
            ->assertRedirect();

        $this->assertSame('dismissed', $report->fresh()->status);
        $this->assertNotSame('rejected', $listing->fresh()->status);

        // البلاغ المصروف يبقى: نسبة الصرف على كل مُبلِّغ هي الإشارة
        // الوحيدة التي تكشف من يُبلِّغ على منافسيه
        $this->assertDatabaseCount('listing_reports', 1);
    }

    public function test_hiding_a_question_removes_it_from_the_public_scope(): void
    {
        $listing = $this->listing();

        $question = ListingQuestion::create([
            'listing_id' => $listing->id,
            'asker_id' => $this->member()->id,
            'body' => 'رقم للتواصل خارج المنصة: 0000000',
        ]);

        $this->actingAs($this->moderator())
            ->post(route('admin.questions.hide', $question), ['reason' => 'محاولة تواصل خارج المنصة.'])
            ->assertRedirect();

        $this->assertNotNull($question->fresh()->hidden_at);
        $this->assertSame(0, ListingQuestion::query()->visible()->count());

        $this->actingAs($this->moderator())
            ->post(route('admin.questions.unhide', $question))
            ->assertRedirect();

        $this->assertNull($question->fresh()->hidden_at);
        $this->assertSame(1, ListingQuestion::query()->visible()->count());
    }

    public function test_hiding_a_question_requires_a_reason(): void
    {
        $listing = $this->listing();

        $question = ListingQuestion::create([
            'listing_id' => $listing->id,
            'asker_id' => $this->member()->id,
            'body' => 'هل السيارة مصدومة؟',
        ]);

        $this->actingAs($this->moderator())
            ->from(route('admin.questions'))
            ->post(route('admin.questions.hide', $question), ['reason' => 'لا'])
            ->assertSessionHasErrors('reason');

        $this->assertNull($question->fresh()->hidden_at);
    }
}
