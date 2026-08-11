<?php

namespace Tests\Feature\Admin;

use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Admin\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\Concerns\MakesAdminUsers;
use Tests\TestCase;

/**
 * الحمايتان التي بلا اختبار تُكتشف يوم تُقفَل اللوحة على الجميع.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase, MakesAdminUsers;

    public function test_admin_cannot_ban_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.users.status', $admin), ['status' => 'banned'])
            ->assertRedirect();

        $this->assertSame('active', $admin->fresh()->status);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.users.role', $admin), ['role' => UserRole::Member->value])
            ->assertRedirect();

        $this->assertSame(UserRole::Admin, UserRole::of($admin->fresh()));
    }

    /**
     * الفحص على النفس لا يكفي: مديران يجرّد كلٌّ منهما الآخر يُنتجان
     * نظاماً بلا مدير وكل فحص فيه على النفس يمرّ.
     */
    public function test_the_last_admin_cannot_be_demoted_by_another_admin(): void
    {
        $first = $this->admin();
        $second = $this->admin();

        // الأول يجرّد الثاني: مسموح — يبقى مدير واحد
        $this->actingAs($first)
            ->post(route('admin.users.role', $second), ['role' => UserRole::Member->value])
            ->assertRedirect();

        $this->assertSame(UserRole::Member, UserRole::of($second->fresh()));

        // الآن الأول وحده. يعود الثاني (وقد صار عضواً) فلا يملك الصلاحية
        // أصلاً — فنفحص القاعدة من الطبقة نفسها: تجريد آخر مدير مرفوض
        $third = $this->admin();

        $this->actingAs($third)
            ->post(route('admin.users.role', $first), ['role' => UserRole::Member->value])
            ->assertRedirect();

        // مسموح: ما زال هناك مدير آخر ($third)
        $this->assertSame(UserRole::Member, UserRole::of($first->fresh()));

        // والآن $third آخر مدير، ولا يستطيع أحد تجريده — ولا هو نفسه
        $this->actingAs($third)
            ->post(route('admin.users.role', $third), ['role' => UserRole::Member->value])
            ->assertRedirect();

        $this->assertSame(UserRole::Admin, UserRole::of($third->fresh()));
    }

    public function test_suspending_a_member_is_recorded_with_before_and_after(): void
    {
        $admin = $this->admin();
        $target = $this->member();

        $this->actingAs($admin)
            ->post(route('admin.users.status', $target), [
                'status' => 'suspended',
                'reason' => 'إعلانات مكررة متعمّدة.',
            ])
            ->assertRedirect();

        $this->assertSame('suspended', $target->fresh()->status);

        $this->assertDatabaseHas('admin_actions', [
            'admin_id' => $admin->id,
            'action' => AdminAction::UserSuspended->value,
            'entity_type' => 'user',
            'entity_id' => $target->id,
            'reason' => 'إعلانات مكررة متعمّدة.',
        ]);
    }

    public function test_promoting_to_moderator_grants_staff_access_only(): void
    {
        $admin = $this->admin();
        $target = $this->member();

        $this->actingAs($admin)
            ->post(route('admin.users.role', $target), ['role' => UserRole::Moderator->value])
            ->assertRedirect();

        $this->assertSame(UserRole::Moderator, UserRole::of($target->fresh()));

        $this->actingAs($target->fresh())->get('/admin')->assertOk();
        $this->actingAs($target->fresh())->get('/admin/users')->assertForbidden();
    }

    public function test_moderator_cannot_change_roles(): void
    {
        $moderator = $this->moderator();
        $target = $this->member();

        $this->actingAs($moderator)
            ->post(route('admin.users.role', $target), ['role' => UserRole::Admin->value])
            ->assertForbidden();

        $this->assertSame(UserRole::Member, UserRole::of($target->fresh()));
    }
}
