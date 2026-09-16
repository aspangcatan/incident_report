<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('incident_contributing_factor', function (Blueprint $table) {
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('contributing_factor_id')->constrained('contributing_factors')->cascadeOnDelete();
            $table->primary(['incident_id', 'contributing_factor_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('incident_contributing_factor');
    }
};
