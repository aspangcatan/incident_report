<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RCA tools are back (5 Whys, Fishbone, Process Mapping, Timeline, Barrier
 * analysis), usable together in one investigation. See App\Enums\RcaTool for
 * how each tool uses the columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigation_findings', function (Blueprint $table) {
            $table->string('tool')->default('simple')->after('investigation_id');
            $table->string('group_name')->nullable()->after('category');
            $table->dateTime('occurred_at')->nullable()->after('group_name');
            $table->boolean('is_flagged')->default(false)->after('occurred_at');
        });

        // Findings of older 5 Whys investigations belong to the 5 Whys tool.
        DB::table('investigation_findings')
            ->whereIn('investigation_id', DB::table('investigations')->where('methodology', 'five_whys')->select('id'))
            ->update(['tool' => 'five_whys']);
    }

    public function down(): void
    {
        Schema::table('investigation_findings', function (Blueprint $table) {
            $table->dropColumn(['tool', 'group_name', 'occurred_at', 'is_flagged']);
        });
    }
};
