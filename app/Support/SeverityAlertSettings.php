<?php

namespace App\Support;

use App\Enums\Role;
use App\Enums\Severity;
use Illuminate\Support\Facades\DB;

/**
 * Who receives the severity alert at each level. The IT Admin's saved list
 * (severity_alert_settings) wins; otherwise the default in
 * config/incident_workflow.php. A saved empty list means nobody.
 * EscalationRecipients::forSeverity() turns these keys into people.
 */
final class SeverityAlertSettings
{
    /** Not an IR role: the tdh_user.section.head of the incident's department. */
    public const DEPARTMENT_HEAD = 'department_head';

    /**
     * Every recipient group, in display order: key => [label, who exactly].
     * Department-scoped groups only reach people of the incident's department.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function options(): array
    {
        return [
            self::DEPARTMENT_HEAD => ['Department/Service Head', "The head of the incident's department."],
            Role::Supervisor->value => [Role::Supervisor->label(), "Focal Persons of the incident's department."],
            Role::Staff->value => [Role::Staff->label(), "Staff (no IR role) of the incident's department."],
            Role::Leadership->value => [Role::Leadership->label(), "Leaders mapped to the incident's department (Leadership Coverage)."],
            Role::QualitySafetyOfficer->value => [Role::QualitySafetyOfficer->label(), 'Everyone with this role, hospital-wide.'],
            Role::CqiCommittee->value => [Role::CqiCommittee->label(), 'Everyone with this role, hospital-wide.'],
            Role::Management->value => [Role::Management->label(), 'Everyone with this role, hospital-wide.'],
            Role::Investigator->value => [Role::Investigator->label(), 'Everyone with this role, hospital-wide.'],
            Role::Administrator->value => [Role::Administrator->label(), 'Everyone with this role, hospital-wide.'],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::options());
    }

    /** @return list<string> recipient keys for this level, in display order */
    public static function recipientsFor(Severity $severity): array
    {
        $saved = DB::table('severity_alert_settings')->where('severity', $severity->value)->value('recipients');

        return self::clean($saved !== null ? json_decode($saved, true) : config("incident_workflow.severity_alert_recipients.{$severity->value}"));
    }

    /** @return array<string, list<string>> severity value => recipient keys */
    public static function all(): array
    {
        $saved = DB::table('severity_alert_settings')->pluck('recipients', 'severity');

        return collect(Severity::cases())->mapWithKeys(fn (Severity $level) => [
            $level->value => self::clean(isset($saved[$level->value])
                ? json_decode($saved[$level->value], true)
                : config("incident_workflow.severity_alert_recipients.{$level->value}")),
        ])->all();
    }

    /** @param array<string, list<string>> $matrix severity value => recipient keys */
    public static function save(array $matrix): void
    {
        DB::table('severity_alert_settings')->upsert(
            collect($matrix)->map(fn ($recipients, $severity) => [
                'severity' => $severity,
                'recipients' => json_encode(self::clean($recipients)),
                'created_at' => now(),
                'updated_at' => now(),
            ])->values()->all(),
            ['severity'],
            ['recipients', 'updated_at'],
        );
    }

    /** Known keys only (a stale or mistyped key is dropped), no duplicates, display order. */
    private static function clean(mixed $recipients): array
    {
        return array_values(array_intersect(self::keys(), (array) $recipients));
    }
}
