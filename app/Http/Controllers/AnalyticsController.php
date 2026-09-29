<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\AnalyticsService;
use App\Services\TrendService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request, AnalyticsService $analytics): Response
    {
        $this->authorize('viewAnalytics', Incident::class);

        return Inertia::render('Analytics/Index', $analytics->overview($request->user()));
    }

    public function trends(Request $request, TrendService $trends): Response
    {
        $this->authorize('viewAnalytics', Incident::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'department_id' => ['nullable', 'integer'],
            'incident_type_id' => ['nullable', 'integer'],
        ]);

        return Inertia::render('Analytics/Trends', $trends->overview($request->user(), $filters));
    }
}
