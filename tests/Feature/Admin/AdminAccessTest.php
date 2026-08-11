<?php

namespace Tests\Feature\Admin;

use App\Modules\Admin\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\Concerns\MakesAdminUsers;
use Tests\TestCase;

/**
 * البوابة قبل الوظيفة.
 *
 * هذه أهم اختبارات الوحدة: عيب في الاعتماد يُنتج رقماً خاطئاً، وعيب في
 * البوابة يُنتج لوحة تحكم مفتوحة لكل مستخدم مسجَّل. ولهذا يُفحص كل مسار
 * لا الأول فقط — الحماية على مجموعة، وسقوط مسار من المجموعة عيب لا
 * يظهر في اختبار يفحص الصفحة الأولى وحدها.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase, MakesAdminUsers;

    public static function staffPages(): array
    {
        return [
            'لوحة القيادة' => ['/admin'],
            'الإعلانات' => ['/admin/listings'],
            'البلاغات' => ['/admin/reports'],
            'الأسئلة' => ['/admin/questions'],
        ];
    }

    #[DataProvider('staffPages')]
    public function test_guest_is_sent_to_login(string $url): void
    {
        $this->get($url)->assertRedirect(route('login'));
    }

    #[DataProvider('staffPages')]
    public function test_member_gets_not_found(string $url): void
    {
        $this->actingAs($this->member())->get($url)->assertNotFound();
    }

    #[DataProvider('staffPages')]
    public function test_moderator_can_open_staff_pages(string $url): void
    {
        $this->seedMarketplace();

        $this->actingAs($this->moderator())->get($url)->assertOk();
    }

    public function test_moderator_cannot_reach_user_management(): void
    {
        $this->actingAs($this->moderator())->get('/admin/users')->assertForbidden();
    }

    public function test_admin_can_reach_user_management(): void
    {
        $this->actingAs($this->admin())->get('/admin/users')->assertOk();
    }

    /**
     * الموقوف لا يدير وإن بقي دوره مديراً.
     *
     * الحالة تحدث فعلاً: مدير يُوقف حسابه ثم يستخدم جلسة مفتوحة سابقاً.
     * LoginController يمنع الدخول، وهذا يمنع البقاء.
     */
    public function test_suspended_admin_is_locked_out(): void
    {
        $suspended = $this->userWithRole(UserRole::Admin, 'suspended');

        $this->actingAs($suspended)->get('/admin')->assertNotFound();
    }

    public function test_member_cannot_act_on_listings(): void
    {
        $this->seedMarketplace();
        $listing = $this->listing(['reviewed_at' => null]);

        $this->actingAs($this->member())
            ->post(route('admin.listings.approve', $listing))
            ->assertNotFound();

        $this->assertNull($listing->fresh()->reviewed_at);
    }
}
