<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Console\ComputeAffinityCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\SeedsMarketplace;
use Tests\TestCase;

/**
 * الأرقام التي كانت أصفاراً ثابتة.
 *
 * كل اختبار هنا يحرس عموداً كان يُكتب بقيمة مُختلقة (صفر) بتعليق يقول
 * «غير مبني بعد». وقد بُني — والاختبار هو ما يمنع رجوعه صفراً بصمت.
 */
class BehaviourRollupTest extends TestCase
{
    use RefreshDatabase, SeedsMarketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();
    }

    public function test_rollup_counts_favorites_instead_of_writing_zero(): void
    {
        $listing = $this->listing();
        $today = Carbon::today()->toDateString();

        $this->recordRawEvent('listing_viewed', null, $listing->id, $today);
        $this->recordRawEvent('favorite_added', User::factory()->create(), $listing->id, $today);
        $this->recordRawEvent('favorite_added', User::factory()->create(), $listing->id, $today);

        $this->artisan('analytics:rollup', ['--date' => $today])->assertSuccessful();

        $row = DB::table('listing_daily_stats')
            ->where('listing_id', $listing->id)
            ->where('stat_date', $today)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->favorites);
    }

    public function test_affinity_weights_a_contact_above_a_view(): void
    {
        $user = User::factory()->create();
        $today = Carbon::today()->toDateString();

        $viewed = $this->listing();
        $contacted = $this->listing();

        // تصنيفان مختلفان مطلوبان ليكون للمقارنة معنى
        if ($viewed->category_id === $contacted->category_id) {
            $other = DB::table('categories')
                ->whereNotNull('parent_id')
                ->where('id', '!=', $viewed->category_id)
                ->value('id');

            $contacted->forceFill(['category_id' => $other])->save();
        }

        $this->recordRawEvent('listing_viewed', $user, $viewed->id, $today);
        $this->recordRawEvent('contact_revealed', $user, $contacted->id, $today);

        $this->artisan('analytics:affinity')->assertSuccessful();

        $viewScore = (float) DB::table('user_category_affinity')
            ->where('user_id', $user->id)->where('category_id', $viewed->category_id)->value('score');

        $contactScore = (float) DB::table('user_category_affinity')
            ->where('user_id', $user->id)->where('category_id', $contacted->category_id)->value('score');

        $this->assertGreaterThan($viewScore, $contactScore);
    }

    /**
     * التلاشي: نفس الحدث بنفس العدد، والأقدم يزن أقل. بلا هذا الاختبار
     * يمكن أن يُحذف التلاشي بلا أن يفشل شيء — ويبقى اهتمام العام الماضي
     * أعلى من اهتمام اليوم.
     */
    public function test_older_behaviour_decays(): void
    {
        $recentUser = User::factory()->create();
        $oldUser = User::factory()->create();

        $listing = $this->listing();

        $this->recordRawEvent('listing_viewed', $recentUser, $listing->id, Carbon::today()->toDateString());
        $this->recordRawEvent(
            'listing_viewed',
            $oldUser,
            $listing->id,
            Carbon::today()->subDays(ComputeAffinityCommand::HALF_LIFE_DAYS)->toDateString()
        );

        $this->artisan('analytics:affinity')->assertSuccessful();

        $recent = (float) DB::table('user_category_affinity')->where('user_id', $recentUser->id)->value('score');
        $old = (float) DB::table('user_category_affinity')->where('user_id', $oldUser->id)->value('score');

        $this->assertGreaterThan($old, $recent);
        $this->assertEqualsWithDelta($recent / 2, $old, 0.05);
    }

    /** الجدول صورةُ حال لا أرشيف: صفّ لمستخدم خرج من النافذة يختفي */
    public function test_affinity_replaces_stale_rows(): void
    {
        $user = User::factory()->create();
        $listing = $this->listing();

        DB::table('user_category_affinity')->insert([
            'user_id' => $user->id,
            'category_id' => $listing->category_id,
            'score' => 999,
            'last_event_at' => now()->subYear(),
            'computed_at' => now()->subYear(),
        ]);

        $this->recordRawEvent('listing_viewed', $user, $listing->id, Carbon::today()->toDateString());

        $this->artisan('analytics:affinity')->assertSuccessful();

        $score = (float) DB::table('user_category_affinity')
            ->where('user_id', $user->id)->value('score');

        $this->assertLessThan(999, $score);
        $this->assertSame(1, DB::table('user_category_affinity')->count());
    }
}
