<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An incident can now have several incident types plus a free-text "Others",
 * and an accident part: was anyone injured, cause(s) and agent(s) of injury.
 * incidents.incident_type_id is kept (copied into the new pivot) but no longer written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_incident_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_type_id')->constrained()->cascadeOnDelete();
            $table->unique(['incident_id', 'incident_type_id']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->string('incident_type_other')->nullable()->after('incident_type_id');
            $table->boolean('has_injury')->nullable()->after('location');
            $table->json('injury_causes')->nullable()->after('has_injury');
            $table->string('injury_cause_other')->nullable()->after('injury_causes');
            $table->json('injury_agents')->nullable()->after('injury_cause_other');
            $table->string('injury_agent_other')->nullable()->after('injury_agents');
        });

        DB::table('incidents')->whereNotNull('incident_type_id')->orderBy('id')->each(function ($incident) {
            DB::table('incident_incident_type')->insert([
                'incident_id' => $incident->id,
                'incident_type_id' => $incident->incident_type_id,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['incident_type_other', 'has_injury', 'injury_causes', 'injury_cause_other', 'injury_agents', 'injury_agent_other']);
        });

        Schema::dropIfExists('incident_incident_type');
    }
};
