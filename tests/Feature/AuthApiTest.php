<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_for_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'adem@email.com',
            'password' => 'secret12',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password' => 'secret12',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'adem@email.com')
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'adem@email.com',
            'password' => 'secret12',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'adem@email.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_register_creates_account_with_algerian_phone(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sara B',
            'phone' => '+213 555 12 34 56',
            'password' => 'secret12',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.phone', '0555123456')
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
