<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Identity\Models\UserDevice;
use App\Modules\Notifications\Services\DeviceRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * دفتر الأجهزة — الطبقة التي ستستدعيها وحدة API عند تسجيل التطبيق.
 */
class DeviceRegistrarTest extends TestCase
{
    use RefreshDatabase;

    private DeviceRegistrar $registrar;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrar = app(DeviceRegistrar::class);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function it_registers_a_device_with_its_token(): void
    {
        $this->registrar->register($this->user, 'uuid-1', 'android', 'token-1');

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $this->user->id,
            'device_uuid' => 'uuid-1',
            'platform' => 'android',
            'push_token' => 'token-1',
        ]);
    }

    /**
     * تدوير الرمز يحدّث الصفّ ولا يضيف صفاً.
     *
     * التعريف بـdevice_uuid لا برمز الدفع هو ما يجعل هذا صحيحاً: الرمز
     * يتغيّر دورياً، ولو كان هو المعرّف لتراكم للمستخدم عشرون صفاً لجهاز
     * واحد — ولوصله الإشعار عشرين مرة.
     */
    #[Test]
    public function rotating_the_token_updates_the_same_device(): void
    {
        $this->registrar->register($this->user, 'uuid-1', 'android', 'token-old');
        $this->registrar->register($this->user, 'uuid-1', 'android', 'token-new');

        $this->assertDatabaseCount('user_devices', 1);
        $this->assertSame(['token-new'], $this->registrar->tokensFor($this->user));
    }

    /**
     * أخطر حالة في الملف: جهاز خرج منه مستخدم ودخل آخر.
     *
     * المزوّد يعطي التطبيق نفس رمز الدفع، فلو بقي مربوطاً بالحساب الأول
     * لوصلت رسائله الخاصة إلى جهاز يحمله شخص آخر.
     */
    #[Test]
    public function a_token_claimed_by_another_account_is_taken_from_it(): void
    {
        $first = $this->user;
        $second = User::factory()->create();

        $this->registrar->register($first, 'uuid-shared', 'android', 'same-token');
        $this->registrar->register($second, 'uuid-shared', 'android', 'same-token');

        $this->assertSame([], $this->registrar->tokensFor($first));
        $this->assertSame(['same-token'], $this->registrar->tokensFor($second));
    }

    /** تسجيل بلا رمز نبضةُ حياة — لا يمسح رمزاً قائماً */
    #[Test]
    public function registering_without_a_token_keeps_the_stored_one(): void
    {
        $this->registrar->register($this->user, 'uuid-1', 'android', 'token-1');
        $this->registrar->register($this->user, 'uuid-1', 'android');

        $this->assertSame(['token-1'], $this->registrar->tokensFor($this->user));
    }

    /** الخروج يقطع التسليم ويُبقي سجلّ الجهاز */
    #[Test]
    public function forgetting_clears_the_token_without_deleting_the_device(): void
    {
        $this->registrar->register($this->user, 'uuid-1', 'android', 'token-1');

        $this->registrar->forget($this->user, 'uuid-1');

        $this->assertSame([], $this->registrar->tokensFor($this->user));
        $this->assertDatabaseCount('user_devices', 1);
    }

    #[Test]
    public function an_unknown_platform_falls_back_to_web(): void
    {
        // العمود enum('web','android','ios') — قيمة خارجه تُرفض من
        // قاعدة البيانات برسالة لا تقول شيئاً للتطبيق الذي أرسلها
        $this->registrar->register($this->user, 'uuid-1', 'symbian', 'token-1');

        $this->assertSame('web', UserDevice::firstOrFail()->platform);
    }

    #[Test]
    public function only_devices_with_a_token_are_pushable(): void
    {
        $this->registrar->register($this->user, 'uuid-1', 'android', 'token-1');
        $this->registrar->register($this->user, 'uuid-2', 'web');

        $this->assertSame(['token-1'], $this->registrar->tokensFor($this->user));
    }
}
