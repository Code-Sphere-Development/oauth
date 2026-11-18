<?php

namespace CodeSphere\OAuth\Http\Controllers;

use CodeSphere\OAuth\Exceptions\MissingAccessTokenException;
use CodeSphere\OAuth\Services\CodeSphereService;
use CodeSphere\OAuth\Support\Locales;
use CodeSphere\OAuth\Support\UrlLocalization;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * POST {prefix}/language: the language switcher of an app. The choice is
 * saved in the user's CodeSphere Accounts profile, so it applies to every
 * CodeSphere app, and the session-cached profile is updated right away.
 */
class LanguageController extends Controller
{
    public function __construct(
        private readonly CodeSphereService $codeSphere,
        private readonly Locales $locales,
        private readonly UrlLocalization $urlLocalization,
    ) {}

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        /** @var array{locale: string} $validated */
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in($this->locales->available())],
        ]);
        $locale = $validated['locale'];

        try {
            $this->codeSphere->updateSettings($request->user(), ['language' => $locale]);
        } catch (MissingAccessTokenException|ConnectionException|RequestException $e) {
            return $this->failed($request, $e);
        }

        if ($request->expectsJson()) {
            return response()->json(['locale' => $locale]);
        }

        $localized = $this->urlLocalization->localizedUrl($locale, url()->previous());

        return $localized !== null ? redirect()->to($localized) : back();
    }

    private function failed(Request $request, MissingAccessTokenException|ConnectionException|RequestException $e): RedirectResponse|JsonResponse
    {
        if ($this->codeSphere->accessRevoked()) {
            // RefreshProfileMiddleware signs the user out on the next request.
            [$status, $message] = [401, __('Your session has ended. Please sign in again.')];
        } elseif ($e instanceof RequestException && $e->response->status() === 403) {
            // A token issued before the `settings` scope existed.
            [$status, $message] = [403, __('Please sign in again to change your language.')];
        } else {
            [$status, $message] = [503, __('The language could not be saved. Please try again later.')];
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return back()->withErrors(['locale' => $message]);
    }
}
