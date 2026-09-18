<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('investigation_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('role_in_team');
            $table->timestamps();
            $table->unique(['investigation_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('investigation_team_members');
    }
};
