<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use Inertia\Inertia;
use Inertia\Response;

/** Published lessons from closed incidents, readable by every logged-in user. No names, no link-through. */
class LessonsLearnedController extends Controller
{
    public function index(): Response
    {
        $lessons = Incident::query()
            ->whereNotNull('lessons_published_at')
            ->with(['incidentTypes', 'department'])
            ->latest('lessons_published_at')
            ->paginate(20)
            ->through(fn (Incident $incident) => [
                'id' => $incident->id,
                'types' => [
                    ...$incident->incidentTypes->pluck('name')->all(),
                    ...($incident->incident_type_other ? [$incident->incident_type_other] : []),
                ],
                'department' => $incident->department?->name,
                'severity' => $incident->severity?->value,
                'lesson' => $incident->lessons_learned,
                'published_at' => $incident->lessons_published_at,
            ]);

        return Inertia::render('Lessons/Index', ['lessons' => $lessons]);
    }
}
