<?php

namespace App\Http\Middleware;

use App\Support\Edata\Notifier;
use Closure;
use Illuminate\Http\Request;

/**
 * Runs the date-driven alerts after the response is sent, at most hourly per
 * company. A failure here must never affect the page the user asked for.
 */
class EdataHeartbeat
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        $user = $request->user();
        if (!$user || in_array($user->type, ['super admin', 'client'], true)) {
            return;
        }

        try {
            Notifier::heartbeat((int) $user->creatorId());
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
