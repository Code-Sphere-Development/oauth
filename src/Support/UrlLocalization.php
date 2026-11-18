<?php

namespace CodeSphere\OAuth\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * Optional bridge to mcamara/laravel-localization (URL locale prefixes such
 * as /de/…). Every method is a no-op when the app does not use it, so the
 * package does not depend on it.
 */
class UrlLocalization
{
    public function __construct(private readonly Container $container) {}

    public function active(): bool
    {
        return $this->container->bound('laravellocalization');
    }

    /**
     * The locale keys mcamara is configured for.
     *
     * @return list<string>
     */
    public function supportedLocales(): array
    {
        if (! $this->active()) {
            return [];
        }

        $keys = $this->localization()->getSupportedLanguagesKeys();

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * The locale prefix of the request URL, e.g. 'de' for /de/invoices.
     */
    public function urlLocale(Request $request): ?string
    {
        $segment = $request->segment(1);

        return is_string($segment) && in_array($segment, $this->supportedLocales(), true) ? $segment : null;
    }

    /**
     * The given URL with its locale prefix switched to $locale, or null when
     * mcamara is not used or does not know the locale.
     */
    public function localizedUrl(string $locale, string $url): ?string
    {
        if (! in_array($locale, $this->supportedLocales(), true)) {
            return null;
        }

        $localized = $this->localization()->getLocalizedURL($locale, $url);

        return is_string($localized) && $localized !== '' ? $localized : null;
    }

    /**
     * Keep mcamara's current locale in line with the one the package chose.
     * Locales mcamara does not know are skipped: it would fall back to its
     * default locale and overwrite the app locale.
     */
    public function setLocale(string $locale): void
    {
        if (in_array($locale, $this->supportedLocales(), true)) {
            $this->localization()->setLocale($locale);
        }
    }

    private function localization(): mixed
    {
        return $this->container->make('laravellocalization');
    }
}
