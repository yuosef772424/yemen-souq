<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بوابة النشر.
 *
 * الاختبار الأخير هو المهم: البوابة قابلة للتعطيل من config، ومفتاح
 * إعداد بلا اختبار يعمل حتى اليوم الذي تحتاجه فيه.
 */
class PublishGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'علي محمد',
            'phone' => '+967771234567',
            'email' => 'ali@example.test',
            'password' => Hash::make('password1234'),
        ]);
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/add')->assertRedirect(route('login'));
    }

    #[Test]
    public function an_unverified_account_is_sent_to_verify_its_phone(): void
    {
        $this->actingAs($this->user)
            ->get('/add')
            ->assertRedirect(route('phone.verify'));
    }

    #[Test]
    public function a_verified_account_reaches_the_form(): void
    {
        $this->user->markPhoneAsVerified();

        $this->actingAs($this->user)->get('/add')->assertOk();
    }

    #[Test]
    public function the_gate_can_be_switched_off_from_config(): void
    {
        config(['identity.require_phone_verification_to_publish' => false]);

        $this->actingAs($this->user)->get('/add')->assertOk();
    }

    #[Test]
    public function switching_the_gate_off_does_not_open_it_to_guests(): void
    {
        config(['identity.require_phone_verification_to_publish' => false]);

        $this->get('/add')->assertRedirect(route('login'));
    }
}