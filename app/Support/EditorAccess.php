<?php

namespace App\Support;

use App\Models\User;

/** Decides who may make changes and who may see what. See config/symptom.php. */
final class EditorAccess
{
    /** @return list<string> */
    public static function emails(): array
    {
        return array_map('strtolower', (array) config('symptom.editor_emails', []));
    }

    /** True when only the listed editors may make changes. */
    public static function isRestricted(): bool
    {
        return self::emails() !== [];
    }

    /**
     * True when every signed-in clinician sees the same dashboard (all patients and all alerts).
     * This is switched on by listing editors in EDITOR_EMAILS: one editor keeps the data up to date
     * and everyone else reads the same dashboard. With no editors listed, each clinician only sees
     * their own patients (the original behaviour).
     */
    public static function sharedView(): bool
    {
        return self::isRestricted();
    }

    public static function can(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return ! self::isRestricted() || in_array(strtolower($user->email), self::emails(), true);
    }
}
