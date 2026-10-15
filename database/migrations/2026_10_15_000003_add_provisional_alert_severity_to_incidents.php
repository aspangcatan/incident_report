<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The system-suggested level an early (on-submit) severity alert was sent for, so the confirmed alert isn't repeated. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('provisional_alert_severity')->nullable()->after('severity');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('provisional_alert_severity');
        });
    }
};
