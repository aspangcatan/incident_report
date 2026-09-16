<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('review_escalated_at')->nullable()->after('escalated_at');
            $table->timestamp('assignment_escalated_at')->nullable()->after('review_escalated_at');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('escalated_at');
        });
    }

    public function down()
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable();
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['review_escalated_at', 'assignment_escalated_at']);
        });
    }
};
