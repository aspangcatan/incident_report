<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IncidentTypes\StoreIncidentTypeRequest;
use App\Http\Resources\IncidentTypeResource;
use App\Models\IncidentType;
use App\Services\IncidentTypeService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** IT Admin settings: the incident type list reporters choose from. */
class IncidentTypeController extends Controller
{
    public function __construct(private readonly IncidentTypeService $service)
    {
    }

    public function index(): Response
    {
        $this->authorize('viewAny', IncidentType::class);

        return Inertia::render('Admin/IncidentTypes', [
            'types' => IncidentTypeResource::collection(
                IncidentType::withCount(IncidentType::USAGE_COUNTS)->orderBy('name')->get()
            ),
            'categories' => collect(IncidentType::CATEGORIES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function store(StoreIncidentTypeRequest $request): RedirectResponse
    {
        $type = $this->service->create($request->toDto());

        return back()->with('success', "Incident type \"{$type->name}\" added.");
    }
}
