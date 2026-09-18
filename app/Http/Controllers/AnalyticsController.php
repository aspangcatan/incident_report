<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\AnalyticsService;
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
}
