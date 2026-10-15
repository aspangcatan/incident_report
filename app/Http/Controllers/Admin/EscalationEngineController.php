<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EscalationRun;
use App\Support\EscalationOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

/** The daily escalation job (incidents:check-overdue): its rules, what is overdue now, its runs, and "Run now". */
class EscalationEngineController extends Controller
{
    /** The job runs daily, so a last run older than this means the scheduler is not running. */
    private const STALE_AFTER_HOURS = 26;

    public function index(): Response
    {
        $this->authorize('manageEscalationEngine');

        $runs = EscalationRun::with('triggeredBy')->latest('started_at')->latest('id')->limit(10)->get();
        $lastRun = $runs->first();

        return Inertia::render('Admin/EscalationEngine', [
            'rules' => EscalationOverview::rules(),
            'overdue' => EscalationOverview::overdue(),
            'runs' => $runs->map(fn (EscalationRun $run) => [
                'id' => $run->id,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
                'triggered_by' => $run->triggered_by ? ($run->triggeredBy?->name ?? 'Unknown user') : null,
                'counts' => $run->counts ?? [],
                'total' => $run->total(),
                'warning' => $run->warning,
            ]),
            'schedulerStale' => $lastRun === null || $lastRun->started_at->lt(now()->subHours(self::STALE_AFTER_HOURS)),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $this->authorize('manageEscalationEngine');

        Artisan::call('incidents:check-overdue', ['--user' => $request->user()->id]);

        $total = EscalationRun::latest('id')->first()?->total() ?? 0;

        return back()->with('success', $total === 0
            ? 'Escalation check done. Nothing new needed a reminder or escalation.'
            : "Escalation check done. {$total} " . str('item')->plural($total) . ' reminded or escalated.');
    }
}
