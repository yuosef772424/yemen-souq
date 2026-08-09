<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Enums\VerificationResult;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Services\PhoneVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private PhoneVerificationService $service;

    private User $user;

    private string $phone = '+967771234567';

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PhoneVerificationService::class);

        $this->user = User::create([
            'name' => 'علي محمد',
            'phone' => $this->phone,
            'email' => 'ali@example.test',
            'password' => Hash::make('password1234'),
        ]);
    }

    #[Test]
    public function the_code_is_never_stored_in_plain_text(): void
    {
        $code = $this->service->issue($this->phone);

        $record = PhoneVerification::first();

        $this->assertNotSame($code, $record->code_hash);
        $this->assertTrue(Hash::check($code, $record->code_hash));
    }

    #[Test]
    public function the_display_driver_returns_the_code_so_the_ui_can_show_it(): void
    {
        $code = $this->service->issue($this->phone);

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
    }

    #[Test]
    public function the_correct_code_verifies(): void
    {
        $code = $this->service->issue($this->phone);

        $this->assertSame(VerificationResult::Verified, $this->service->verify($this->phone, $code));
    }

    #[Test]
    public function a_code_works_only_once(): void
    {
        $code = $this->service->issue($this->phone);

        $this->service->verify($this->phone, $code);

        $this->assertSame(VerificationResult::Expired, $this->service->verify($this->phone, $code));
    }

    #[Test]
    public function a_wrong_code_counts_against_the_attempt_limit(): void
    {
        $this->service->issue($this->phone);

        $this->assertSame(VerificationResult::Invalid, $this->service->verify($this->phone, '000000'));
        $this->assertSame(1, PhoneVerification::first()->attempts);
    }

    #[Test]
    public function too_many_wrong_attempts_lock_the_code(): void
    {
        $code = $this->service->issue($this->phone);

        for ($i = 0; $i < 5; $i++) {
            $this->service->verify($this->phone, '000000');
        }

        // ولو كان الرمز صحيحاً — القفل على الرمز لا على المحاولة
        $this->assertSame(VerificationResult::Locked, $this->service->verify($this->phone, $code));
    }

    #[Test]
    public function an_expired_code_is_rejected(): void
    {
        $this->service->issue($this->phone);

        $this->travel(11)->minutes();

        $this->assertSame(VerificationResult::Expired, $this->service->verify($this->phone, '000000'));
    }

    #[Test]
    public function issuing_a_new_code_invalidates_the_previous_one(): void
    {
        $first = $this->service->issue($this->phone);
        $second = $this->service->issue($this->phone);

        $this->assertSame(VerificationResult::Invalid, $this->service->verify($this->phone, $first));

        // نفس السجل الجديد، فالمحاولة الخاطئة أعلاه استهلكت واحدة
        $this->assertSame(VerificationResult::Verified, $this->service->verify($this->phone, $second));
    }

    #[Test]
    public function the_send_rate_is_capped(): void
    {
        $this->service->issue($this->phone);
        $this->service->issue($this->phone);
        $this->service->issue($this->phone);

        $this->expectException(RuntimeException::class);

        $this->service->issue($this->phone);
    }

    #[Test]
    public function verifying_through_the_route_marks_the_user(): void
    {
        $code = $this->service->issue($this->phone);

        $this->actingAs($this->user)
            ->post('/verify-phone', ['code' => $code])
            ->assertRedirect();

        $this->assertTrue($this->user->fresh()->hasVerifiedPhone());
    }

    #[Test]
    public function an_already_verified_user_is_sent_away_from_the_page(): void
    {
        $this->user->markPhoneAsVerified();

        $this->actingAs($this->user)
            ->get('/verify-phone')
            ->assertRedirect(route('search'));
    }
}