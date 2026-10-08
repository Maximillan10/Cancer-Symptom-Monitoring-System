<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinician_id')->constrained('users')->cascadeOnDelete();
            $table->string('symptom_type'); // pain | fatigue | nausea | anxiety | appetite | dyspnea
            $table->unsignedTinyInteger('threshold_value'); // absolute severity trigger point
            $table->unsignedSmallInteger('window_days')->nullable(); // null = absolute-only; set = rate-of-change window
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['clinician_id', 'symptom_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_thresholds');
    }
};
