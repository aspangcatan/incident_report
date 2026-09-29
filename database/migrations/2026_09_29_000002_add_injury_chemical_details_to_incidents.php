<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When "Chemicals" is ticked as the agent of injury, the reporter says which chemical. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('injury_chemical_details')->nullable()->after('injury_agents');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('injury_chemical_details');
        });
    }
};
