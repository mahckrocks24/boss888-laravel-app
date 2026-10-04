<?php

namespace App\Http\Middleware;

use App\Core\Workspaces\TeamActivity;
use Closure;
use Illuminate\Http\Request;

/** TEAM-ACTIVITY-1: who is acting (for credit attribution) on the way in; what they did, once the response is sent. */
class TeamActivityMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        TeamActivity::bindActor($request);
        TeamActivity::recordEarly($request);
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        TeamActivity::record($request, $response);
    }
}
