<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Level IV "Critical / Sentinel" splits into IV Critical and V Sentinel; existing rows become Sentinel (user's decision). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('incidents')->where('severity', 'level_4_critical_sentinel')->update(['severity' => 'level_5_sentinel']);
        DB::table('incident_types')->where('default_severity', 'level_4_critical_sentinel')->update(['default_severity' => 'level_5_sentinel']);
    }

    public function down(): void
    {
        DB::table('incidents')->whereIn('severity', ['level_4_critical', 'level_5_sentinel'])->update(['severity' => 'level_4_critical_sentinel']);
        DB::table('incident_types')->whereIn('default_severity', ['level_4_critical', 'level_5_sentinel'])->update(['default_severity' => 'level_4_critical_sentinel']);
    }
};
