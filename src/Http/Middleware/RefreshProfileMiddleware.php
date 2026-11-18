<?php

namespace CodeSphere\OAuth\Http\Middleware;

use Closure;
use CodeSphere\OAuth\Services\CodeSphereService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-fetches the session-cached CodeSphere profile once it is older than
 * codesphere.cache_ttl, so changes made in AccountSphere (language, name,
 * groups) reach the app without a new login.
 *
 * When CodeSphere Accounts rejects the user's tokens (access revoked,
 * account deactivated) the user is signed out of the app. A browser is sent
 * back to the login, which silently signs them in again if their central
 * session is still valid.
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
        if (! $request->hasSession() || ! $request->user()) {
            return $next($request);
        }

        if (config('codesphere.refresh_profile', true)) {
            $this->codeSphere->refreshIfStale($request->user());
        }

        if ($this->codeSphere->accessRevoked()) {
            return $this->signOut($request);
        }

        return $next($request);
    }

    private function signOut(Request $request): Response
    {
        $this->codeSphere->clearCache();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => __('Your session has ended. Please sign in again.')], 401);
        }

        return redirect()->guest(Route::has('login') ? route('login') : route('codesphere.redirect'));
    }
}
