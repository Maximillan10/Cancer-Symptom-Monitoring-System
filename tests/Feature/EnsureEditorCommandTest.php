<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EnsureEditorCommandTest extends TestCase
{
    use RefreshDatabase;

    private function withEnv(?string $password, callable $run): void
    {
        $old = getenv('EDITOR_PASSWORD');
        $password === null ? putenv('EDITOR_PASSWORD') : putenv("EDITOR_PASSWORD={$password}");
        $_ENV['EDITOR_PASSWORD'] = $_SERVER['EDITOR_PASSWORD'] = $password;
        try {
            $run();
        } finally {
            $old === false ? putenv('EDITOR_PASSWORD') : putenv("EDITOR_PASSWORD={$old}");
            unset($_ENV['EDITOR_PASSWORD'], $_SERVER['EDITOR_PASSWORD']);
        }
    }

    public function test_it_does_not_create_the_account_without_a_password(): void
    {
        config(['symptom.editor_emails' => ['editor@example.test']]);

        $this->withEnv(null, function () {
            $this->artisan('app:ensure-editor')->assertSuccessful();
        });

        $this->assertDatabaseMissing('users', ['email' => 'editor@example.test']);
    }

    public function test_it_creates_the_account_with_the_given_password(): void
    {
        config(['symptom.editor_emails' => ['editor@example.test']]);

        $this->withEnv('a-long-private-password', function () {
            $this->artisan('app:ensure-editor')->assertSuccessful();
        });

        $user = User::where('email', 'editor@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('a-long-private-password', $user->password));
        $this->assertFalse(Hash::check('password', $user->password), 'the old default password must not work');
    }
}
