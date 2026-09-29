<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Trend Analysis page: incidents over time and where they cluster, limited to
 * what the viewer may see (Incident::visibleTo), drafts excluded.
 */
class TrendService
{
    public const MAX_MONTHS = 24;

    private const TOP = 8;

    /**
     * @param  array{from?: ?string, to?: ?string, department_id?: ?int, incident_type_id?: ?int}  $filters
     */
    public function overview(User $user, array $filters): array
    {
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'])->endOfMonth() : CarbonImmutable::now()->endOfMonth();
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'])->startOfMonth() : $to->subMonths(11)->startOfMonth();
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->startOfMonth(), $from->endOfMonth()];
        }
        if ($from->diffInMonths($to) >= self::MAX_MONTHS) {
            $from = $to->subMonths(self::MAX_MONTHS - 1)->startOfMonth();
        }

        // The previous period of the same length, for "change vs before".
        $months = $from->diffInMonths($to) + 1;
        $previousFrom = $from->subMonths($months);
        $previousTo = $from->subSecond();

        $current = $this->incidents($user, $filters, $from, $to);
        $previous = $this->incidents($user, $filters, $previousFrom, $previousTo);

        return [
            'filters' => [
                'from' => $from->format('Y-m'),
                'to' => $to->format('Y-m'),
                'department_id' => $filters['department_id'] ?? null,
                'incident_type_id' => $filters['incident_type_id'] ?? null,
            ],
            'periodLabel' => $from->format('M Y') . ' – ' . $to->format('M Y'),
            'total' => $current->count(),
            'previousTotal' => $previous->count(),
            'monthly' => $this->monthly($current, $from, $to),
            'byType' => $this->compare($this->typeCounts($current), $this->typeCounts($previous), IncidentType::pluck('name', 'id')),
            'byDepartment' => $this->compare(
                $current->whereNotNull('department_id')->countBy('department_id'),
                $previous->whereNotNull('department_id')->countBy('department_id'),
                $this->departmentNames($current->pluck('department_id')->merge($previous->pluck('department_id')))
            ),
            'heatmap' => $this->heatmap($current),
            'resolution' => $this->resolution($user, $filters, $from, $to),
            'options' => [
                'departments' => Department::options(),
                'incidentTypes' => IncidentType::where('is_active', true)->orderBy('id')->get(['id', 'name']),
            ],
        ];
    }

    private function scoped(User $user, array $filters)
    {
        return Incident::query()
            ->where('status', '!=', IncidentStatus::Draft)
            ->visibleTo($user)
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['incident_type_id'] ?? null, fn ($q, $id) => $q->whereHas('incidentTypes', fn ($t) => $t->where('incident_types.id', $id)));
    }

    private function incidents(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->scoped($user, $filters)
            ->whereBetween('reported_at', [$from, $to])
            ->get(['id', 'reported_at', 'severity', 'department_id']);
    }

    private function monthly(Collection $incidents, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byMonth = $incidents->groupBy(fn (Incident $i) => $i->reported_at->format('Y-m'));
        $rows = [];

        for ($month = $from; $month->lessThanOrEqualTo($to); $month = $month->addMonth()) {
            $inMonth = $byMonth->get($month->format('Y-m'), collect());
            $counts = ['unassessed' => $inMonth->whereNull('severity')->count()];
            foreach (Severity::cases() as $severity) {
                $counts[$severity->value] = $inMonth->filter(fn (Incident $i) => $i->severity === $severity)->count();
            }
            $rows[] = ['month' => $month->format('Y-m'), 'label' => $month->format('M Y'), 'short' => $month->format('M'), 'counts' => $counts, 'total' => $inMonth->count()];
        }

        return $rows;
    }

    /** incident_type_id => count; an incident with two types counts toward each. */
    private function typeCounts(Collection $incidents): Collection
    {
        if ($incidents->isEmpty()) {
            return collect();
        }

        return DB::table('incident_incident_type')
            ->whereIn('incident_id', $incidents->pluck('id'))
            ->select('incident_type_id', DB::raw('COUNT(*) as total'))
            ->groupBy('incident_type_id')
            ->pluck('total', 'incident_type_id')
            ->map(fn ($n) => (int) $n);
    }

    private function departmentNames(Collection $ids): Collection
    {
        return Department::whereIn('id', $ids->filter()->unique()->values())->get()->mapWithKeys(fn (Department $d) => [$d->id => $d->name]);
    }

    /** Top entries this period, with the previous period's count beside them. */
    private function compare(Collection $current, Collection $previous, Collection $names): array
    {
        return $current->sortDesc()->take(self::TOP)->map(fn (int $count, $id) => [
            'id' => (int) $id,
            'name' => $names[$id] ?? 'Unknown',
            'count' => $count,
            'previous' => (int) ($previous[$id] ?? 0),
        ])->values()->all();
    }

    private function heatmap(Collection $incidents): array
    {
        $departments = $incidents->whereNotNull('department_id')->countBy('department_id')->sortDesc()->take(10);
        $names = $this->departmentNames($departments->keys());

        return [
            'severities' => collect(Severity::cases())->map(fn (Severity $s) => ['value' => $s->value, 'label' => $s->label()])->all(),
            'rows' => $departments->keys()->map(fn ($id) => [
                'department' => $names[$id] ?? 'Unknown',
                'cells' => collect(Severity::cases())->mapWithKeys(fn (Severity $s) => [
                    $s->value => $incidents->where('department_id', $id)->filter(fn (Incident $i) => $i->severity === $s)->count(),
                ])->all(),
            ])->values()->all(),
        ];
    }

    /** Days from report to closure, for incidents closed in the period. */
    private function resolution(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $closed = $this->scoped($user, $filters)
            ->where('status', IncidentStatus::Closed)
            ->whereBetween('closed_at', [$from, $to])
            ->get(['id', 'reported_at', 'closed_at', 'severity']);

        $days = fn (Collection $set) => $set->isEmpty() ? null
            : round($set->avg(fn (Incident $i) => $i->reported_at->diffInHours($i->closed_at) / 24), 1);

        return [
            'closedCount' => $closed->count(),
            'averageDays' => $days($closed),
            'bySeverity' => collect(Severity::cases())->map(fn (Severity $s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'count' => $closed->filter(fn (Incident $i) => $i->severity === $s)->count(),
                'averageDays' => $days($closed->filter(fn (Incident $i) => $i->severity === $s)),
            ])->all(),
        ];
    }
}
