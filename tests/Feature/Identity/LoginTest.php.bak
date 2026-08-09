<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginTest extends TestCase
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
    public function it_accepts_a_phone_written_in_the_local_form(): void
    {
        $this->post('/login', ['identifier' => '0771234567', 'password' => 'password1234'])
            ->assertRedirect(route('search'));

        $this->assertAuthenticatedAs($this->user);
    }

    #[Test]
    public function it_accepts_an_email_in_the_same_field(): void
    {
        $this->post('/login', ['identifier' => 'ali@example.test', 'password' => 'password1234']);

        $this->assertAuthenticatedAs($this->user);
    }

    #[Test]
    public function it_records_the_login_time(): void
    {
        $this->post('/login', ['identifier' => '0771234567', 'password' => 'password1234']);

        $this->assertNotNull($this->user->fresh()->last_login_at);
    }

    #[Test]
    public function a_suspended_account_is_told_so_instead_of_being_called_wrong(): void
    {
        $this->user->forceFill(['status' => 'suspended'])->save();

        $response = $this->post('/login', ['identifier' => '0771234567', 'password' => 'password1234']);

        $response->assertSessionHasErrors('identifier');
        $this->assertStringContainsString('موقوف', session('errors')->first('identifier'));
        $this->assertGuest();
    }

    #[Test]
    public function a_banned_account_gets_its_own_message(): void
    {
        $this->user->forceFill(['status' => 'banned'])->save();

        $this->post('/login', ['identifier' => '0771234567', 'password' => 'password1234']);

        $this->assertStringContainsString('محظور', session('errors')->first('identifier'));
    }

    #[Test]
    public function a_wrong_password_is_rejected(): void
    {
        $this->post('/login', ['identifier' => '0771234567', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    #[Test]
    public function logging_out_ends_the_session(): void
    {
        $this->actingAs($this->user)->post('/logout')->assertRedirect(route('search'));

        $this->assertGuest();
    }
}