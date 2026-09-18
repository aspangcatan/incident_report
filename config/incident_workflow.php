<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Review SLA (hours from submission to review, per severity)
    |--------------------------------------------------------------------------
    */
    'review_sla_hours' => [
        'level_1_low' => 120,
        'level_2_moderate' => 72,
        'level_3_high' => 48,
        'level_4_critical_sentinel' => 24,
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
        'level_4_critical_sentinel' => 72,
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
        'level_4_critical_sentinel' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation recipients (role values — see App\Enums\Role)
    |--------------------------------------------------------------------------
    */
    'escalation_recipient_roles' => ['quality_safety_officer', 'administrator'],
];
