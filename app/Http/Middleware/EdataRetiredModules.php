<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Closes the URLs of modules the company no longer uses. Only the door closes:
 * tables and stored rows stay exactly as they are.
 */
class EdataRetiredModules
{
    public function handle(Request $request, Closure $next)
    {
        $path = trim($request->path(), '/');

        foreach (config('edata.retired_prefixes', []) as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'هذه الوحدة غير مفعّلة.'], 404);
                }
                if (!auth()->check()) {
                    return redirect('login');
                }

                return redirect()->route('dashboard')->with('error', 'هذه الوحدة غير مفعّلة في النظام.');
            }
        }

        return $next($request);
    }
}
