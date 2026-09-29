<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Effectiveness check before closure: once every CAPA is verified, the
 * Department Head confirms (after a waiting period) that the actions worked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('effectiveness_due_at')->nullable();
            $table->string('effectiveness_result')->nullable();
            $table->text('effectiveness_notes')->nullable();
            $table->unsignedBigInteger('effectiveness_checked_by')->nullable();
            $table->timestamp('effectiveness_checked_at')->nullable();
            $table->timestamp('effectiveness_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn([
                'effectiveness_due_at', 'effectiveness_result', 'effectiveness_notes',
                'effectiveness_checked_by', 'effectiveness_checked_at', 'effectiveness_notified_at',
            ]);
        });
    }
};
