<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One "due soon" reminder per investigation / corrective action (escalated_at already limits escalations to one). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigations', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('escalated_at');
        });
        Schema::table('corrective_actions', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('escalated_at');
        });
    }

    public function down(): void
    {
        Schema::table('investigations', fn (Blueprint $table) => $table->dropColumn('reminder_sent_at'));
        Schema::table('corrective_actions', fn (Blueprint $table) => $table->dropColumn('reminder_sent_at'));
    }
};
