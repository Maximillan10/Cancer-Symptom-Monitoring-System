<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClinicalData;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Dr Mulenga',
            'email' => 'mulenga@example.test',
            'password' => 'strongpass1',
            'password_confirmation' => 'strongpass1',
        ], $overrides);
    }

    public function test_health_endpoint_is_public(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_registration_creates_a_clinician_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/register', $this->registrationPayload());

        $response->assertCreated()->assertJsonStructure(['user' => ['id', 'email'], 'token']);
        $this->assertDatabaseHas('users', ['email' => 'mulenga@example.test', 'role' => 'clinician']);
    }

    public function test_registration_cannot_create_an_admin(): void
    {
        $this->postJson('/api/register', $this->registrationPayload(['role' => 'admin']))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'mulenga@example.test', 'role' => 'clinician']);
    }

    public function test_registration_does_not_store_the_password_in_plain_text(): void
    {
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();

        $stored = \App\Models\User::where('email', 'mulenga@example.test')->value('password');
        $this->assertNotSame('strongpass1', $stored);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        $this->clinician(['email' => 'mulenga@example.test']);

        $this->postJson('/api/register', $this->registrationPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_a_short_or_unconfirmed_password(): void
    {
        $this->postJson('/api/register', $this->registrationPayload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/register', $this->registrationPayload(['password_confirmation' => 'different1']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_login_with_valid_credentials_returns_a_token(): void
    {
        $user = $this->clinician(['password' => 'password123']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_login_with_a_wrong_password_is_rejected(): void
    {
        $user = $this->clinician();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnauthorized();
    }

    public function test_login_with_an_unknown_email_is_rejected(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'password123'])
            ->assertUnauthorized();
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_returns_the_signed_in_clinician(): void
    {
        $user = $this->clinician(['password' => 'password123']);
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJson(['id' => $user->id, 'email' => $user->email]);
    }

    public function test_a_garbage_token_is_rejected(): void
    {
        $this->withToken('not-a-real-token')->getJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->clinician(['password' => 'password123']);
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])->json('token');

        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Laravel keeps the resolved user in memory between requests inside one test.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_the_password_is_never_returned_by_the_api(): void
    {
        $user = $this->clinician(['password' => 'password123']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertJsonMissingPath('user.password');
    }
}
