<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->unique()->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('lead_investigator_id')->constrained('users')->restrictOnDelete();
            $table->text('objective');
            $table->string('methodology');
            $table->timestamp('started_at');
            $table->timestamp('target_completion_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('conclusion')->nullable();
            $table->string('status');
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('investigations');
    }
};
