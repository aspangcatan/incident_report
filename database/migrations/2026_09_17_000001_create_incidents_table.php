<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_number')->nullable()->unique();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('incident_type_id')->nullable()->constrained('incident_types')->nullOnDelete();
            $table->string('severity')->nullable();
            $table->string('status')->default('draft');
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('reported_at')->nullable();
            $table->string('location')->nullable();
            $table->text('summary')->nullable();
            $table->text('recommendations')->nullable();
            $table->boolean('is_sentinel_event')->default(false);
            $table->boolean('police_notified')->default(false);
            $table->string('police_station')->nullable();
            $table->string('police_officer_in_charge')->nullable();
            $table->string('police_blotter_no')->nullable();
            $table->dateTime('police_notified_at')->nullable();
            $table->foreignId('assigned_investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supervisor_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('supervisor_reviewed_at')->nullable();
            $table->text('supervisor_comments')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->date('target_closure_date')->nullable();
            $table->dateTime('legal_attestation_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('incidents');
    }
};
