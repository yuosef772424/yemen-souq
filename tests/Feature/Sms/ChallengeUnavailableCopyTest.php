<?php

namespace Tests\Feature\Sms;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * غياب رقم الاستقبال: رسالة عامة للعميل، والتفصيل في السجلّ.
 */
class ChallengeUnavailableCopyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_missing_inbound_number_shows_a_generic_message_and_logs_the_detail(): void
    {
        config(['sms.inbound.number' => null]);

        Log::spy();

        $user = User::factory()->create(['phone' => '+967771234567', 'phone_verified_at' => null]);

        $this->actingAs($user)
            ->get(route('sms.challenge'))
            ->assertOk()
            ->assertSee('هذه الخدمة غير متاحة حاليًا، حاول لاحقًا.')
            ->assertDontSee('رقم الاستقبال')
            ->assertDontSee('راجع الإدارة');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'sms.inbound.number')
                && ($context['user_id'] ?? null) === $user->id);
    }

    #[Test]
    public function a_configured_number_shows_the_normal_page_and_logs_nothing(): void
    {
        config(['sms.inbound.number' => '0770000000']);

        Log::spy();

        $user = User::factory()->create(['phone' => '+967771234567', 'phone_verified_at' => null]);

        $this->actingAs($user)
            ->get(route('sms.challenge'))
            ->assertOk()
            ->assertDontSee('هذه الخدمة غير متاحة حاليًا');

        Log::shouldNotHaveReceived('warning');
    }
}
