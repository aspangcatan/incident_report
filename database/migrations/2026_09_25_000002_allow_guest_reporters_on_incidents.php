<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public guest reports (docs/superpowers/specs/2026-09-25-guest-reporting-design.md):
 * guests have no account, so reporter_id may be null and their contact
 * details live on the incident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('reporter_id')->nullable()->change();
            $table->string('guest_name')->nullable()->after('reporter_id');
            $table->string('guest_contact')->nullable()->after('guest_name');
            $table->string('guest_relationship', 20)->nullable()->after('guest_contact');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['guest_name', 'guest_contact', 'guest_relationship']);
        });
    }
};
