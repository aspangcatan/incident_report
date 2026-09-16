<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', [
            'notifications' => $request->user()->notifications()->latest()->paginate(20),
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        // Scoped to the authenticated user's own notifications, so a foreign or
        // unknown ID silently no-ops rather than 404ing - avoids confirming or
        // denying whether a given notification ID exists for another user.
        $request->user()->notifications()->where('id', $notification)->first()?->markAsRead();

        return back();
    }
}
