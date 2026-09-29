<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurrence Prevention: a review the CQI Office opens on a recurring pattern
 * (same incident type, same department). department_id and the user ids point
 * at tdh_user on another connection, so they carry no foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrence_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->foreignId('incident_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('incident_count');
            $table->string('status');
            $table->unsignedBigInteger('assigned_to');
            $table->date('due_date');
            $table->text('cqi_notes');
            $table->text('fix_description')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('decision_comments')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['department_id', 'incident_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrence_reviews');
    }
};
