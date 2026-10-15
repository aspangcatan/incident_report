<?php

namespace App\Support;

use App\Enums\IncidentStatus;
use App\Enums\InvestigationStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Approval;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Investigation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only picture of the daily escalation job (incidents:check-overdue) for
 * Admin → Escalation Engine: its rules with the current time limits, and what
 * is past its limit right now. The job itself stays the only thing that sends.
 */
final class EscalationOverview
{
    /** Keys match the counts CheckOverdueIncidents records per run. */
    public static function rules(): array
    {
        $escalationRoles = collect(config('incident_workflow.escalation_recipient_roles'))
            ->map(fn (string $role) => Role::from($role)->label())
            ->join(', ') ?: 'Nobody (no roles configured)';
        $assessmentHours = WorkflowDurations::get('assessment_sla_hours');
        $waitDays = WorkflowDurations::get('effectiveness_wait_days');

        return [
            ['key' => 'assessment', 'name' => 'Department assessment overdue', 'when' => 'A submitted report is not assessed in time after it was reported.', 'limit' => "{$assessmentHours} hours", 'notifies' => $escalationRoles],
            ['key' => 'review', 'name' => 'CQI triage overdue', 'when' => 'An assessed incident is not triaged in time.', 'limit' => self::perLevel('review_sla_hours'), 'notifies' => $escalationRoles],
            ['key' => 'assignment', 'name' => 'Investigation not started', 'when' => 'An investigator is assigned but has not started by the target closure date.', 'limit' => 'Target closure date', 'notifies' => $escalationRoles],
            ['key' => 'investigation_reminder', 'name' => 'Investigation due soon', 'when' => 'An investigation is due within the next 24 hours (reminder).', 'limit' => '24 hours before', 'notifies' => 'Lead investigator'],
            ['key' => 'investigation_overdue', 'name' => 'Investigation overdue', 'when' => 'An investigation in progress is past its target completion.', 'limit' => self::perLevel('investigation_sla_hours'), 'notifies' => 'Lead investigator, Department Head, Patient Safety/CQI Office'],
            ['key' => 'capa_reminder', 'name' => 'Corrective action due soon', 'when' => 'An open corrective action is due tomorrow (reminder).', 'limit' => '1 day before', 'notifies' => 'Action owner'],
            ['key' => 'capa_overdue', 'name' => 'Corrective action overdue', 'when' => 'A corrective action is past its due date and not yet verified.', 'limit' => 'Its due date', 'notifies' => 'Action owner, Department Head, Patient Safety/CQI Office; Hospital Executives too for Critical priority'],
            ['key' => 'approval', 'name' => 'Closure approval overdue', 'when' => 'A closure request is not approved or returned in time.', 'limit' => self::perLevel('approval_sla_hours'), 'notifies' => $escalationRoles],
            ['key' => 'effectiveness', 'name' => 'Effectiveness check due', 'when' => 'The waiting period after every corrective action was verified is over.', 'limit' => $waitDays === 0 ? 'Right away' : "{$waitDays} days", 'notifies' => 'Patient Safety/CQI Committee'],
        ];
    }

    /**
     * Everything past its limit right now, most overdue first, whether or not
     * it has been escalated yet (escalated_at is null until the job runs).
     *
     * @return Collection<int, array{stage: string, reference: string, incident_id: int, department: ?string, due_at: Carbon, escalated_at: ?Carbon}>
     */
    public static function overdue(): Collection
    {
        $now = now();
        $row = fn (string $stage, Incident $incident, Carbon $dueAt, ?Carbon $escalatedAt, ?string $reference = null) => [
            'stage' => $stage,
            'reference' => $reference ?? $incident->incident_number,
            'incident_id' => $incident->id,
            'department' => $incident->department?->name,
            'due_at' => $dueAt,
            'escalated_at' => $escalatedAt,
        ];

        $assessmentHours = WorkflowDurations::get('assessment_sla_hours');
        $assessments = Incident::with('department')
            ->where('status', IncidentStatus::Submitted)
            ->where('reported_at', '<=', $now->copy()->subHours($assessmentHours))
            ->get()
            ->map(fn (Incident $i) => $row('Department assessment', $i, $i->reported_at->copy()->addHours($assessmentHours), $i->assessment_escalated_at));

        $reviews = Incident::with('department')
            ->where('status', IncidentStatus::ForReview)
            ->whereNotNull('assessed_at')
            ->whereNotNull('severity')
            ->get()
            ->map(fn (Incident $i) => $row('CQI triage', $i, $i->assessed_at->copy()->addHours(WorkflowDurations::forLevel('review_sla_hours', $i->severity)), $i->review_escalated_at))
            ->filter(fn (array $r) => $r['due_at']->lt($now));

        $assignments = Incident::with('department')
            ->where('status', IncidentStatus::Assigned)
            ->where('target_closure_date', '<', $now)
            ->get()
            ->map(fn (Incident $i) => $row('Investigation not started', $i, $i->target_closure_date, $i->assignment_escalated_at));

        $investigations = Investigation::with('incident.department')
            ->where('status', InvestigationStatus::InProgress)
            ->where('target_completion_at', '<', $now)
            ->get()
            ->map(fn (Investigation $inv) => $row('Investigation', $inv->incident, $inv->target_completion_at, $inv->escalated_at));

        $correctiveActions = CorrectiveAction::with('incident.department')
            ->overdue()
            ->get()
            ->map(fn (CorrectiveAction $a) => $row('Corrective action', $a->incident, $a->due_date, $a->escalated_at, $a->capa_number));

        $approvals = Approval::with('incident.department')
            ->overdue()
            ->get()
            ->map(fn (Approval $a) => $row("Closure approval ({$a->stage->label()})", $a->incident, $a->due_at, $a->escalated_at));

        return collect()
            ->concat($assessments)->concat($reviews)->concat($assignments)
            ->concat($investigations)->concat($correctiveActions)->concat($approvals)
            ->sortBy(fn (array $r) => $r['due_at']->timestamp)
            ->values();
    }

    /** "I 120h · II 72h · …" for a per-severity duration. */
    private static function perLevel(string $stage): string
    {
        return collect(Severity::cases())
            ->map(fn (Severity $level) => $level->romanNumeral() . ' ' . WorkflowDurations::forLevel($stage, $level) . 'h')
            ->join(' · ');
    }
}
