<?php

namespace CodeSphere\OAuth\Http\Middleware;

use Closure;
use CodeSphere\OAuth\Services\CodeSphereService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the locale for the request. Resolution order:
 *
 *   1. session('locale')            – an explicit switch inside the app
 *   2. the CodeSphere profile        – the language chosen in AccountSphere
 *   3. the Accept-Language header    – guests
 *   4. config('app.locale')
 *
 * Only locales listed in codesphere.locales are accepted.
 */
class LangMiddleware
{
    public function __construct(private readonly CodeSphereService $codeSphere) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    public function resolve(Request $request): string
    {
        $supported = $this->supportedLocales();

        $candidates = [
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->user() ? $this->codeSphere->userField('language') : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        foreach ($request->getLanguages() as $language) {
            $language = strtolower(substr($language, 0, 2));

            if (in_array($language, $supported, true)) {
                return $language;
            }
        }

        return (string) config('app.locale');
    }

    /**
     * @return list<string>
     */
    private function supportedLocales(): array
    {
        return array_values((array) config('codesphere.locales', ['de', 'en']));
    }
}
