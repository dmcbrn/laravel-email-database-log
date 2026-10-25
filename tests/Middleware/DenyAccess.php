<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests\Middleware;

use Closure;

/**
 * An access middleware that turns every request away, to check that the configured
 * middleware is really applied.
 */
class DenyAccess
{
    public function handle($request, Closure $next)
    {
        abort(403);
    }
}
