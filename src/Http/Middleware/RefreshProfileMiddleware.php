<?php

namespace CodeSphere\OAuth\Http\Middleware;

use Closure;
use CodeSphere\OAuth\Services\CodeSphereService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-fetches the session-cached CodeSphere profile once it is older than
 * codesphere.cache_ttl, so changes made in AccountSphere (language, name,
 * groups) reach the app without a new login.
 */
class RefreshProfileMiddleware
{
    public function __construct(private readonly CodeSphereService $codeSphere) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('codesphere.refresh_profile', true) && $request->hasSession()) {
            $this->codeSphere->refreshIfStale($request->user());
        }

        return $next($request);
    }
}
