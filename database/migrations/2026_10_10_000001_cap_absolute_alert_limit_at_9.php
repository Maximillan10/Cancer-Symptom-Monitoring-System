<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Absolute limits above 9 are no longer allowed (a limit of 10 would let a critical score of 9 through
 * without an alert). Bring any limit already saved above 9 down to 9.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('alert_thresholds')
            ->whereNull('window_days')
            ->where('threshold_value', '>', 9)
            ->update(['threshold_value' => 9]);
    }

    public function down(): void
    {
        // Nothing to undo: the original values are not kept.
    }
};
