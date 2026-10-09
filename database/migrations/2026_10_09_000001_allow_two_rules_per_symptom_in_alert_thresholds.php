<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original table allowed one row per clinician and symptom. The alert engine needs two:
 * an absolute rule (window_days null) and a rate-of-change rule (window_days set).
 * The table's columns do not change, so the schema still matches the approved proposal;
 * only the unique constraint is replaced by a normal index. The settings page keeps
 * the rows unique per clinician, symptom and rule type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_thresholds', function (Blueprint $table) {
            $table->dropUnique(['clinician_id', 'symptom_type']);
            $table->index(['clinician_id', 'symptom_type']);
        });
    }

    public function down(): void
    {
        Schema::table('alert_thresholds', function (Blueprint $table) {
            $table->dropIndex(['clinician_id', 'symptom_type']);
            $table->unique(['clinician_id', 'symptom_type']);
        });
    }
};
