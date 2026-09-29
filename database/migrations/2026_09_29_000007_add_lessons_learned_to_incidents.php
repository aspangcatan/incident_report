<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lessons learned: drafted by the Department Head at closure request, published when the incident closes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->text('lessons_learned')->nullable();
            $table->timestamp('lessons_published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['lessons_learned', 'lessons_published_at']);
        });
    }
};
