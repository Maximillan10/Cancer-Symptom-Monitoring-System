<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Laravel ships a default `create_users_table` migration. Add this migration
// AFTER it to extend the table with the `role` column your proposal specifies
// (clinician / admin), rather than replacing the default users table wholesale.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('clinician')->after('email'); // clinician | admin
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
