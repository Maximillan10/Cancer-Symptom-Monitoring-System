<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Makes sure the editor account (the first address in EDITOR_EMAILS) exists.
 * Safe to run on every start: it creates the account only when it is missing, and it only
 * changes an existing password when EDITOR_PASSWORD is set.
 */
class EnsureEditor extends Command
{
    protected $signature = 'app:ensure-editor';

    protected $description = 'Create the editor account from EDITOR_EMAILS if it does not exist';

    public function handle(): int
    {
        $email = (string) (config('symptom.editor_emails')[0] ?? '');

        if ($email === '') {
            $this->info('EDITOR_EMAILS is empty, nothing to do.');

            return self::SUCCESS;
        }

        $password = env('EDITOR_PASSWORD');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = new User();
            $user->forceFill([
                'name' => env('EDITOR_NAME', 'Dr Mwansa'),
                'email' => $email,
                'password' => Hash::make($password ?: 'password'),
                'role' => 'clinician',
            ])->save();
            $this->info("Created editor account {$email}.");

            return self::SUCCESS;
        }

        if ($password) {
            $user->forceFill(['password' => Hash::make($password)])->save();
            $this->info("Updated the password for {$email}.");

            return self::SUCCESS;
        }

        $this->info("Editor account {$email} already exists.");

        return self::SUCCESS;
    }
}
