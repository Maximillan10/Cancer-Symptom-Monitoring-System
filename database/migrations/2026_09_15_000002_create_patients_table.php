<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assigned_clinician_id')->constrained('users')->cascadeOnDelete();
            $table->string('mobile_app_user_id')->nullable()->unique(); // links to Part A mobile app
            $table->string('name');
            $table->date('dob');
            $table->string('diagnosis')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
