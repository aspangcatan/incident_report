<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sentinel Event Pathway: who confirmed records, equipment and evidence were preserved, and when. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('evidence_preserved_at')->nullable();
            $table->unsignedBigInteger('evidence_preserved_by')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['evidence_preserved_by']);
            $table->dropColumn(['evidence_preserved_at', 'evidence_preserved_by']);
        });
    }
};
