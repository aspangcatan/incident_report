<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence can now be added after reporting: during Department Assessment,
 * the investigation, and as proof when a CAPA is completed. Every file stays
 * on the incident, tagged with the stage it was added in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('stage')->default('report')->after('category');
            $table->unsignedBigInteger('corrective_action_id')->nullable()->after('stage');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn(['stage', 'corrective_action_id']);
        });
    }
};
