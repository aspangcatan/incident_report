<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Workflow durations the IT Admin changed; keys are config/incident_workflow.php paths. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedInteger('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_settings');
    }
};
