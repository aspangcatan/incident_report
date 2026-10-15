<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per incidents:check-overdue run, shown on Admin → Escalation Engine. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_runs', function (Blueprint $table) {
            $table->id();
            // tdh_user.users id when someone pressed "Run now"; null for the scheduler.
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('counts')->nullable();
            $table->string('warning')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_runs');
    }
};
