<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Login only admits tdh_user status '1', but a session outlives that check.
 * The session guard re-reads the user from tdh_user on every request, so
 * this ends the session as soon as the account is deactivated there.
 */
class EnsureTdhUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Your hospital account is no longer active. Please contact the system administrator.');
        }

        return $next($request);
    }
}
