<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users and departments now live in the shared tdh_user database (read
 * live, never written — see config/tdh.php). MySQL cannot enforce foreign
 * keys across databases, so the constraints pointing at the old local
 * tables are dropped (columns and their indexes are kept) and the local
 * tables are removed.
 *
 * One-way: incident_report held no real data when this ran (2026-09-24).
 * On SQLite (tests only) Laravel 9 cannot drop foreign keys; phpunit.xml
 * disables FK enforcement there instead.
 */
return new class extends Migration
{
    private const FOREIGN_KEYS = [
        'users' => ['department_id'],
        'departments' => ['parent_department_id', 'head_user_id'],
        'incidents' => ['reporter_id', 'department_id', 'assigned_investigator_id', 'supervisor_reviewed_by', 'closed_by'],
        'incident_individuals' => ['department_id'],
        'incident_actions' => ['responsible_user_id'],
        'attachments' => ['uploaded_by'],
        'audit_logs' => ['actor_id'],
        'investigations' => ['lead_investigator_id'],
        'investigation_team_members' => ['user_id'],
        'corrective_actions' => ['responsible_user_id', 'responsible_department_id', 'completed_by', 'verified_by'],
        'approvals' => ['requested_by', 'approver_id'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            foreach (self::FOREIGN_KEYS as $table => $columns) {
                Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                    foreach ($columns as $column) {
                        $blueprint->dropForeign([$column]);
                    }
                });
            }
        }

        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('users');
        Schema::dropIfExists('departments');
    }

    public function down(): void
    {
        throw new RuntimeException('Irreversible: users/departments now come from tdh_user.');
    }
};
