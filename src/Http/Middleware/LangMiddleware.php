<?php

namespace CodeSphere\OAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;
use function App\Http\Middleware\session;

class LangMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $local = session('locale', config('app.locale'));
        App::setLocale($local);
        return $next($request);
    }
}
