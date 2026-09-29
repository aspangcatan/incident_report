<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which departments each Medical/Nursing/Ancillary Leadership user oversees.
 * user_id and department_id point at tdh_user (users.id, section.id) on another
 * connection, so there are no foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadership_departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('department_id');
            $table->timestamps();
            $table->unique(['user_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadership_departments');
    }
};
