<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the IT Admin set to receive the severity alert, one row per level.
 * No row: the default in config/incident_workflow.php. A row with an empty
 * list: nobody is alerted at that level. See App\Support\SeverityAlertSettings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('severity_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->string('severity')->unique();
            $table->json('recipients');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('severity_alert_settings');
    }
};
