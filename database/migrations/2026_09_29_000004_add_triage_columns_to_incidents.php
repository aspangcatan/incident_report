<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CQI triage: the department's recommended investigator, and the CQI Office's
 * decision that an incident needs no investigation (with its reason).
 * User ids point at tdh_user.users on another connection, so no foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('recommended_investigator_id')->nullable()->after('assigned_investigator_id');
            $table->text('investigation_skipped_reason')->nullable()->after('recommended_investigator_id');
            $table->unsignedBigInteger('investigation_skipped_by')->nullable()->after('investigation_skipped_reason');
            $table->timestamp('investigation_skipped_at')->nullable()->after('investigation_skipped_by');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['recommended_investigator_id', 'investigation_skipped_reason', 'investigation_skipped_by', 'investigation_skipped_at']);
        });
    }
};
