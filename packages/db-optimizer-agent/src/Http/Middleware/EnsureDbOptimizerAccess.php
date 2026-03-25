<?php

namespace Mdj\DbOptimizer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDbOptimizerAccess
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $debugEnabled = method_exists(app(), 'hasDebugModeEnabled')
            ? app()->hasDebugModeEnabled()
            : (bool) config('app.debug', false);

        abort_unless(app()->environment('local') || $debugEnabled, 403);

        return $next($request);
    }
}
