<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Http\Middleware;

use Closure;

/**
 * The email log is only reachable once the app has protected it with its own middleware
 * (EMAIL_LOG_ACCESS_MIDDLEWARE for the pages, EMAIL_LOG_ACCESS_MIDDLEWARE_API for the API).
 * Until then it answers 404, so nothing tells visitors that the log exists.
 */
class RequireAccessMiddleware
{
    /**
     * @param  string  $setting  "access_middleware" or "access_middleware_api"
     */
    public function handle($request, Closure $next, $setting)
    {
        if (trim((string) config('email_log.' . $setting)) === '') {
            abort(404);
        }

        return $next($request);
    }
}
