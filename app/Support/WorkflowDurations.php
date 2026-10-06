<?php

namespace App\Support;

use App\Enums\Severity;
use Illuminate\Support\Facades\DB;

/**
 * Workflow time limits. The IT Admin's saved value (workflow_settings) wins;
 * otherwise the default in config/incident_workflow.php. Keys are config paths,
 * e.g. 'review_sla_hours.level_1_low' or 'effectiveness_wait_days'.
 */
class WorkflowDurations
{
    /** Hours per severity level (the levels themselves are fixed). */
    public const PER_LEVEL = ['review_sla_hours', 'investigation_sla_hours', 'approval_sla_hours'];

    /** Single values that do not depend on severity. */
    public const SINGLE = ['assessment_sla_hours', 'effectiveness_wait_days'];

    public static function get(string $key): int
    {
        $saved = DB::table('workflow_settings')->where('key', $key)->value('value');

        return (int) ($saved ?? config("incident_workflow.{$key}"));
    }

    public static function forLevel(string $stage, Severity $severity): int
    {
        return self::get("{$stage}.{$severity->value}");
    }

    /** Every key with its current value. */
    public static function all(): array
    {
        $saved = DB::table('workflow_settings')->pluck('value', 'key');

        return collect(self::keys())
            ->mapWithKeys(fn (string $key) => [$key => (int) ($saved[$key] ?? config("incident_workflow.{$key}"))])
            ->all();
    }

    /** @param array<string, int> $values keyed by config path */
    public static function save(array $values): void
    {
        DB::table('workflow_settings')->upsert(
            collect($values)->map(fn ($value, $key) => [
                'key' => $key,
                'value' => (int) $value,
                'created_at' => now(),
                'updated_at' => now(),
            ])->values()->all(),
            ['key'],
            ['value', 'updated_at'],
        );
    }

    /** @return list<string> */
    public static function keys(): array
    {
        $perLevel = collect(self::PER_LEVEL)->flatMap(
            fn (string $stage) => collect(Severity::cases())->map(fn (Severity $level) => "{$stage}.{$level->value}")
        );

        return $perLevel->merge(self::SINGLE)->all();
    }
}
