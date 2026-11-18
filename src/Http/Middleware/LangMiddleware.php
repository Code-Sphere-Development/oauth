<?php

namespace CodeSphere\OAuth\Http\Middleware;

use Closure;
use CodeSphere\OAuth\Services\CodeSphereService;
use CodeSphere\OAuth\Support\Locales;
use CodeSphere\OAuth\Support\UrlLocalization;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the locale for the request. The package alone decides it:
 *
 *   1. signed-in users: the language chosen in CodeSphere Accounts, or
 *      'en' when the app has no translations for it
 *   2. the locale prefix of the URL (mcamara/laravel-localization)
 *   3. the Accept-Language header
 *   4. 'en'
 *
 * Only locales the app has translations for are used (see Locales). With
 * URL prefixes, signed-in users are redirected to the URL of their language;
 * guests keep the prefix of the link they followed.
 */
class LangMiddleware
{
    public function __construct(
        private readonly CodeSphereService $codeSphere,
        private readonly Locales $locales,
        private readonly UrlLocalization $urlLocalization,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $profileLocale = $this->profileLocale($request);
        $urlLocale = $this->urlLocalization->urlLocale($request);

        if ($profileLocale !== null && $urlLocale !== null && $urlLocale !== $profileLocale
            && $this->mayRedirect($request)) {
            $target = $this->urlLocalization->localizedUrl($profileLocale, $request->fullUrl());

            if ($target !== null && $target !== $request->fullUrl()) {
                return redirect()->to($target);
            }
        }

        $locale = $profileLocale ?? $this->guestLocale($request, $urlLocale);

        $this->urlLocalization->setLocale($locale);
        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    public function resolve(Request $request): string
    {
        return $this->profileLocale($request)
            ?? $this->guestLocale($request, $this->urlLocalization->urlLocale($request));
    }

    /**
     * The signed-in user's CodeSphere language; 'en' if the app has no
     * translations for it. Null when the profile has no language.
     */
    private function profileLocale(Request $request): ?string
    {
        if (! $request->user()) {
            return null;
        }

        $language = $this->codeSphere->userField('language');

        if (! is_string($language) || $language === '') {
            return null;
        }

        return $this->locales->isAvailable($language) ? $language : Locales::FALLBACK;
    }

    private function guestLocale(Request $request, ?string $urlLocale): string
    {
        if ($this->locales->isAvailable($urlLocale)) {
            return (string) $urlLocale;
        }

        return $this->locales->fromAcceptLanguage($request) ?? Locales::FALLBACK;
    }

    /**
     * Only page views follow the profile language: a redirect would drop a
     * form submission and confuse API clients.
     */
    private function mayRedirect(Request $request): bool
    {
        return in_array($request->getMethod(), ['GET', 'HEAD'], true) && ! $request->expectsJson();
    }
}
