<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_the_account_and_its_profile_together(): void
    {
        $response = $this->post('/register', $this->payload());

        $response->assertRedirect(route('phone.verify'));

        $user = User::where('email', 'ali@example.test')->first();

        $this->assertNotNull($user);
        $this->assertNotNull($user->profile, 'الحساب بلا ملف شخصي — عطل صامت');
        $this->assertSame('علي محمد', $user->profile->display_name);
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function it_normalizes_the_phone_before_storing_it(): void
    {
        $this->post('/register', $this->payload(['phone' => '0771234567']));

        $this->assertDatabaseHas('users', ['phone' => '+967771234567']);
    }

    #[Test]
    public function the_same_number_written_differently_cannot_register_twice(): void
    {
        $this->post('/register', $this->payload(['phone' => '0771234567']));
        $this->post('/logout');

        $response = $this->post('/register', $this->payload([
            'phone' => '+967771234567',
            'email' => 'other@example.test',
        ]));

        $response->assertSessionHasErrors('phone');
        $this->assertSame(1, User::count());
    }

    #[Test]
    public function it_rejects_a_number_that_is_not_a_yemeni_mobile(): void
    {
        $response = $this->post('/register', $this->payload(['phone' => '012345678']));

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    #[Test]
    public function it_normalizes_the_display_name_for_search(): void
    {
        $this->post('/register', $this->payload(['name' => 'أحمد الحُسَيني']));

        $user = User::first();

        $this->assertSame('احمد الحسيني', $user->profile->name_normalized);
    }

    #[Test]
    public function a_new_account_starts_with_an_unverified_phone(): void
    {
        $this->post('/register', $this->payload());

        $this->assertFalse(User::first()->hasVerifiedPhone());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'علي محمد',
            'phone' => '0771234567',
            'email' => 'ali@example.test',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ], $overrides);
    }
}