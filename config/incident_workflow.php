<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Department assessment SLA (hours from submission; fixed — severity is
    | only known once the Department Head completes the assessment)
    |--------------------------------------------------------------------------
    */
    'assessment_sla_hours' => 72,

    /*
    |--------------------------------------------------------------------------
    | Review SLA (hours from submission to review, per severity)
    |--------------------------------------------------------------------------
    */
    'review_sla_hours' => [
        'level_1_low' => 120,
        'level_2_moderate' => 72,
        'level_3_high' => 48,
        'level_4_critical' => 24,
        'level_5_sentinel' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Investigation SLA (hours from assignment to target closure, per severity)
    |--------------------------------------------------------------------------
    */
    'investigation_sla_hours' => [
        'level_1_low' => 240,
        'level_2_moderate' => 168,
        'level_3_high' => 120,
        'level_4_critical' => 72,
        'level_5_sentinel' => 72,
    ],

    /*
    |--------------------------------------------------------------------------
    | Closure approval SLA (hours from request to decision, per severity)
    |--------------------------------------------------------------------------
    */
    'approval_sla_hours' => [
        'level_1_low' => 120,
        'level_2_moderate' => 72,
        'level_3_high' => 48,
        'level_4_critical' => 24,
        'level_5_sentinel' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Effectiveness check: days after every CAPA is verified before the
    | Department Head can confirm the actions worked (and request closure)
    |--------------------------------------------------------------------------
    */
    'effectiveness_wait_days' => 0,

    /*
    |--------------------------------------------------------------------------
    | Severity alert recipients per level (the client's matrix, 2026-10-01).
    | Values are App\Support\SeverityAlertSettings::options() keys: a role
    | value, or 'department_head'. The IT Admin's saved list wins.
    |--------------------------------------------------------------------------
    */
    'severity_alert_recipients' => [
        'level_1_low' => [],
        'level_2_moderate' => ['department_head', 'quality_safety_officer'],
        'level_3_high' => ['department_head', 'quality_safety_officer', 'leadership'],
        'level_4_critical' => ['quality_safety_officer', 'leadership', 'management'],
        'level_5_sentinel' => ['quality_safety_officer', 'leadership', 'management'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation recipients (role values — see App\Enums\Role)
    |--------------------------------------------------------------------------
    */
    'escalation_recipient_roles' => ['quality_safety_officer'],
];
