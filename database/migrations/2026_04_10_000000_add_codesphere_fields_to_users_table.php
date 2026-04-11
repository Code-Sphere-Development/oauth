<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stub migration provided by code-sphere/oauth.
 *
 * Run `php artisan vendor:publish --tag=codesphere-migrations` to copy this
 * into your app's database/migrations directory, then `php artisan migrate`.
 *
 * This migration adds the minimal CodeSphere identity columns to the users
 * table. It intentionally does NOT remove existing columns (name, email,
 * password, etc.) — that is left as an explicit decision for the host app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'codesphere_id')) {
                $table->unsignedBigInteger('codesphere_id')->unique()->after('id');
            }
            if (! Schema::hasColumn('users', 'access_token')) {
                $table->text('access_token')->nullable();
            }
            if (! Schema::hasColumn('users', 'refresh_token')) {
                $table->text('refresh_token')->nullable();
            }
            if (! Schema::hasColumn('users', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['codesphere_id', 'access_token', 'refresh_token', 'token_expires_at']);
        });
    }
};
