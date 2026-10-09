<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

/** POST /api/register, /api/login, /api/logout and GET /api/me */
class AuthEndpointsTest extends TestCase
{
    use RefreshDatabase, ClinicalFixtures;

    private function registration(array $over = []): array
    {
        return $over + [
            'name' => 'Dr Test', 'email' => 'dr.test@example.com',
            'password' => 'secret1234', 'password_confirmation' => 'secret1234',
        ];
    }

    public function test_register_creates_a_clinician_and_returns_a_token(): void
    {
        $this->postJson('/api/register', $this->registration(['device_name' => 'phone']))
            ->assertCreated()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role'], 'token'])
            ->assertJsonPath('user.role', 'clinician')
            ->assertJsonMissingPath('user.password');

        $user = User::where('email', 'dr.test@example.com')->sole();
        $this->assertNotSame('secret1234', $user->password, 'password must be stored hashed');
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'phone']);
    }

    public function test_nobody_can_register_themselves_as_admin(): void
    {
        $this->postJson('/api/register', $this->registration(['role' => 'admin']))
            ->assertCreated()->assertJsonPath('user.role', 'clinician');
        $this->assertDatabaseMissing('users', ['role' => 'admin']);
    }

    public function test_register_validation(): void
    {
        $this->postJson('/api/register', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
        $this->postJson('/api/register', $this->registration(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/register', $this->registration(['password_confirmation' => 'different123']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/register', $this->registration(['email' => 'not-an-email']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        $this->makeClinician(['email' => 'taken@example.com']);

        $this->postJson('/api/register', $this->registration(['email' => 'taken@example.com']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_returns_user_and_token(): void
    {
        $this->makeClinician(['email' => 'c@example.com', 'password' => 'secret1234']);

        $this->postJson('/api/login', ['email' => 'c@example.com', 'password' => 'secret1234'])
            ->assertOk()->assertJsonStructure(['user' => ['id', 'email', 'role'], 'token'])
            ->assertJsonMissingPath('user.password');
    }

    public function test_login_failures_are_401_and_do_not_reveal_which_part_was_wrong(): void
    {
        $this->makeClinician(['email' => 'c@example.com', 'password' => 'secret1234']);

        $wrongPassword = $this->postJson('/api/login', ['email' => 'c@example.com', 'password' => 'nope']);
        $unknownEmail = $this->postJson('/api/login', ['email' => 'ghost@example.com', 'password' => 'secret1234']);

        $wrongPassword->assertUnauthorized();
        $unknownEmail->assertUnauthorized();
        $this->assertSame($wrongPassword->json('message'), $unknownEmail->json('message'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_validation(): void
    {
        $this->postJson('/api/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        $this->makeClinician(['email' => 'c@example.com', 'password' => 'secret1234']);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', ['email' => 'c@example.com', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => 'c@example.com', 'password' => 'secret1234'])->assertStatus(429);
    }

    public function test_me_returns_the_authenticated_user_only(): void
    {
        $this->makeClinician(['email' => 'me@example.com', 'password' => 'secret1234']);
        $bearer = $this->postJson('/api/login', ['email' => 'me@example.com', 'password' => 'secret1234'])->json('token');

        $this->withToken($bearer)->getJson('/api/me')->assertOk()
            ->assertJsonPath('email', 'me@example.com')->assertJsonMissingPath('password');
    }

    public function test_garbage_token_is_rejected(): void
    {
        $this->withToken('1|not-a-real-token')->getJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token_used(): void
    {
        $this->makeClinician(['email' => 'out@example.com', 'password' => 'secret1234']);
        $bearer = $this->postJson('/api/login', ['email' => 'out@example.com', 'password' => 'secret1234'])->json('token');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($bearer)->postJson('/api/logout')->assertOk()->assertJson(['message' => 'Logged out.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
