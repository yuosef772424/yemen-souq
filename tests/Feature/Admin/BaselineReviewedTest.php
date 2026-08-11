<?php

namespace Tests\Feature\Admin;

use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\MakesAdminUsers;
use Tests\TestCase;

class BaselineReviewedTest extends TestCase
{
    use RefreshDatabase, MakesAdminUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->listing(['reviewed_at' => null]);
        $this->listing(['reviewed_at' => null]);

        $this->artisan('admin:baseline-reviewed', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(2, Listing::query()->whereNull('reviewed_at')->count());
        $this->assertDatabaseCount('admin_actions', 0);
    }

    /** أهم تأكيد: صفٌّ واحد في السجل لا صفّ لكل إعلان */
    public function test_it_stamps_everything_and_logs_once(): void
    {
        collect(range(1, 3))->each(fn () => $this->listing(['reviewed_at' => null]));

        $this->artisan('admin:baseline-reviewed')->assertSuccessful();

        $this->assertSame(0, Listing::query()->whereNull('reviewed_at')->count());
        $this->assertDatabaseCount('admin_actions', 1);

        $row = DB::table('admin_actions')->first();

        $this->assertSame(AdminAction::ListingsBaselineReviewed->value, $row->action);
        $this->assertNull($row->admin_id);
        $this->assertSame(3, json_decode((string) $row->meta, true)['count']);
    }

    /** reviewed_by يبقى فارغاً: لم يرَ الإعلان بشر، والعمود لا يكذب */
    public function test_it_never_claims_a_human_reviewer(): void
    {
        $listing = $this->listing(['reviewed_at' => null]);

        $this->artisan('admin:baseline-reviewed')->assertSuccessful();

        $listing->refresh();

        $this->assertNotNull($listing->reviewed_at);
        $this->assertNull($listing->reviewed_by);
    }

    public function test_before_limits_the_stamp(): void
    {
        $old = $this->listing(['reviewed_at' => null]);
        $old->forceFill(['created_at' => now()->subMonth()])->save();

        $fresh = $this->listing(['reviewed_at' => null]);

        $this->artisan('admin:baseline-reviewed', ['--before' => now()->subWeek()->toDateString()])
            ->assertSuccessful();

        $this->assertNotNull($old->fresh()->reviewed_at);
        $this->assertNull($fresh->fresh()->reviewed_at);
    }

    public function test_a_bad_date_fails_loudly(): void
    {
        $this->artisan('admin:baseline-reviewed', ['--before' => '11-08-2026'])->assertFailed();
    }
}
