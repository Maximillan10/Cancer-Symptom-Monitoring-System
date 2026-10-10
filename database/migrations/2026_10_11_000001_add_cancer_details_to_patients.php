<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Adds the clinical details a clinician records when registering a patient. Each column is only added if it is missing. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            if (! Schema::hasColumn('patients', 'cancer_type')) {
                $table->string('cancer_type', 100)->nullable();
            }
            if (! Schema::hasColumn('patients', 'cancer_stage')) {
                $table->string('cancer_stage', 20)->nullable();
            }
            if (! Schema::hasColumn('patients', 'sex')) {
                $table->string('sex', 10)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            foreach (['cancer_type', 'cancer_stage', 'sex'] as $column) {
                if (Schema::hasColumn('patients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
