<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('symptom_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->timestamp('submitted_at');
            // 0-10 severity scale per symptom, matches proposal's symptom_submissions entity
            $table->unsignedTinyInteger('pain')->nullable();
            $table->unsignedTinyInteger('fatigue')->nullable();
            $table->unsignedTinyInteger('nausea')->nullable();
            $table->unsignedTinyInteger('anxiety')->nullable();
            $table->unsignedTinyInteger('appetite')->nullable();
            $table->unsignedTinyInteger('dyspnea')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symptom_submissions');
    }
};
