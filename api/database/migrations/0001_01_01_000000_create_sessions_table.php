<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Framework table. `users` and `password_reset_tokens` belong to the data model migrations.
 * Grants follow the role matrix: no default privileges, no GRANT on ALL TABLES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // The application uses UUID users. No foreign key on purpose.
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON sessions TO app_runtime');
        DB::statement('GRANT SELECT (user_id), DELETE ON sessions TO app_maintenance');
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
