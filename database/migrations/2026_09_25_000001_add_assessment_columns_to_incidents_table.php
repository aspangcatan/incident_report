<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Department Assessment stage (docs/superpowers/specs/2026-09-24-department-assessment-design.md).
 * assessed_by holds a tdh_user.users id — no FK (different database).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('assessed_by')->nullable()->index()->after('supervisor_comments');
            $table->dateTime('assessed_at')->nullable()->after('assessed_by');
            $table->dateTime('assessment_escalated_at')->nullable()->after('assessed_at');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['assessed_by', 'assessed_at', 'assessment_escalated_at']);
        });
    }
};
