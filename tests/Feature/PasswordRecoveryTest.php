<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_links_are_sent_without_disclosing_account_existence(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
        $message = session('status');
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertSessionHas('status', $message);
    }

    public function test_valid_tokens_reset_password_and_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))->assertOk();
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];
        $this->post('/reset-password', $data)->assertRedirect('/login');
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertRedirect();
        }
        $this->post('/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertStatus(429);
    }
}
