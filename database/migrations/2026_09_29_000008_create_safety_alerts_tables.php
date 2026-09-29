<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safety alerts issued by the CQI Office to all staff or chosen departments,
 * and who has acknowledged them. user_id / department_id / created_by point at
 * tdh_user on another connection, so they carry no foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->string('urgency');
            $table->string('audience'); // all | departments
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('safety_alert_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safety_alert_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('department_id');
            $table->unique(['safety_alert_id', 'department_id']);
        });

        Schema::create('safety_alert_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safety_alert_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('acknowledged_at');
            $table->unique(['safety_alert_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_alert_acknowledgements');
        Schema::dropIfExists('safety_alert_departments');
        Schema::dropIfExists('safety_alerts');
    }
};
